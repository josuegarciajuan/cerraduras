<?php
declare(strict_types=1);

namespace App\Domain\Devices;

/**
 * TuyaCreditsClassifier — pure classification of a Tuya Cloud API response to
 * answer one question: does the account still have IoT Core credits?
 *
 * It is intentionally side-effect free (no network, no DB, no clock) so it can
 * be unit-tested and reused by both the read-only diagnostic endpoint
 * (GET /dashboard-api/tuya-quota) and tuyaPresenceApi()'s backoff decision.
 *
 * Tuya returns HTTP 200 with a logical failure for an exhausted trial, e.g.:
 *   {"success":false,"code":28841004,"msg":"IoT Core trial quota is exhausted."}
 *
 * Contract (Fase 53, contracts.md §Tuya quota):
 *   - success=true                                 -> credits=ok, category=ok
 *   - HTTP 429 | code 28841004/28841105/28841107 |
 *     msg matches quota|exhaust|limit              -> credits=exhausted, category=quota
 *   - code 2008 | msg contains "offline"           -> credits=ok, category=offline
 *     (the quota works; the *device* is unreachable)
 *   - any other / empty response                   -> credits=unknown, category=unknown
 *
 * `credits` answers "can we spend IoT Core credits?" while `category` explains
 * WHY, so the dashboard can tell a real quota problem from an offline device.
 */
final class TuyaCreditsClassifier
{
    /** @var list<int> Tuya IoT Core quota-exhaustion error codes. */
    private const QUOTA_CODES = [28841004, 28841105, 28841107];

    /** Tuya error code for "device offline / not connected". */
    private const OFFLINE_CODE = 2008;

    /**
     * Classify a Tuya response.
     *
     * @param int                  $httpCode HTTP status returned by the call (or a
     *                                       synthetic 429/502 from the local guard).
     * @param array<string,mixed>  $data     decoded JSON body ([] when none).
     * @return array{credits:'ok'|'exhausted'|'unknown', category:'ok'|'quota'|'offline'|'unknown'}
     */
    public static function classifyTuyaCredits(int $httpCode, array $data): array
    {
        if ((bool) ($data['success'] ?? false)) {
            return ['credits' => 'ok', 'category' => 'ok'];
        }

        $rawCode = $data['code'] ?? null;
        $codeNum = (is_int($rawCode) || (is_string($rawCode) && is_numeric($rawCode)))
            ? (int) $rawCode
            : null;

        $rawMsg = $data['msg'] ?? null;
        $msg    = is_string($rawMsg) ? $rawMsg : (is_scalar($rawMsg) ? (string) $rawMsg : '');
        $needle = strtolower($msg);

        if ($httpCode === 429
            || ($codeNum !== null && in_array($codeNum, self::QUOTA_CODES, true))
            || ($needle !== '' && (
                str_contains($needle, 'quota')
                || str_contains($needle, 'exhaust')
                || str_contains($needle, 'limit')
            ))
        ) {
            return ['credits' => 'exhausted', 'category' => 'quota'];
        }

        if ($codeNum === self::OFFLINE_CODE
            || ($needle !== '' && str_contains($needle, 'offline'))
        ) {
            return ['credits' => 'ok', 'category' => 'offline'];
        }

        return ['credits' => 'unknown', 'category' => 'unknown'];
    }
}
