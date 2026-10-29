<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\ApiClientRepository;
use PDO;

/**
 * ImanEventStreamController — canal push Zigbee → servidor → ESP-imán (F97).
 *
 * GET /dashboard-api/door-events?external_id=<chipId>&since_id=<n>
 *   Header: X-API-Key con la credencial individual del ESP.
 *
 * El ESP está autenticado como dispositivo (`api_clients.device_id`) y solo
 * puede escuchar la puerta de su propio pack. No se consulta Tuya ni se hace
 * `/get` al sensor: los hechos salen de `presence_events` ya procesados.
 *
 * Eventos:
 *   snapshot → {protocol, door_state, last_event_id, contiguous, sensor_external_id}
 *   door     → {protocol, event_id, state, occurred_at, received_at}
 *   ping     → keepalive
 *   close    → max_lifetime (el ESP reconecta con since_id)
 *
 * `contiguous=true` solo cuando el cliente demuestra que no se ha perdido
 * ningún evento aplicado desde `since_id`. Si es false, el firmware no puede
 * rearmar el imán con el estado cacheado: exige una transición nueva.
 */
final class ImanEventStreamController
{
    private const SLEEP_US = 200_000;
    private const PING_S = 5;
    private const MAX_LIFETIME_S = 60;
    private const MAX_BATCH = 100;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function stream(): void
    {
        $client = $this->authenticate();
        if ($client === null) {
            $this->deny(401, 'unauthorized', 'Valid X-API-Key required');
        }

        $externalId = trim((string) ($_GET['external_id'] ?? ''));
        if ($externalId === '') {
            $this->deny(400, 'external_id_required', 'external_id is required');
        }
        if ($client->deviceId === null || $client->deviceId <= 0) {
            $this->deny(403, 'device_required', 'The credential is not bound to a device');
        }
        if (!$client->hasScope('qr:validate')) {
            $this->deny(403, 'auth_insufficient_scope', 'qr:validate scope is required');
        }
        if (!$client->ipAllowed((string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
            $this->deny(403, 'ip_not_allowed', 'Client IP is not allowed');
        }

        $device = $this->fetchDevice((int) $client->deviceId);
        if ($device === null || !hash_equals((string) $device['external_id'], $externalId)) {
            $this->deny(403, 'device_mismatch', 'external_id does not match the credential');
        }
        if ((string) $device['kind'] !== 'RPI'
            || strtoupper((string) ($device['subtype'] ?? '')) !== 'IMAN'
        ) {
            $this->deny(403, 'variant_mismatch', 'This endpoint is only for ESP-imán');
        }

        $packId = $device['pack_id'] === null ? null : (int) $device['pack_id'];
        if ($packId === null) {
            $this->deny(409, 'device_without_pack', 'ESP-imán is not assigned to a pack');
        }

        $roomId = $this->resolveRoomId($packId);
        if ($roomId === null) {
            $this->deny(409, 'room_not_found', 'The pack is not assigned to a room');
        }

        $sensor = $this->fetchDoorSensor($packId);
        if ($sensor === null) {
            $this->deny(409, 'door_sensor_unavailable', 'The pack has no Zigbee door sensor');
        }

        $sinceId = max(0, (int) ($_GET['since_id'] ?? 0));
        $lastId = $this->lastDoorEventId($roomId);
        $doorState = $this->doorState($roomId);
        $contiguous = $this->isContiguous($roomId, $sinceId, $lastId);

        $this->openStream();
        $this->send('snapshot', [
            'protocol' => 1,
            'door_state' => $doorState,
            'last_event_id' => $lastId,
            'contiguous' => $contiguous,
            'since_id' => $sinceId,
            'sensor_external_id' => (string) $sensor['external_id'],
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);

        // Nunca reenviar eventos anteriores: el estado actual viaja en el
        // snapshot y el firmware decide si puede confiar en él (`contiguous`).
        $lastSent = $lastId;
        $started = time();
        $lastPing = time();

        while (true) {
            if (connection_aborted()) {
                break;
            }
            if ((time() - $started) >= self::MAX_LIFETIME_S) {
                $this->send('close', ['reason' => 'max_lifetime', 'last_event_id' => $lastSent]);
                break;
            }

            try {
                foreach ($this->doorEventsAfter($roomId, $lastSent) as $event) {
                    $lastSent = (int) $event['id'];
                    $this->send('door', [
                        'protocol' => 1,
                        'event_id' => $lastSent,
                        'state' => (string) $event['value'],
                        'occurred_at' => (string) $event['occurred_at'],
                        'received_at' => (string) $event['received_at'],
                    ]);
                }
            } catch (\Throwable $e) {
                // Un error transitorio de BD no debe romper el canal; el ESP
                // reconecta con since_id y `contiguous` decide la confianza.
            }

            if ((time() - $lastPing) >= self::PING_S) {
                $lastPing = time();
                $this->send('ping', ['ts' => gmdate('Y-m-d\TH:i:s\Z')]);
            }

            usleep(self::SLEEP_US);
        }
        exit(0);
    }

    private function authenticate(): ?\App\Domain\Auth\ApiClient
    {
        $key = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
        if ($key === '') {
            return null;
        }
        $repo = new ApiClientRepository($this->pdo);
        return $repo->findActiveByKeyHash(hash('sha256', $key));
    }

    /** @return array<string,mixed>|null */
    private function fetchDevice(int $deviceId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, external_id, kind, subtype, pack_id FROM devices WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $deviceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function resolveRoomId(int $packId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM rooms WHERE pack_id = :pack LIMIT 1');
        $stmt->execute([':pack' => $packId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    /** @return array<string,mixed>|null */
    private function fetchDoorSensor(int $packId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, external_id, subtype, transport FROM devices
              WHERE pack_id = :pack AND kind = 'PROXIMITY'
                AND transport = 'ZIGBEE2MQTT'
              ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute([':pack' => $packId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function lastDoorEventId(int $roomId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT MAX(id) FROM presence_events
              WHERE room_id = :room AND sensor = 'PROXIMITY' AND applied = 1"
        );
        $stmt->execute([':room' => $roomId]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? 0 : (int) $value;
    }

    private function doorState(int $roomId): string
    {
        $stmt = $this->pdo->prepare('SELECT door_state FROM iot_sessions WHERE room_id = :room LIMIT 1');
        $stmt->execute([':room' => $roomId]);
        $value = $stmt->fetchColumn();
        $state = $value === false ? 'UNKNOWN' : strtoupper((string) $value);
        return in_array($state, ['OPEN', 'CLOSED'], true) ? $state : 'UNKNOWN';
    }

    private function isContiguous(int $roomId, int $sinceId, int $lastId): bool
    {
        if ($sinceId <= 0 || $sinceId > $lastId) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM presence_events
              WHERE room_id = :room AND sensor = 'PROXIMITY' AND applied = 1
                AND id > :since AND id <= :last"
        );
        $stmt->execute([':room' => $roomId, ':since' => $sinceId, ':last' => $lastId]);
        return (int) $stmt->fetchColumn() === 0;
    }

    /** @return list<array<string,mixed>> */
    private function doorEventsAfter(int $roomId, int $lastSent): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, value, occurred_at, received_at FROM presence_events
              WHERE room_id = :room AND sensor = 'PROXIMITY' AND applied = 1
                AND id > :last
              ORDER BY id ASC LIMIT " . self::MAX_BATCH
        );
        $stmt->execute([':room' => $roomId, ':last' => $lastSent]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function openStream(): void
    {
        @set_time_limit(0);
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        echo "retry: 3000\n\n";
        @flush();
    }

    /** @param array<string,mixed> $payload */
    private function send(string $event, array $payload): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        @flush();
    }

    private function deny(int $status, string $error, string $message): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $error, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit(0);
    }
}
