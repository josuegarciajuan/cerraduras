<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\ApiClientRepository;
use App\Http\Request;
use App\Http\Response;
use App\Support\Errors\ApiException;
use App\Support\Json\JsonBody;
use PDO;

final class AdminApiClientController
{
    private ApiClientRepository $repo;
    private PDO $pdo;

    public function __construct(ApiClientRepository $repo, PDO $pdo)
    {
        $this->repo = $repo;
        $this->pdo  = $pdo;
    }

    public function index(Request $request): Response
    {
        $all = $this->repo->findAll();
        $items = array_map(function ($c) {
            return [
                'id'               => $c->id,
                'code'             => $c->code,
                'kind'             => $c->kind,
                'scopes'           => $c->scopes,
                'ip_whitelist'     => $c->ipWhitelist,
                'active'           => $c->active,
            ];
        }, $all);
        return Response::json(200, ['items' => $items]);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $client = $this->repo->findById($id);
        if (!$client) {
            throw new ApiException(404, 'not_found', 'API client not found');
        }
        return Response::json(200, [
            'id'           => $client->id,
            'code'         => $client->code,
            'kind'         => $client->kind,
            'scopes'       => $client->scopes,
            'ip_whitelist' => $client->ipWhitelist,
            'active'       => $client->active,
        ]);
    }

    public function create(Request $request): Response
    {
        $body   = JsonBody::require($request->jsonBody);
        $code   = JsonBody::string($body, 'code', 64);
        $kind   = JsonBody::string($body, 'kind', 20);
        $scopes = $body['scopes'] ?? [];
        $ipList = $body['ip_whitelist'] ?? null;

        if (!is_array($scopes)) {
            throw new ApiException(400, 'bad_request', 'scopes must be an array');
        }
        $scopesCsv = implode(',', $scopes);
        $ipCsv = is_array($ipList) ? implode(',', $ipList) : null;

        $plainKey = bin2hex(random_bytes(24));
        $keyHash  = hash('sha256', $plainKey);

        $validKinds = ['VB6','RPI','ADMIN','SIM','VB6_BRIDGE','TUYA_BRIDGE'];
        if (!in_array($kind, $validKinds, true)) {
            throw new ApiException(400, 'bad_request', 'Invalid kind: ' . $kind);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO api_clients (code, kind, api_key_hash, scopes_csv, ip_whitelist_csv, active, created_at, updated_at)
             VALUES (:code, :kind, :hash, :scopes, :ip, 1, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))'
        );
        $stmt->bindValue(':code', $code);
        $stmt->bindValue(':kind', $kind);
        $stmt->bindValue(':hash', $keyHash);
        $stmt->bindValue(':scopes', $scopesCsv);
        $stmt->bindValue(':ip', $ipCsv);
        $stmt->execute();
        $id = (int) $this->pdo->lastInsertId();

        return Response::json(201, [
            'id'      => $id,
            'code'    => $code,
            'api_key' => $plainKey,
            'warning' => 'Store this key securely. It will not be shown again.',
        ]);
    }

    public function update(Request $request): Response
    {
        $id   = (int) $request->routeParam('id');
        $body = JsonBody::require($request->jsonBody);

        $existing = $this->repo->findById($id);
        if (!$existing) {
            throw new ApiException(404, 'not_found', 'API client not found');
        }

        $updates = [];
        if (isset($body['code'])) $updates['code'] = (string) $body['code'];
        if (isset($body['kind'])) $updates['kind'] = (string) $body['kind'];
        if (isset($body['scopes'])) {
            $scopes = $body['scopes'];
            $updates['scopes_csv'] = is_array($scopes) ? implode(',', $scopes) : (string) $scopes;
        }
        if (isset($body['ip_whitelist'])) {
            $ip = $body['ip_whitelist'];
            $updates['ip_whitelist_csv'] = is_array($ip) ? implode(',', $ip) : ($ip === null ? null : (string) $ip);
        }
        if (isset($body['active'])) $updates['active'] = (bool) $body['active'] ? 1 : 0;

        $this->repo->update($id, $updates);
        $client = $this->repo->findById($id);

        return Response::json(200, [
            'id'           => $client->id,
            'code'         => $client->code,
            'kind'         => $client->kind,
            'scopes'       => $client->scopes,
            'ip_whitelist' => $client->ipWhitelist,
            'active'       => $client->active,
        ]);
    }

    public function delete(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $this->repo->update($id, ['active' => 0]);
        return Response::json(200, ['deleted' => true]);
    }

    public function rotateKey(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $existing = $this->repo->findById($id);
        if (!$existing) {
            throw new ApiException(404, 'not_found', 'API client not found');
        }

        $plainKey = bin2hex(random_bytes(24));
        $keyHash  = hash('sha256', $plainKey);

        $this->repo->update($id, ['api_key_hash' => $keyHash]);

        return Response::json(200, [
            'id'      => $id,
            'code'    => $existing->code,
            'api_key' => $plainKey,
            'warning' => 'Store this key securely. It will not be shown again.',
        ]);
    }
}
