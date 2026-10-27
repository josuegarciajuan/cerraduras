<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Devices\ApplyPackService;
use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceIntegrationStatusService;
use App\Domain\Devices\DevicePackRepository;
use App\Http\Request;
use App\Http\Response;
use App\Support\Errors\ApiException;
use App\Support\Json\JsonBody;

final class DevicePackController
{
    private DevicePackRepository $packRepo;
    private ApplyPackService $applyPackService;
    private DeviceIntegrationStatusService $status;

    public function __construct(
        DevicePackRepository $packRepo,
        ApplyPackService $applyPackService,
        ?DeviceIntegrationStatusService $status = null
    ) {
        $this->packRepo         = $packRepo;
        $this->applyPackService = $applyPackService;
        $this->status           = $status ?? new DeviceIntegrationStatusService();
    }

    /** @return array<string,mixed> */
    private function serialize(\App\Domain\Devices\DevicePack $pack): array
    {
        $data = $pack->toArray();
        foreach ($data['devices'] as &$device) {
            $device['transport'] = $device['transport'] ?? Device::TRANSPORT_UNKNOWN;
            $device['integration_status'] = $this->status->forRow($device);
        }
        unset($device);
        return $data;
    }

    public function index(Request $request): Response
    {
        $packs = array_map(fn($p) => $this->serialize($p), $this->packRepo->findAll());
        return Response::json(200, ['items' => $packs]);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $pack = $this->packRepo->findById($id);
        if (!$pack) throw new ApiException(404, 'not_found', 'Pack no encontrado');
        return Response::json(200, $this->serialize($pack));
    }

    public function create(Request $request): Response
    {
        $body = JsonBody::require($request->jsonBody);
        $name = JsonBody::string($body, 'name', 128);
        $code = $body['code'] ?? strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $name)));

        $existing = $this->packRepo->findByCode($code);
        if ($existing) throw new ApiException(409, 'conflict', 'Ya existe un pack con ese código');

        $id = $this->packRepo->insert($code, $name);
        $pack = $this->packRepo->findById($id);
        return Response::json(201, $this->serialize($pack));
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $body = JsonBody::require($request->jsonBody);
        $name = JsonBody::string($body, 'name', 128);
        $code = $body['code'] ?? strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $name)));

        $existing = $this->packRepo->findById($id);
        if (!$existing) throw new ApiException(404, 'not_found', 'Pack no encontrado');

        $byCode = $this->packRepo->findByCode($code);
        if ($byCode && $byCode->id !== $id) throw new ApiException(409, 'conflict', 'Ya existe otro pack con ese código');

        $this->packRepo->update($id, $code, $name);
        return Response::json(200, $this->serialize($this->packRepo->findById($id)));
    }

    public function delete(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $pack = $this->packRepo->findById($id);
        if (!$pack) throw new ApiException(404, 'not_found', 'Pack no encontrado');
        $this->packRepo->delete($id);
        return Response::json(200, ['deleted' => true]);
    }

    public function apply(Request $request): Response
    {
        $roomId = (int) $request->routeParam('id');
        $packId = (int) $request->routeParam('pack_id');
        $result = $this->applyPackService->apply($roomId, $packId, 'replace');
        return Response::json(200, $result);
    }
}
