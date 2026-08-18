<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Switch;

use App\Domain\Devices\Device;

/**
 * SwitchGatewayInterface: abstraction over a WiFi-controlled smart switch/relay
 * (EAWCBT-J Smart Electricity Protector via Tuya IoT Platform).
 *
 * Both turnOn() and turnOff() return a result array:
 *   [
 *     'ok'       => bool,
 *     'provider' => 'SIMULATED' | 'TUYA',
 *     'error'    => string|null,
 *   ]
 *
 * The gateway does NOT write access_events; that responsibility belongs to the
 * calling service.
 *
 * See design.md §2.4b, RF-16, TSK-SW3.
 */
interface SwitchGatewayInterface
{
    /**
     * Turn the switch ON (energize output).
     *
     * @param int $roomId
     * @param array<string,mixed> $ctx Extra context (e.g. timeout overrides).
     * @return array{ok:bool, provider:string, error:string|null}
     */
    public function turnOn(int $roomId, array $ctx = []): array;

    /**
     * Turn the switch OFF (de-energize output).
     *
     * @param int $roomId
     * @param array<string,mixed> $ctx
     * @return array{ok:bool, provider:string, error:string|null}
     */
    public function turnOff(int $roomId, array $ctx = []): array;

    /**
     * Turn the switch ON for a given Device (pack-based lookup, no room needed).
     *
     * @return array{ok:bool, provider:string, error:string|null}
     */
    public function turnOnForDevice(Device $device, array $ctx = []): array;

    /**
     * Turn the switch OFF for a given Device (pack-based lookup, no room needed).
     *
     * @return array{ok:bool, provider:string, error:string|null}
     */
    public function turnOffForDevice(Device $device, array $ctx = []): array;

    /**
     * Return the provider identifier string used for audit/tracing.
     */
    public function getProvider(): string;
}
