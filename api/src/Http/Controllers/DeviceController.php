<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Devices\DeviceService;
use App\Http\Request;
use App\Http\Response;
use App\Support\Errors\BadRequestException;
use App\Support\Json\JsonBody;

/**
 * DeviceController: admin endpoints for managing room devices.
 *
 * Routes (not explicitly in contracts.md §2.2 but required for configuration;
 * guarded by rooms:write):
 *   GET    /rooms/{id}/devices
 *   POST   /rooms/{id}/devices
 *   PATCH  /devices/{id}
 *   DELETE /devices/{id}
 */
final class DeviceController
{
    private DeviceService $devices;

    public function __construct(DeviceService $devices)
    {
        $this->devices = $devices;
    }

    /**
     * List every device (pack-assigned and unassigned, e.g. RPI claimed
     * from factory without a pack — RF-39.4.5).
     * GET /api/v1/devices (scope: rooms:read)
     */
    public function index(Request $request): Response
    {
        $list = $this->devices->listAll();
        return Response::json(200, [
            'items' => array_map(static function ($d) { return $d->toArray(); }, $list),
        ]);
    }

    public function listForRoom(Request $request): Response
    {
        $roomId = (int) $request->routeParam('id');
        $list = $this->devices->listForRoom($roomId);
        return Response::json(200, [
            'items' => array_map(static function ($d) { return $d->toArray(); }, $list),
        ]);
    }

    /**
     * Create a device in the pack assigned to the given room (canonical F30:
     * devices belong to a pack, never directly to a room).
     * POST /api/v1/rooms/{id}/devices (scope: rooms:write)
     */
    public function create(Request $request): Response
    {
        $roomId = (int) $request->routeParam('id');
        $body = JsonBody::require($request->jsonBody);
        $kind = JsonBody::string($body, 'kind', 32);
        $externalId = JsonBody::string($body, 'external_id', 128);
        $label = isset($body['label']) && $body['label'] !== '' ? (string) $body['label'] : null;
        $apiClientId = JsonBody::intOpt($body, 'api_client_id', 1);
        $meta = null;
        if (array_key_exists('meta', $body)) {
            $m = $body['meta'];
            if ($m !== null && !is_array($m)) {
                throw new BadRequestException('meta must be an object or null');
            }
            $meta = $m;
        }
        $device = $this->devices->createInRoom($roomId, $kind, $externalId, $label, $apiClientId, $meta);
        return Response::json(201, $device->toArray());
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $body = JsonBody::require($request->jsonBody);
        $fields = [];
        if (array_key_exists('external_id', $body)) {
            $fields['external_id'] = $body['external_id'];
        }
        if (array_key_exists('label', $body)) {
            $fields['label'] = $body['label'];
        }
        if (array_key_exists('api_client_id', $body)) {
            $fields['api_client_id'] = $body['api_client_id'];
        }
        if (array_key_exists('meta', $body)) {
            $fields['meta'] = $body['meta'];
        }
        if (array_key_exists('pack_id', $body)) {
            $fields['pack_id'] = $body['pack_id'];
        }
        $device = $this->devices->update($id, $fields);
        return Response::json(200, $device->toArray());
    }

    public function delete(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $this->devices->delete($id);
        return Response::json(200, ['result' => 'ok', 'id' => $id]);
    }

    /**
     * register: convenience endpoint for device registration (canonical F30:
     * registration is by pack_id; room_id is no longer accepted).
     *
     * POST /api/v1/devices/register
     * Body: { "pack_id": 5, "kind": "RPI", "external_id": "92f57630" }
     */
    public function register(Request $request): Response
    {
        $body       = JsonBody::require($request->jsonBody);
        $packId     = JsonBody::int($body, 'pack_id');
        $kind       = JsonBody::string($body, 'kind', 32);
        $externalId = JsonBody::string($body, 'external_id', 128);
        $apiClientId = JsonBody::intOpt($body, 'api_client_id', 1);
        $meta = null;
        if (array_key_exists('meta', $body)) {
            $m = $body['meta'];
            if ($m !== null && !is_array($m)) {
                throw new BadRequestException('meta must be an object or null');
            }
            $meta = $m;
        }
        $device = $this->devices->create($packId, $kind, $externalId, null, $apiClientId, $meta);
        return Response::json(201, $device->toArray());
    }

    /**
     * Mark/unmark a device as identified ("mírame").
     * POST /api/v1/devices/identify (scope: rpi)
     *
     * Body: { "external_id": "92f57630", "state": true }
     */
    public function identify(Request $request): Response
    {
        $body       = JsonBody::require($request->jsonBody);
        $externalId = JsonBody::string($body, 'external_id', 128);
        $state      = JsonBody::bool($body, 'state');

        $result = $this->devices->identify($externalId, $state);
        return Response::json(200, $result);
    }

    /**
     * Admin force-unidentify a device.
     * DELETE /api/v1/devices/{id}/identify (scope: rooms:write)
     */
    public function unidentify(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $this->devices->unidentify($id);
        return Response::json(200, ['identified' => false, 'device_id' => $id]);
    }
}
