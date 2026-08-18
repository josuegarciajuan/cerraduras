<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Lock;

/**
 * SimulatedLockGateway: in-memory/no-op implementation for dev and testing.
 *
 * In simulated mode the physical lock is never contacted; the gateway simply
 * records that the command was issued (the access_event is written by the
 * calling service) and returns an OK result immediately.
 *
 * See design.md §10, RF-11, TSK-080.
 */
final class SimulatedLockGateway implements LockGatewayInterface
{
    public function open(int $roomId, array $ctx = []): array
    {
        // Simulated: no external call, always succeeds.
        return [
            'ok'       => true,
            'provider' => self::PROVIDER,
            'error'    => null,
        ];
    }

    public function lock(int $roomId, array $ctx = []): array
    {
        return [
            'ok'       => true,
            'provider' => self::PROVIDER,
            'error'    => null,
        ];
    }

    public function getProvider(): string
    {
        return self::PROVIDER;
    }

    private const PROVIDER = 'SIMULATED';
}
