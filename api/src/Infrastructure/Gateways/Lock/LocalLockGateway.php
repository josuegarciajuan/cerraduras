<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Lock;

/**
 * LocalLockGateway: no-op implementation for ESP32 self-driven relay.
 *
 * In the LOCAL provider model, the physical lock actuator (relay connected
 * to the ESP32's GPIO16) is driven by the ESP32 firmware itself after
 * receiving HTTP 200 from POST /qr/validate. The API simply records the
 * access_event with provider=LOCAL and does NOT emit any network command.
 *
 * The fail-safe (normally-closed) latch re-closes mechanically when the
 * relay de-energises, so lock() is also a no-op.
 *
 * open()  → {ok:true, provider:'LOCAL'}  (always succeeds)
 * lock()  → {ok:true, provider:'LOCAL'}  (always succeeds)
 *
 * See design.md §2.8, TSK-222, F20.
 */
final class LocalLockGateway implements LockGatewayInterface
{
    private const PROVIDER = 'LOCAL';

    public function open(int $roomId, array $ctx = []): array
    {
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
}
