<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AccessKeys\AccessKeyService;
use App\Http\Request;
use App\Http\Response;
use App\Support\Json\JsonBody;

/**
 * AccessKeyController: /api/v1/keys endpoints (F96).
 *
 * Scopes:
 *   GET                      → keys:read
 *   POST/PATCH/...           → keys:write
 *   POST /keys/validate      → qr:validate
 *   GET  /keys/accesses      → audit:read
 *   GET  /keys/{id}/accesses → audit:read
 */
final class AccessKeyController
{
    public function __construct(private AccessKeyService $service)
    {
    }

    public function list(Request $request): Response
    {
        $filters = [];
        foreach (['status', 'owner_type', 'key_type', 'q'] as $k) {
            $v = $request->query($k);
            if ($v !== null && $v !== '') {
                $filters[$k] = (string) $v;
            }
        }
        if ($request->query('worker_id')) {
            $filters['worker_id'] = (int) $request->query('worker_id');
        }
        if ($request->query('role_id')) {
            $filters['role_id'] = (int) $request->query('role_id');
        }

        $items = $this->service->listKeys($filters);
        return Response::json(200, ['data' => $items, 'meta' => ['total' => count($items)]]);
    }

    public function show(Request $request): Response
    {
        return Response::json(200, ['data' => $this->service->getKey((int) $request->routeParam('id'))]);
    }

    public function defaults(Request $request): Response
    {
        $workerId = $request->query('worker_id');
        $roleId   = $request->query('role_id');
        return Response::json(200, [
            'data' => $this->service->defaults(
                $workerId !== null && $workerId !== '' ? (int) $workerId : null,
                $roleId !== null && $roleId !== '' ? (int) $roleId : null
            ),
        ]);
    }

    public function create(Request $request): Response
    {
        $body = JsonBody::require($request->jsonBody);
        $data = [
            'label'          => JsonBody::string($body, 'label', 120),
            'owner_type'     => JsonBody::string($body, 'owner_type', 10),
            'key_type'       => JsonBody::string($body, 'key_type', 8),
            'valid_until'    => JsonBody::string($body, 'valid_until', 40),
            'all_room_types' => !empty($body['all_room_types']),
            'room_type_ids'  => is_array($body['room_type_ids'] ?? null) ? $body['room_type_ids'] : [],
        ];
        if (!empty($body['worker_id'])) {
            $data['worker_id'] = (int) $body['worker_id'];
        }
        if (!empty($body['role_id'])) {
            $data['role_id'] = (int) $body['role_id'];
        }
        if (!empty($body['nfc_card_id'])) {
            $data['nfc_card_id'] = (int) $body['nfc_card_id'];
        }
        if (array_key_exists('notes', $body)) {
            $data['notes'] = $body['notes'];
        }

        return Response::json(201, ['data' => $this->service->create($data)]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $body = JsonBody::require($request->jsonBody);
        $data = [];
        foreach (['label', 'valid_until', 'notes'] as $k) {
            if (array_key_exists($k, $body)) {
                $data[$k] = $body[$k];
            }
        }
        if (array_key_exists('all_room_types', $body)) {
            $data['all_room_types'] = !empty($body['all_room_types']);
        }
        if (is_array($body['room_type_ids'] ?? null)) {
            $data['room_type_ids'] = $body['room_type_ids'];
        }
        if (!empty($body['nfc_card_id'])) {
            $data['nfc_card_id'] = (int) $body['nfc_card_id'];
        }

        return Response::json(200, ['data' => $this->service->update($id, $data)]);
    }

    public function revoke(Request $request): Response
    {
        return Response::json(200, ['data' => $this->service->revoke((int) $request->routeParam('id'))]);
    }

    public function regenerateQr(Request $request): Response
    {
        return Response::json(200, ['data' => $this->service->regenerateQr((int) $request->routeParam('id'))]);
    }

    public function accesses(Request $request): Response
    {
        return Response::json(200, [
            'data' => $this->service->accessEvents($this->accessFilters($request)),
            'meta' => [],
        ]);
    }

    public function keyAccesses(Request $request): Response
    {
        $filters = $this->accessFilters($request);
        $filters['key_id'] = (int) $request->routeParam('id');
        return Response::json(200, ['data' => $this->service->accessEvents($filters)]);
    }

    /**
     * POST /api/v1/keys/validate — generic credential validation for future
     * NFC readers. QR scans are also dispatched here by the legacy endpoints.
     */
    public function validate(Request $request): Response
    {
        $body = JsonBody::require($request->jsonBody);
        $deviceId = JsonBody::string($body, 'device_id');
        $provider = (string) ($request->query('provider') ?? 'TUYA');
        $corrId = (string) $request->attr('correlation_id', '');
        $roomId = !empty($body['room_id']) ? (int) $body['room_id'] : null;

        if (!empty($body['qr_text'])) {
            return Response::json(200, $this->service->validateQrToken(
                (string) $body['qr_text'], $roomId, $deviceId, $provider, $corrId
            ));
        }
        if (!empty($body['ficha_code'])) {
            return Response::json(200, $this->service->validateFicha(
                (string) $body['ficha_code'], $roomId, $deviceId, $provider, $corrId
            ));
        }
        return Response::json(422, ['error' => [
            'code' => 'validation_error',
            'message' => 'qr_text or ficha_code is required',
        ]]);
    }

    /** @return array<string,mixed> */
    private function accessFilters(Request $request): array
    {
        $filters = [];
        foreach (['result', 'from', 'to'] as $k) {
            $v = $request->query($k);
            if ($v !== null && $v !== '') {
                $filters[$k] = (string) $v;
            }
        }
        foreach (['worker_id', 'role_id', 'room_id'] as $k) {
            if ($request->query($k)) {
                $filters[$k] = (int) $request->query($k);
            }
        }
        if ($request->query('limit')) {
            $filters['limit'] = (int) $request->query('limit');
        }
        return $filters;
    }
}
