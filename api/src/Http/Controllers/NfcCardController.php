<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AccessKeys\NfcCardService;
use App\Http\Request;
use App\Http\Response;
use App\Support\Json\JsonBody;

/**
 * NfcCardController: /api/v1/nfc-cards endpoints (F96).
 *
 * Scopes: GET → nfc-cards:read, mutations → nfc-cards:write (F98).
 */
final class NfcCardController
{
    public function __construct(private NfcCardService $service)
    {
    }

    public function list(Request $request): Response
    {
        $filters = [];
        foreach (['status', 'q'] as $k) {
            $v = $request->query($k);
            if ($v !== null && $v !== '') {
                $filters[$k] = (string) $v;
            }
        }
        $items = $this->service->list($filters);
        return Response::json(200, ['data' => $items, 'meta' => ['total' => count($items)]]);
    }

    public function show(Request $request): Response
    {
        return Response::json(200, ['data' => $this->service->get((int) $request->routeParam('id'))]);
    }

    public function create(Request $request): Response
    {
        $body = JsonBody::require($request->jsonBody);
        $data = [
            'number' => JsonBody::string($body, 'number', 32),
            'code'   => JsonBody::string($body, 'code', 64),
        ];
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
        if (array_key_exists('number', $body)) {
            $data['number'] = $body['number'];
        }
        if (array_key_exists('notes', $body)) {
            $data['notes'] = $body['notes'];
        }
        return Response::json(200, ['data' => $this->service->update($id, $data)]);
    }

    public function disable(Request $request): Response
    {
        return Response::json(200, ['data' => $this->service->disable((int) $request->routeParam('id'))]);
    }
}
