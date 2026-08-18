<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Support\Errors\ApiException;
use App\Support\Json\JsonBody;
use PDO;

final class AdminSettingsController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function show(Request $request): Response
    {
        $service = $request->query('service') ?? 'api';
        if (!in_array($service, ['api', 'ws-vb6'], true)) {
            throw new ApiException(400, 'bad_request', 'service must be "api" or "ws-vb6"');
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM system_settings WHERE service = :svc ORDER BY category, setting_key'
        );
        $stmt->bindValue(':svc', $service);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($rows as $row) {
            $cat = $row['category'];
            if (!isset($grouped[$cat])) $grouped[$cat] = [];
            $item = [
                'key'      => $row['setting_key'],
                'value'    => $row['value'],
                'is_sensitive' => (bool) $row['is_sensitive'],
            ];
            // Mask sensitive values
            if ($item['is_sensitive'] && $item['value'] !== null && strlen($item['value']) > 4) {
                $item['value_masked'] = substr($item['value'], 0, 4) . '...';
            } else {
                $item['value_masked'] = $item['value'];
            }
            $grouped[$cat][] = $item;
        }

        return Response::json(200, ['service' => $service, 'settings' => $grouped]);
    }

    public function update(Request $request): Response
    {
        $body = JsonBody::require($request->jsonBody);
        $service = JsonBody::string($body, 'service', 10);
        $settings = $body['settings'] ?? [];

        if (!is_array($settings) || empty($settings)) {
            throw new ApiException(400, 'bad_request', 'settings must be a non-empty array');
        }
        if (!in_array($service, ['api', 'ws-vb6'], true)) {
            throw new ApiException(400, 'bad_request', 'service must be "api" or "ws-vb6"');
        }

        $updateStmt = $this->pdo->prepare(
            'INSERT INTO system_settings (service, setting_key, value, category, updated_at)
             VALUES (:svc, :k, :v, \'runtime\', UTC_TIMESTAMP(3))
              ON DUPLICATE KEY UPDATE value = :v2, updated_at = UTC_TIMESTAMP(3)'
        );

        $updated = [];
        foreach ($settings as $s) {
            if (!is_array($s) || !isset($s['key'])) continue;
            $updateStmt->bindValue(':svc', $service);
            $updateStmt->bindValue(':k', $s['key']);
            $updateStmt->bindValue(':v', $s['value']);
            $updateStmt->bindValue(':v2', $s['value']);
            $updateStmt->execute();
            $updated[] = $s['key'];
        }

        return Response::json(200, ['updated' => $updated]);
    }

    public function syncEnv(Request $request): Response
    {
        $service = $request->query('service') ?? 'api';
        if (!in_array($service, ['api', 'ws-vb6'], true)) {
            throw new ApiException(400, 'bad_request', 'service must be "api" or "ws-vb6"');
        }

        $stmt = $this->pdo->prepare('SELECT setting_key, value FROM system_settings WHERE service = :svc');
        $stmt->bindValue(':svc', $service);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build map: setting_key → value
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['value'];
        }

        $envPath = $service === 'api'
            ? __DIR__ . '/../../../.env'
            : __DIR__ . '/../../../../ws-vb6/.env';

        // Backup
        if (file_exists($envPath)) {
            copy($envPath, $envPath . '.bak.' . date('YmdHis'));
        }

        // Merge: preserve existing .env lines, update only matching keys
        $merged = [];
        $updatedKeys = [];
        if (file_exists($envPath)) {
            $existing = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($existing === false) $existing = [];
            foreach ($existing as $line) {
                $line = rtrim($line);
                // Preserve comments and non-KEY=VALUE lines
                if ($line === '' || $line[0] === '#') {
                    $merged[] = $line;
                    continue;
                }
                $eq = strpos($line, '=');
                if ($eq === false) {
                    $merged[] = $line;
                    continue;
                }
                $key = trim(substr($line, 0, $eq));
                if (isset($settings[$key])) {
                    $merged[] = $key . '=' . $settings[$key];
                    $updatedKeys[$key] = true;
                } else {
                    $merged[] = $line;
                }
            }
        }

        // Append new keys (not already present in .env)
        foreach ($settings as $key => $value) {
            if (!isset($updatedKeys[$key])) {
                $merged[] = $key . '=' . $value;
            }
        }

        file_put_contents($envPath, implode("\n", $merged) . "\n");

        return Response::json(200, [
            'synced' => true,
            'file'   => $envPath,
            'count'  => count($rows),
            'updated' => count($updatedKeys),
            'appended' => count($settings) - count($updatedKeys),
        ]);
    }
}
