<?php
declare(strict_types=1);

namespace App\Infrastructure\WsVb6Client;

use App\Support\Config;
use App\Support\Errors\ApiException;

/**
 * WsVb6Client: HTTP client for the WS-VB6 bridge service.
 *
 * Calls:
 *   POST /ws-vb6/v1/debts           (topic: debt.created)
 *   POST /ws-vb6/v1/stays/events    (topics: stay.closed, stay.overstay)
 *
 * On HTTP error or connectivity failure, throws ApiException(502) on network failure,
 * or ApiException($httpCode) on non-2xx response.
 *
 * Config keys (api/.env):
 *   WSVB6_BASE_URL  — e.g. http://127.0.0.1:8081/ws-vb6/v1
 *   WSVB6_API_KEY   — plaintext key (VB6-BRIDGE plaintext from seeds)
 *
 * See design.md §13, §19.
 */
final class WsVb6Client
{
    private string $baseUrl;
    private string $apiKey;
    private int    $timeout;

    public function __construct(int $timeout = 10)
    {
        $this->baseUrl = rtrim(Config::getRequired('WSVB6_BASE_URL'), '/');
        $this->apiKey  = Config::getRequired('WSVB6_API_KEY');
        $this->timeout = $timeout;
    }

    /**
     * POST /ws-vb6/v1/debts
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     * @throws ApiException on HTTP error or timeout
     */
    public function postDebts(array $payload, string $idemKey): array
    {
        return $this->post('/debts', $payload, $idemKey);
    }

    /**
     * POST /ws-vb6/v1/stays/events
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     * @throws ApiException on HTTP error or timeout
     */
    public function postStaysEvents(array $payload, string $idemKey): array
    {
        return $this->post('/stays/events', $payload, $idemKey);
    }

    /**
     * Execute a POST request to the WS-VB6 endpoint.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     * @throws ApiException on network failure or non-2xx response
     */
    private function post(string $path, array $payload, string $idemKey): array
    {
        $url  = $this->baseUrl . $path;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->apiKey,
                'Idempotency-Key: ' . $idemKey,
            ],
        ]);

        $response   = curl_exec($ch);
        $httpCode   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode === 0) {
            throw new ApiException(502, 'upstream_error', 'WS-VB6 unreachable: ' . $curlError);
        }

        if ($httpCode >= 400) {
            throw new ApiException($httpCode, 'client_error',
                "WS-VB6 returned HTTP {$httpCode}",
                ['path' => $path, 'response' => substr((string) $response, 0, 300)]
            );
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            $decoded = ['raw' => (string) $response];
        }
        $decoded['_http_status'] = $httpCode;

        return $decoded;
    }
}
