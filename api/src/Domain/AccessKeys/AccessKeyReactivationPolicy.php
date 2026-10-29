<?php
declare(strict_types=1);

namespace App\Domain\AccessKeys;

/**
 * AccessKeyReactivationPolicy — pure rules for reactivating a revoked key (F99).
 *
 * Reactivation is intentionally narrower than editing:
 *   - only REVOKED keys can be reactivated;
 *   - the key must not be expired;
 *   - a personal key requires its worker to be active;
 *   - an NFC key requires its card to remain available (or still bound to it).
 *
 * No DB, clock or network access: callers pass the already-loaded key row,
 * the worker state and the card row.
 */
final class AccessKeyReactivationPolicy
{
    /**
     * @param array<string,mixed>      $key         access_keys row (hydrated)
     * @param bool|null                $workerActive null when the key is not personal
     * @param array<string,mixed>|null $card         nfc_cards row, null when missing
     * @return array{ok:bool, code:string|null, message:string|null}
     */
    public static function evaluate(array $key, ?bool $workerActive, ?array $card, int $now): array
    {
        if (($key['status'] ?? '') !== 'REVOKED') {
            return self::deny('not_revoked', 'Only deactivated keys can be reactivated');
        }

        $validTs = strtotime((string) ($key['valid_until'] ?? '') . ' UTC');
        if ($validTs === false || $validTs <= $now) {
            return self::deny('key_expired', 'Expired keys cannot be reactivated');
        }

        if (($key['owner_type'] ?? '') === 'WORKER' && $workerActive !== true) {
            return self::deny('worker_inactive', 'The key owner is inactive');
        }

        if (($key['key_type'] ?? '') === 'NFC') {
            $cardId = $key['nfc_card_id'] ?? null;
            if ($cardId === null || $card === null) {
                return self::deny('nfc_card_unavailable', 'The NFC card no longer exists');
            }
            $status = (string) ($card['status'] ?? '');
            $boundTo = $card['current_key_id'] ?? null;
            $available = $status === 'AVAILABLE'
                || ($status === 'ASSIGNED' && $boundTo !== null && (int) $boundTo === (int) ($key['id'] ?? 0));
            if (!$available) {
                return self::deny('nfc_card_unavailable', 'The NFC card is not available');
            }
        }

        return ['ok' => true, 'code' => null, 'message' => null];
    }

    /** @return array{ok:bool, code:string, message:string} */
    private static function deny(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
