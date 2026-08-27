<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\FactoryDevices\FactoryDeviceService;
use App\Http\Request;
use App\Http\Response;
use App\Support\Json\JsonBody;

final class FactoryDeviceController
{
    public function __construct(private FactoryDeviceService $service) {}

    public function announce(Request $request): Response
    {
        $body = JsonBody::require($request->jsonBody);
        $result = $this->service->announce(JsonBody::string($body, 'chip_id'));
        return Response::json($result['created'] ? 201 : 200, $result);
    }

    public function list(Request $request): Response
    {
        return Response::json(200, ['data' => $this->service->list($request->query('status', 'PENDING') ?? 'PENDING')]);
    }

    public function claim(Request $request): Response
    {
        $crmUser = $request->attr('crm_user');
        $client = $request->attr('api_client');
        $actor = is_object($crmUser) && isset($crmUser->username) ? (string)$crmUser->username
            : (is_object($client) && isset($client->code) ? (string)$client->code : 'unknown');
        $actorClientId = is_object($client) && isset($client->id) && (int)$client->id > 0 ? (int)$client->id : null;
        return Response::json(200, ['data' => $this->service->claim((int)$request->routeParam('id'), $actor, $actorClientId)]);
    }
}
