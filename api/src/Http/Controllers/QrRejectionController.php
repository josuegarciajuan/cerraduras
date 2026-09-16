<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Support\Json\JsonBody;
use App\Support\Ulid;

/**
 * QrRejectionController: telemetría on-device de lecturas QR descartadas por el
 * firmware antes de llamar a /api/v1/qr/validate.
 *
 * El ESP32 pre-filtra la cadena leída y descarta en silencio las lecturas
 * malformadas (lectura parcial USB-HID). Sin monitor serie esa pérdida es
 * invisible: la API nunca recibe la petición. Este endpoint persiste
 * len/dots/hex en `audit_log` para diagnosticarlo desde el servidor.
 *
 * POST /api/v1/devices/qr-rejected  (scope: qr:validate)
 * Body: { external_id, len, dots, hex_head?, hex_tail?, fw? }
 */
final class QrRejectionController
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function store(Request $request): Response
    {
        $body = JsonBody::require($request->jsonBody);

        $externalId = JsonBody::string($body, 'external_id', 128);
        $len        = JsonBody::intOpt($body, 'len', 0, 100000) ?? 0;
        $dots       = JsonBody::intOpt($body, 'dots', 0, 1000) ?? 0;
        $hexHead    = isset($body['hex_head']) ? mb_substr((string) $body['hex_head'], 0, 240) : null;
        $hexTail    = isset($body['hex_tail']) ? mb_substr((string) $body['hex_tail'], 0, 120) : null;
        $fw         = isset($body['fw']) ? mb_substr((string) $body['fw'], 0, 64) : null;

        $correlationId = (string) $request->attr('correlation_id', '');
        if ($correlationId === '' || !Ulid::isValid($correlationId)) {
            $correlationId = Ulid::generate();
        }

        $client  = $request->attr('api_client');
        $actorId = is_object($client) && isset($client->id) ? (int) $client->id : null;

        // Contexto del dispositivo (pack/kind) para el rastro de auditoría.
        $deviceRow = null;
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, kind, external_id, pack_id FROM devices WHERE external_id = :e LIMIT 1'
            );
            $stmt->execute([':e' => $externalId]);
            $deviceRow = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            $deviceRow = null;
        }

        $payload = [
            'external_id' => $externalId,
            'len'         => $len,
            'dots'        => $dots,
            'hex_head'    => $hexHead,
            'hex_tail'    => $hexTail,
            'fw'          => $fw,
            'device_id'   => $deviceRow['id'] ?? null,
            'kind'        => $deviceRow['kind'] ?? null,
            'pack_id'     => $deviceRow['pack_id'] ?? null,
            'remote_ip'   => $request->remoteIp,
        ];

        $ins = $this->pdo->prepare(
            'INSERT INTO audit_log
                (actor_client_id, scope, action, entity, entity_id, correlation_id, ip, payload_json)
             VALUES (:client, :scope, :action, :entity, :eid, :corr, :ip, :payload)'
        );
        $ins->execute([
            ':client'  => $actorId,
            ':scope'   => 'device',
            ':action'  => 'qr_rejected',
            ':entity'  => 'qr',
            ':eid'     => $externalId,
            ':corr'    => $correlationId,
            ':ip'      => $request->remoteIp,
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return Response::json(202, ['accepted' => true, 'len' => $len, 'dots' => $dots]);
    }
}
