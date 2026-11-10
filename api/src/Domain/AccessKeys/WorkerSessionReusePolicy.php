<?php
declare(strict_types=1);

namespace App\Domain\AccessKeys;

use App\Domain\Workers\WorkerSession;

/**
 * WorkerSessionReusePolicy — F123/RF-338.
 *
 * Access keys (NFC ficha and key QR) must open the door even when the worker
 * already has an active session in the room. A recent session is reused; a
 * session older than the configured TTL is treated as stale and must be closed
 * before opening a new one.
 *
 * The master worker QR (/workers/qr/validate) keeps its own `already_inside`
 * rule; this policy is only applied by AccessKeyService.
 */
final class WorkerSessionReusePolicy
{
    /** 12 h. A worker cannot stay inside a room forever without a new scan. */
    public const DEFAULT_STALE_SECONDS = 43200;

    /**
     * @param list<WorkerSession> $activeSessions Active sessions for the worker,
     *        ordered by entered_at ascending (as returned by the repository).
     * @return array{action:string, session:?WorkerSession}
     *         action: 'none' | 'reuse' | 'stale'
     */
    public static function decide(
        array $activeSessions,
        int $roomId,
        int $nowEpoch,
        int $staleSeconds = self::DEFAULT_STALE_SECONDS
    ): array {
        $match = null;
        foreach ($activeSessions as $session) {
            if ($session->roomId === $roomId) {
                // Repository order is ascending: keeping the last match
                // selects the newest active session in this room.
                $match = $session;
            }
        }

        if ($match === null) {
            return ['action' => 'none', 'session' => null];
        }

        $enteredTs = strtotime($match->enteredAt . ' UTC');
        $stale = $enteredTs !== false
            && $staleSeconds > 0
            && ($nowEpoch - $enteredTs) >= $staleSeconds;

        return [
            'action'  => $stale ? 'stale' : 'reuse',
            'session' => $match,
        ];
    }
}
