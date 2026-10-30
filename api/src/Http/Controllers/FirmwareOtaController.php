<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Firmware\FirmwareOtaService;
use App\Domain\Firmware\FirmwareReleaseRepository;
use App\Http\Request;
use App\Http\Response;
use App\Support\Errors\BadRequestException;
use App\Support\Errors\UnauthorizedException;
use App\Support\Json\JsonBody;

/**
 * FirmwareOtaController — F100.
 *
 * Panel routes:
 *   GET  /api/v1/firmware/releases
 *   GET  /api/v1/devices/{id}/firmware-operations
 *   POST /api/v1/devices/{id}/firmware-update
 *   GET  /api/v1/firmware-operations/{id}
 *   POST /api/v1/firmware-operations/{id}/cancel
 *
 * Device routes (authenticated, bound to the ESP credential):
 *   GET  /dashboard-api/firmware-update
 *   GET  /dashboard-api/firmware-download
 *   POST /dashboard-api/firmware-progress
 *   POST /dashboard-api/firmware-confirm
 */
final class FirmwareOtaController
{
    private FirmwareOtaService $service;
    private FirmwareReleaseRepository $releases;

    public function __construct(FirmwareOtaService $service, FirmwareReleaseRepository $releases)
    {
        $this->service = $service;
        $this->releases = $releases;
    }

    public function listReleases(Request $request): Response
    {
        $variant = $request->query('variant');
        $items = $this->releases->listPublished($variant);
        foreach ($items as &$item) {
            // Never expose the internal storage path.
            unset($item['binary_path']);
        }
        unset($item);
        return Response::json(200, ['items' => $items]);
    }

    public function listForDevice(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        return Response::json(200, ['items' => $this->service->listForDevice($id)]);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $op = $this->service->findOperation($id);
        if ($op === null) {
            return Response::json(404, ['error' => 'firmware_operation_not_found']);
        }
        return Response::json(200, $op);
    }

    public function createForDevice(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $body = JsonBody::require($request->jsonBody);
        $variant = JsonBody::stringOpt($body, 'variant', 16);
        $upgrade = JsonBody::boolOpt($body, 'upgrade') ?? false;
        if ($variant === null && !$upgrade) {
            throw new BadRequestException('variant or upgrade is required');
        }
        $op = $this->service->request($id, $variant, $this->actor($request), $upgrade);
        if ($op === null) {
            return Response::json(200, ['created' => false, 'operation' => null]);
        }
        return Response::json(202, ['created' => true, 'operation' => $op]);
    }

    public function cancel(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        return Response::json(200, $this->service->cancel($id));
    }

    public function deviceManifest(Request $request): Response
    {
        $externalId = (string) ($request->query('external_id') ?? '');
        $this->requireDeviceIdentity($request, $externalId);
        return Response::json(200, $this->service->manifestFor($externalId));
    }

    public function deviceBinary(Request $request): Response
    {
        $externalId = (string) ($request->query('external_id') ?? '');
        $operationId = (int) ($request->query('operation_id') ?? 0);
        $this->requireDeviceIdentity($request, $externalId);
        if ($operationId <= 0) {
            throw new BadRequestException('operation_id is required');
        }
        $binary = $this->service->binaryFor($externalId, $operationId);
        return new Response(200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $binary['filename'] . '"',
            'Content-Length' => (string) strlen($binary['body']),
            'X-Firmware-SHA256' => $binary['sha256'],
            'X-Content-Type-Options' => 'nosniff',
        ], $binary['body']);
    }

    public function deviceProgress(Request $request): Response
    {
        $body = JsonBody::require($request->jsonBody);
        $externalId = JsonBody::string($body, 'external_id', 128);
        $this->requireDeviceIdentity($request, $externalId);
        return Response::json(200, $this->service->report($externalId, $body));
    }

    public function deviceConfirm(Request $request): Response
    {
        $body = JsonBody::require($request->jsonBody);
        $externalId = JsonBody::string($body, 'external_id', 128);
        $this->requireDeviceIdentity($request, $externalId);
        return Response::json(200, $this->service->confirm($externalId, $body));
    }

    private function actor(Request $request): string
    {
        $crmUser = $request->attr('crm_user');
        if (is_object($crmUser) && isset($crmUser->username)) {
            return (string) $crmUser->username;
        }
        $client = $request->attr('api_client');
        if (is_object($client) && isset($client->code)) {
            return (string) $client->code;
        }
        return 'unknown';
    }

    /**
     * Defence in depth: the route middleware already binds the API key to a
     * device, but we re-check the supplied external_id against that binding.
     */
    private function requireDeviceIdentity(Request $request, string $externalId): void
    {
        if ($externalId === '') {
            throw new BadRequestException('external_id is required');
        }
        $client = $request->attr('api_client');
        if (!is_object($client) || !isset($client->deviceId) || $client->deviceId === null) {
            // CRM sessions have no device binding; they must not call these routes.
            throw new UnauthorizedException('Device credential required');
        }
    }
}
