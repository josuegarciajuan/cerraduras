<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepositoryInterface;
use App\Domain\Anomalies\AnomalyRepositoryInterface;
use App\Domain\Locks\AccessEvent;
use App\Domain\Presence\ExitRuleEvaluator;
use App\Domain\Presence\IotSessionRepositoryInterface;
use App\Domain\Presence\PresenceEventRepositoryInterface;
use App\Domain\Qr\QrWindows;
use App\Domain\Rooms\RoomRepositoryInterface;
use App\Domain\Rooms\RoomTypeRepositoryInterface;
use App\Domain\Stays\StayRepositoryInterface;
use App\Http\Request;
use App\Http\Response;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Errors\NotFoundException;
use App\Support\Qr\QrTokenizer;

/**
 * RoomLiveController: public read-only endpoint for the live dashboard.
 *
 * GET /api/v1/rooms/{id}/live  (no auth — LAN/MVP)
 *
 * Aggregates: room status, IoT session state, active stay, recent
 * access events — everything the dashboard needs in one JSON response.
 *
 * See contracts.md §9.2, TSK-230, F21.
 */
final class RoomLiveController
{
    private RoomRepositoryInterface         $rooms;
    private IotSessionRepositoryInterface   $iotSessions;
    private PresenceEventRepositoryInterface $presenceEvents;
    private StayRepositoryInterface         $stays;
    private ?DeviceRepositoryInterface      $devices;
    private ?RoomTypeRepositoryInterface    $roomTypes;
    private ?AnomalyRepositoryInterface     $anomalyRepo;
    /** @var \PDO */
    private $pdo;

    public function __construct(
        RoomRepositoryInterface         $rooms,
        IotSessionRepositoryInterface   $iotSessions,
        PresenceEventRepositoryInterface $presenceEvents,
        StayRepositoryInterface         $stays,
        \PDO                            $pdo,
        ?DeviceRepositoryInterface      $devices = null,
        ?RoomTypeRepositoryInterface    $roomTypes = null,
        ?AnomalyRepositoryInterface     $anomalyRepo = null
    ) {
        $this->rooms          = $rooms;
        $this->iotSessions    = $iotSessions;
        $this->presenceEvents = $presenceEvents;
        $this->stays          = $stays;
        $this->pdo            = $pdo;
        $this->devices        = $devices;
        $this->roomTypes      = $roomTypes;
        $this->anomalyRepo    = $anomalyRepo;
    }

    /**
     * GET /api/v1/rooms/{id}/live
     */
    public function show(Request $request): Response
    {
        $roomId = (int) $request->routeParam('id');

        $room = $this->rooms->findById($roomId);
        if ($room === null) {
            throw new NotFoundException('Room not found', ['room_id' => $roomId]);
        }

        // IoT session
        $session = $this->iotSessions->findByRoomId($roomId);
        $iotData = $session !== null
            ? $session->toArray()
            : ['door_state' => 'UNKNOWN', 'presence_state' => 'UNKNOWN',
               'last_open_at' => null, 'last_close_at' => null, 'last_absent_since' => null,
               'last_door_event_at' => null, 'last_presence_event_at' => null,
               'last_door_value' => null, 'last_presence_value' => null];

        // Active stay
        $activeStay = $this->stays->findActiveForRoom($roomId);
        $stayData = null;
        if ($activeStay !== null) {
            $stayData = [
                'id'              => $activeStay->id,
                'status'          => $activeStay->status,
                'duracion_minutos' => $activeStay->duracionMinutos,
                'first_entry_at'   => $activeStay->firstEntryAt,
                // F41: authoritative "guest inside" mark for the choreography.
                'entry_confirmed_at' => $activeStay->entryConfirmedAt,
                'exited_at'        => $activeStay->exitDetectedAt,
            ];
        }

        // QR status (scannable, consumed, revoked, expired) — inline, no async fetch needed
        $qrStatus = $this->fetchQrStatus($roomId);

        // Recent access events (last 20, newest first)
        $recentEvents = $this->fetchRecentAccessEvents($roomId, 20);

        // Recent presence events (last 10, SOLO aplicados — F48)
        // Los descartados (duplicate/stale/noop/no_context) no deben alimentar la
        // coreografía del panel (p. ej. `freshPresenceAfterClose` cancelaba salidas).
        $recentPresence = array_map(static function ($e): array {
            return [
                'sensor'      => $e->sensor,
                'value'       => $e->value,
                'provider'    => $e->provider,
                'occurred_at' => $e->occurredAt,
            ];
        }, $this->presenceEvents->listForRoom($roomId, 10, true));

        // Smart Switch (EAWCBT-J) — RF-16: show if room has a SWITCH device
        $switchState = null;
        if ($this->devices !== null) {
            $switchDevice = $this->devices->findForRoomKind($roomId, Device::KIND_SWITCH);
            if ($switchDevice !== null) {
                $meta = $switchDevice->meta ?? [];
                $switchState = [
                    'id'           => $switchDevice->id,
                    'external_id'  => $switchDevice->externalId,
                    'last_command' => $meta['last_command'] ?? 'UNKNOWN',
                    'commanded_at' => $meta['commanded_at'] ?? null,
                    // F50: estado REAL del relé reportado por push de Tuya
                    // (aditivo). `UNKNOWN` mientras no haya push.
                    'state'        => $meta['switch_state'] ?? 'UNKNOWN',
                    'state_at'     => $meta['switch_state_at'] ?? null,
                    // Observabilidad del último fallo Tuya (aditivo, nullable).
                    'last_error'          => $meta['last_error'] ?? null,
                    'last_error_at'       => $meta['last_error_at'] ?? null,
                    'last_error_category' => $meta['last_error_category'] ?? null,
                ];
            }
        }

        // F36: Battery status for PROXIMITY sensor (MC400D door sensor)
        $battery = null;
        if ($this->devices !== null) {
            $proxDevice = $this->devices->findForRoomKind($roomId, Device::KIND_PROXIMITY);
            if ($proxDevice !== null) {
                $pct = $proxDevice->batteryPct;
                $state = 'unknown';
                if ($pct !== null) {
                    if ($pct <= 10) {
                        $state = 'critical';
                    } elseif ($pct <= 20) {
                        $state = 'low';
                    } else {
                        $state = 'normal';
                    }
                }
                $battery = [
                    'device_id' => $proxDevice->id,
                    'kind'      => $proxDevice->kind,
                    'label'     => $proxDevice->label,
                    'pct'       => $pct,
                    'state'     => $state,
                ];
            }
        }

        // Exit deadline (F41/F42, contracts.md §3.3): emitted only with
        // presence=ABSENT + a credited door cycle + door=CLOSED + entry confirmed.
        // F42 (RF-46.4): sin `entry_confirmed_at` no hay salida posible; evita que
        // la ventana de ENTRADA no consolidada muestre un conteo de salida. The
        // internal exit rule no longer requires the current door state; the UI
        // field does, so a stuck door does not freeze the countdown on screen.
        $exitDeadline = null;
        $gapSeconds = null; // F31: exposed for dashboard verification-hold computation

        // RF-30: room-level override takes precedence over room_type config
        $rt = $this->roomTypes !== null
            ? $this->roomTypes->findById($room->roomTypeId)
            : null;

        $gapSeconds = $room->presenceCheckSeconds;
        if ($gapSeconds === null) {
            $gapSeconds = $rt !== null ? $rt->exitPresenceGapSeconds : 15;
        }

        // Bug 1 (RF-47.2.4): la guarda de SALIDA se desacopla de `gap_seconds`.
        // Resolución: override de sala (presence_check_seconds) > global
        // EXIT_ABSENCE_GUARD_SECONDS > 3 s. `gap_seconds` sigue siendo legacy.
        $exitGuardSeconds = ExitRuleEvaluator::resolveGuardSeconds(
            $room->presenceCheckSeconds,
            Config::getInt('EXIT_ABSENCE_GUARD_SECONDS', null)
        );

        // F44 (RF-51.2/51.4/51.6): ventanas configurables del poller de presencia.
        // `entry_window_seconds`: muestreo tras la apertura hasta detectar presencia.
        // `exit_check_seconds`: muestreo post-cierre para decidir la salida real.
        // Fallback retrocompatible: entry 90 s; exit = gap + 10 s.
        $entryWindowSeconds = $rt !== null ? $rt->presenceEntryWindowSeconds : 90;
        $exitCheckSeconds   = $rt !== null ? $rt->exitCheckSeconds : ($gapSeconds + 10);

        if ($session !== null
            && $session->presenceState === \App\Domain\Presence\IotSession::PRESENCE_ABSENT
            && $session->doorState === \App\Domain\Presence\IotSession::DOOR_CLOSED
            && $session->lastAbsentSince !== null
            && $session->lastOpenAt !== null
            && $session->lastCloseAt !== null
            && $stayData !== null
            && !empty($stayData['entry_confirmed_at'])
        ) {
            $openTs   = strtotime($session->lastOpenAt . ' UTC');
            $closeTs  = strtotime($session->lastCloseAt . ' UTC');
            $absentTs = strtotime($session->lastAbsentSince . ' UTC');

            // Credited cycle: opened then closed (close >= open).
            if ($openTs !== false && $closeTs !== false && $absentTs !== false
                && $closeTs >= $openTs
            ) {
                $deadlineTs   = $absentTs + $exitGuardSeconds;
                $exitDeadline = gmdate('Y-m-d\TH:i:s\Z', $deadlineTs);
            }
        }

        // F35: Active anomalies for this room
        $anomalies = [];
        if ($this->anomalyRepo !== null) {
            $openAnomalies = $this->anomalyRepo->findOpenForRoom($roomId);
            $anomalies = array_map(fn ($a) => $a->toArray(), $openAnomalies);
        }

        // F46+: visibilidad de cuota Tuya (presupuesto/backoff compartido con el
        // poller) y de puerta abierta demasiado tiempo (anti-drenaje).
        $quotaFile = dirname(__DIR__, 3) . '/run/tuya-quota.json';
        $quota = ['callsDay' => 0, 'callsHour' => 0, 'backoffUntil' => 0];
        if (is_file($quotaFile)) {
            $qd = json_decode((string) @file_get_contents($quotaFile), true);
            if (is_array($qd)) {
                $quota = array_merge($quota, $qd);
            }
        }
        // F46++: `backoffUntil` en SEGUNDOS (normalizar legados en ms > 1e12).
        if (($quota['backoffUntil'] ?? 0) > 1e12) {
            $quota['backoffUntil'] = (int) round($quota['backoffUntil'] / 1000);
        }
        $quotaState = (($quota['backoffUntil'] ?? 0) > time()) ? 'exhausted' : 'ok';

        $doorOpenTooLong = false;
        if (($iotData['door_state'] ?? '') === \App\Domain\Presence\IotSession::DOOR_OPEN) {
            $ref   = $iotData['last_door_event_at'] ?? ($iotData['last_open_at'] ?? null);
            $refTs = $ref ? strtotime((string) $ref . ' UTC') : false;
            if ($refTs !== false && (time() - $refTs) > 300) {
                $doorOpenTooLong = true;
            }
        }

        return Response::json(200, [
            'room_id'          => $roomId,
            'code'             => $room->code,
            'status'           => $room->status,
            'presence_check_seconds' => $room->presenceCheckSeconds,
            'cooldown_until'   => $room->cooldownUntil,
            'iot_session'      => $iotData,
            'active_stay'      => $stayData,
            'switch_state'     => $switchState,
            'battery'          => $battery,
            'exit_deadline'    => $exitDeadline,
            'gap_seconds'      => $gapSeconds,
            // Bug 1 (RF-47.2.4): guarda de ausencia sostenida de la SALIDA.
            // `exit_deadline = last_absent_since + exit_guard_seconds`.
            'exit_guard_seconds' => $exitGuardSeconds,
            // F44 (RF-51.6): ventanas configurables del poller de presencia.
            'entry_window_seconds' => $entryWindowSeconds,
            'exit_check_seconds'   => $exitCheckSeconds,
            'qr_status'        => $qrStatus,
            'recent_events'    => $recentEvents,
            'recent_presence'  => $recentPresence,
            'anomalies'        => $anomalies,
            // F46+: cuota Tuya (ok|exhausted) + puerta abierta demasiado tiempo.
            'tuya_quota'         => [
                'state'         => $quotaState,
                'calls_today'   => (int) ($quota['callsDay'] ?? 0),
                'calls_hour'    => (int) ($quota['callsHour'] ?? 0),
                'backoff_until' => (int) ($quota['backoffUntil'] ?? 0),
            ],
            'door_open_too_long' => $doorOpenTooLong,
        ])
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0');
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchRecentAccessEvents(int $roomId, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, kind, result, reason, provider, correlation_id, meta_json, occurred_at
             FROM access_events
             WHERE room_id = :rid
             ORDER BY occurred_at DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':rid', $roomId, \PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $events = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $meta = null;
            if ($row['meta_json'] !== null) {
                $decoded = json_decode((string) $row['meta_json'], true);
                $meta = is_array($decoded) ? $decoded : null;
            }
            $events[] = [
                'id'             => (int) $row['id'],
                'kind'           => (string) $row['kind'],
                'result'         => (string) $row['result'],
                'reason'         => $row['reason'],
                'provider'       => (string) $row['provider'],
                'correlation_id' => (string) $row['correlation_id'],
                'meta'           => $meta,
                'occurred_at'    => (string) $row['occurred_at'],
            ];
        }
        return $events;
    }

    /**
     * Fetch QR credential status for a room.
     *
     * Mirrors the logic in GET /dashboard-api/qr-status.
     *
     * Fase 51 / Bug 4: `expired` follows the two-phase guest-QR lifetime:
     *   - never used  → expired when now > issued_at + QR_ARRIVAL_WINDOW_MINUTES
     *   - already used → expired when now > valid_until (fallback:
     *                    consumed_at + stay.duracion_minutos)
     *
     * @return array<string,mixed>
     */
    private function fetchQrStatus(int $roomId): array
    {
        $default = ['scannable' => false, 'consumed' => false, 'revoked' => false,
                     'expired' => false, 'jti' => '', 'stay_id' => null,
                     'first_used_at' => null, 'valid_until' => null,
                     'arrival_deadline' => null, 'in_use' => false];

        $stmt = $this->pdo->prepare(
            'SELECT qc.id, qc.jti, qc.issued_at, qc.consumed_at, qc.revoked_at, qc.expires_at,
                    qc.first_used_at, qc.valid_until, qc.stay_id, qc.room_id,
                    s.duracion_minutos AS stay_duracion
             FROM qr_credentials qc
             JOIN stays s ON s.id = qc.stay_id
             WHERE s.room_id = :rid AND s.status IN (\'RESERVED\',\'OCCUPIED\')
             ORDER BY qc.issued_at DESC LIMIT 1'
        );
        $stmt->execute([':rid' => $roomId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return $default;
        }

        $nowEpoch = Clock::nowUtc()->getTimestamp();
        $iatEpoch = self::sqlEpoch($row['issued_at'] ?? null) ?? $nowEpoch;
        $expEpoch = self::sqlEpoch($row['expires_at'] ?? null) ?? $iatEpoch;

        $firstUsedEpoch  = self::sqlEpoch($row['first_used_at'] ?? null)
            ?? self::sqlEpoch($row['consumed_at'] ?? null);
        $validUntilEpoch = self::sqlEpoch($row['valid_until'] ?? null);
        $arrivalMinutes  = Config::getInt('QR_ARRIVAL_WINDOW_MINUTES', 15) ?? 15;
        $stayDuration    = (int) ($row['stay_duracion'] ?? 0);

        $state   = QrWindows::evaluate(
            $iatEpoch, $firstUsedEpoch, $validUntilEpoch, $stayDuration, $arrivalMinutes, $nowEpoch
        );
        $expired = in_array(
            $state,
            [QrWindows::STATE_EXPIRED_ARRIVAL, QrWindows::STATE_EXPIRED_USAGE],
            true
        );

        $secret      = Config::getRequired('QR_SIGNING_SECRET');
        $qrTokenizer = new QrTokenizer($secret);
        $qrText      = $qrTokenizer->issue(
            (int) $row['room_id'],
            (int) $row['stay_id'],
            (string) $row['jti'],
            $iatEpoch,
            $expEpoch
        );

        return [
            'scannable'  => !$row['consumed_at'] && !$row['revoked_at'] && !$expired,
            'consumed'   => (bool) $row['consumed_at'],
            'revoked'    => (bool) $row['revoked_at'],
            'expired'    => $expired,
            'jti'        => (string) $row['jti'],
            'stay_id'    => (int) $row['stay_id'],
            'expires_at' => $row['expires_at'],
            'qr_text'    => $qrText,
            // Fase 51 — additive fields for the new arrival/usage lifecycle.
            'first_used_at'    => $row['first_used_at'] ?? null,
            'valid_until'      => $row['valid_until'] ?? null,
            'arrival_deadline' => gmdate('Y-m-d H:i:s',
                QrWindows::arrivalDeadline($iatEpoch, $arrivalMinutes)) . '.000',
            'in_use'           => $firstUsedEpoch !== null,
        ];
    }

    /** Parse a DB DATETIME(3) UTC string into an epoch, or null. */
    private static function sqlEpoch(?string $sql): ?int
    {
        if ($sql === null || $sql === '') {
            return null;
        }
        $ts = strtotime($sql . ' UTC');
        return $ts === false ? null : $ts;
    }
}
