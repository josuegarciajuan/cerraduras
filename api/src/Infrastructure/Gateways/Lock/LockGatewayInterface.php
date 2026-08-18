<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Lock;

/**
 * LockGatewayInterface: abstraction over the physical lock actuator.
 *
 * Both open() and lock() return a result array:
 *   [
 *     'ok'       => bool,
 *     'provider' => 'SIMULATED' | 'TUYA',
 *     'error'    => string|null,   // null on success
 *   ]
 *
 * The gateway does NOT write access_events; that responsibility belongs to the
 * calling service so it can record the correlation_id, stay_id, and reason
 * with full context.
 *
 * See design.md §2.4 and §10.
 */
interface LockGatewayInterface
{
    /**
     * Send an "open" command to the lock for the given room.
     *
     * @param array<string,mixed> $ctx  Extra context forwarded to the provider (e.g. timeout overrides).
     * @return array{ok:bool, provider:string, error:string|null}
     */
    public function open(int $roomId, array $ctx = []): array;

    /**
     * Send a "lock" command (close/secure) to the lock for the given room.
     *
     * @param array<string,mixed> $ctx
     * @return array{ok:bool, provider:string, error:string|null}
     */
    public function lock(int $roomId, array $ctx = []): array;

    /**
     * Return the provider identifier string used in access_events.provider.
     */
    public function getProvider(): string;
}
