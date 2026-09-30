<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceService;
use App\Domain\Warehouse\Go2rtcClient;
use App\Http\Request;
use App\Http\Response;
use App\Support\Errors\BadRequestException;
use App\Support\Errors\NotFoundException;
use App\Support\Errors\UnprocessableException;
use App\Support\Json\JsonBody;
use PDO;

/**
 * WarehouseCameraController: manages CAMERA devices for the warehouse and keeps
 * go2rtc in sync (F61/RF-68.3, RF-72).
 *
 * Routes (public LAN):
 *   GET    /almacen-api/cameras?room_id=
 *   POST   /almacen-api/cameras
 *   PATCH  /almacen-api/cameras/{id}
 *   DELETE /almacen-api/cameras/{id}
 *   POST   /almacen-api/cameras/sync
 */
final class WarehouseCameraController
{
    private PDO $pdo;
    private DeviceService $devices;
    private Go2rtcClient $go2rtc;

    public function __construct(PDO $pdo, DeviceService $devices, Go2rtcClient $go2rtc)
    {
        $this->pdo = $pdo;
        $this->devices = $devices;
        $this->go2rtc = $go2rtc;
    }

    public function index(Request $request): Response
    {
        $roomId = (int) ($request->query['room_id'] ?? 0);
        if ($roomId <= 0) {
            throw new BadRequestException('room_id required', ['field' => 'room_id']);
        }
        return Response::json(200, ['cameras' => $this->listForRoom($roomId)]);
    }

    public function create(Request $request): Response
    {
        $body = JsonBody::require($request->jsonBody);
        $roomId = JsonBody::int($body, 'room_id');
        $position = strtoupper(JsonBody::string($body, 'position', 32));
        if (!in_array($position, Device::cameraPositions(), true)) {
            throw new UnprocessableException('subtype_invalid', 'position must be EXTERIOR or INTERIOR', ['field' => 'position']);
        }
        $rtspUrl = JsonBody::string($body, 'rtsp_url', 512);
        $externalId = JsonBody::string($body, 'external_id', 128);
        $label = isset($body['label']) && $body['label'] !== '' ? (string) $body['label'] : null;

        $room = $this->pdo->prepare('SELECT id, pack_id FROM rooms WHERE id = :id LIMIT 1');
        $room->execute([':id' => $roomId]);
        $roomRow = $room->fetch(PDO::FETCH_ASSOC);
        if ($roomRow === false) {
            throw new NotFoundException('Room not found', ['room_id' => $roomId]);
        }
        if ($roomRow['pack_id'] === null) {
            throw new UnprocessableException('room_has_no_pack', 'La habitación no tiene pack asignado', ['room_id' => $roomId]);
        }

        $meta = [
            'rtsp_url' => $rtspUrl,
            'enabled' => $this->boolOpt($body, 'enabled', true),
            'record_enabled' => $this->boolOpt($body, 'record_enabled', true),
        ];
        $device = $this->devices->create((int) $roomRow['pack_id'], 'CAMERA', $externalId, $label, null, $meta, $position);
        $this->syncRoom($roomId);
        return Response::json(201, ['camera' => $this->format($device, $roomId)]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $device = $this->devices->listAll();
        $found = null;
        foreach ($device as $d) {
            if ($d->id === $id && $d->kind === 'CAMERA') {
                $found = $d;
                break;
            }
        }
        if ($found === null) {
            throw new NotFoundException('Camera not found', ['camera_id' => $id]);
        }
        $body = JsonBody::require($request->jsonBody);
        $fields = [];
        if (array_key_exists('label', $body)) {
            $fields['label'] = $body['label'];
        }
        if (array_key_exists('position', $body)) {
            $fields['subtype'] = strtoupper((string) $body['position']);
        }
        $meta = $found->meta ?? [];
        if (array_key_exists('rtsp_url', $body)) {
            $meta['rtsp_url'] = (string) $body['rtsp_url'];
        }
        if (array_key_exists('enabled', $body)) {
            $meta['enabled'] = (bool) $body['enabled'];
        }
        if (array_key_exists('record_enabled', $body)) {
            $meta['record_enabled'] = (bool) $body['record_enabled'];
        }
        $fields['meta'] = $meta;
        $updated = $this->devices->update($id, $fields);
        $roomId = $this->pdo->prepare('SELECT r.id FROM rooms r JOIN devices d ON d.pack_id = r.pack_id WHERE d.id = :id LIMIT 1');
        $roomId->execute([':id' => $id]);
        $rid = (int) ($roomId->fetchColumn() ?: 0);
        $this->syncRoom($rid);
        return Response::json(200, ['camera' => $this->format($updated, $rid)]);
    }

    public function delete(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $device = $this->devices->listAll();
        $found = null;
        foreach ($device as $d) {
            if ($d->id === $id && $d->kind === 'CAMERA') { $found = $d; break; }
        }
        if ($found === null) {
            throw new NotFoundException('Camera not found', ['camera_id' => $id]);
        }
        $meta = $found->meta ?? [];
        $meta['enabled'] = false;
        $this->devices->update($id, ['meta' => $meta]);
        return Response::json(200, ['ok' => true]);
    }

    public function sync(Request $request): Response
    {
        $n = 0;
        foreach ($this->pdo->query(
            "SELECT r.id AS room_id, d.subtype, d.meta_json
             FROM devices d JOIN rooms r ON r.pack_id = d.pack_id
             WHERE d.kind='CAMERA' AND r.pack_id IS NOT NULL"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $meta = json_decode((string) ($row['meta_json'] ?? ''), true) ?: [];
            $url = is_string($meta['rtsp_url'] ?? null) ? $meta['rtsp_url'] : '';
            if ($url !== '' && ($meta['enabled'] ?? true) !== false) {
                if ($this->go2rtc->upsert(Go2rtcClient::streamName((int) $row['room_id'], (string) $row['subtype']), $url)) {
                    $n++;
                }
            }
        }
        return Response::json(200, ['ok' => true, 'streams' => $n]);
    }

    // -- helpers ---------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function listForRoom(int $roomId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT d.id, d.pack_id, d.kind, d.subtype, d.external_id, d.label, d.meta_json
             FROM devices d JOIN rooms r ON r.pack_id = d.pack_id
             WHERE r.id = :rid AND d.kind='CAMERA'
             ORDER BY FIELD(d.subtype,'EXTERIOR','INTERIOR'), d.id"
        );
        $stmt->execute([':rid' => $roomId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = $this->formatRow($row, $roomId);
        }
        return $out;
    }

    private function syncRoom(int $roomId): void
    {
        if ($roomId <= 0 || !$this->go2rtc->enabled()) {
            return;
        }
        foreach ($this->listForRoom($roomId) as $cam) {
            if ($cam['enabled'] && $cam['rtsp_url'] !== '') {
                $this->go2rtc->upsert($cam['stream'], $cam['rtsp_url']);
            } elseif (($cam['stream'] ?? '') !== '') {
                $this->go2rtc->remove($cam['stream']);
            }
        }
    }

    /** @param array<string,mixed> $row */
    private function formatRow(array $row, int $roomId): array
    {
        $meta = json_decode((string) ($row['meta_json'] ?? ''), true) ?: [];
        $position = (string) ($row['subtype'] ?? '');
        $enabled = ($meta['enabled'] ?? true) !== false;
        $rtsp = is_string($meta['rtsp_url'] ?? null) ? $meta['rtsp_url'] : '';
        $stream = Go2rtcClient::streamName($roomId, $position);

        $rec = $this->pdo->prepare("SELECT COUNT(*) FROM camera_recordings WHERE room_id=:r AND position=:p AND status='RECORDING'");
        $rec->execute([':r' => $roomId, ':p' => $position]);
        $recording = (int) $rec->fetchColumn() > 0;

        return [
            'id' => (int) $row['id'],
            'position' => $position,
            'label' => $row['label'] !== null ? (string) $row['label'] : null,
            'external_id' => (string) $row['external_id'],
            'enabled' => $enabled,
            'record_enabled' => ($meta['record_enabled'] ?? true) !== false,
            'rtsp_url' => $rtsp,
            'rtsp_url_set' => $rtsp !== '',
            'online' => null,
            'recording' => $recording,
            'stream' => $stream,
            'live_url' => $enabled ? $this->go2rtc->liveUrl($roomId, $position) : null,
        ];
    }

    private function format(Device $d, int $roomId): array
    {
        $meta = $d->meta ?? [];
        $position = (string) ($d->subtype ?? '');
        $enabled = ($meta['enabled'] ?? true) !== false;
        return [
            'id' => $d->id,
            'position' => $position,
            'label' => $d->label,
            'external_id' => $d->externalId,
            'enabled' => $enabled,
            'record_enabled' => ($meta['record_enabled'] ?? true) !== false,
            'rtsp_url' => is_string($meta['rtsp_url'] ?? null) ? $meta['rtsp_url'] : '',
            'rtsp_url_set' => is_string($meta['rtsp_url'] ?? null) && $meta['rtsp_url'] !== '',
            'online' => null,
            'recording' => false,
            'stream' => Go2rtcClient::streamName($roomId, $position),
            'live_url' => $enabled ? $this->go2rtc->liveUrl($roomId, $position) : null,
        ];
    }

    /** @param array<string,mixed> $body */
    private function boolOpt(array $body, string $key, bool $default): bool
    {
        return array_key_exists($key, $body) ? (bool) $body[$key] : $default;
    }
}
