<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Sensor;

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepositoryInterface;
use App\Support\Clock;
use App\Support\Errors\BadRequestException;

/**
 * TuyaSensorIngress: normalizes raw Tuya IoT push payloads into canonical
 * presence events consumed by the domain layer (IotSessionService).
 *
 * Handles both legacy (protocol 4, statusReport) and IoT Core (protocol 1000,
 * devicePropertyMessage) Tuya message formats.
 *
 * DP mapping (MC400D door magnet, category mcs):
 *   doorcontact_state: true → PROXIMITY / OPEN
 *   doorcontact_state: false → PROXIMITY / CLOSED
 *
 * See design.md §2.5, RF-5, TSK-200.
 */
final class TuyaSensorIngress implements SensorIngressInterface
{
    private const PROVIDER = 'TUYA';

    /** Tuya DPs → canonical sensor+value mapping. */
    private const DP_MAP = [
        'doorcontact_state' => [
            'sensor' => 'PROXIMITY',
            'true'   => 'OPEN',
            'false'  => 'CLOSED',
        ],
        'presence_state' => [
            'sensor'    => 'PRESENCE',
            'presence'  => 'PRESENT',
            // 24G-Presence Sensor V3 reports transient motion as "move"; treat it
            // as presence so mmWave events are not dropped (F43).
            'move'      => 'PRESENT',
            'none'      => 'ABSENT',
        ],
    ];

    /** DPs that are purely informational (battery, RSSI, …) – don't emit events. */
    private const INFO_DPS = ['battery_percentage', 'battery_state', 'battery_value', 'rssi'];

    private DeviceRepositoryInterface $deviceRepo;

    public function __construct(DeviceRepositoryInterface $deviceRepo)
    {
        $this->deviceRepo = $deviceRepo;
    }

    /**
     * Normalize a raw Tuya push payload into the canonical presence event format.
     *
     * Accepts two payload shapes:
     *
     * A) Legacy statusReport (protocol 4):
     *    { devId, status: [{code, value, t}, …] }
     *
     * B) IoT Core devicePropertyMessage (protocol 1000):
     *    { bizData: { devId, properties: [{code, value, time}, …] }, ts }
     *
     * @param array<string,mixed> $raw
     *
     * @return array{room_id:int, sensor:string, value:string, provider:string,
     *               occurred_at:string, source_event_id:string|null,
     *               meta:array<string,mixed>|null}
     *
     * @throws BadRequestException if devId is missing or unknown.
     */
    public function normalize(array $raw): array
    {
        // --- 1. Extract device-level fields ---
        // Legacy shape
        $devId = (string) ($raw['devId'] ?? $raw['dev_id'] ?? '');

        // IoT Core shape: bizData wraps the device info
        $bizData = isset($raw['bizData']) && is_array($raw['bizData']) ? $raw['bizData'] : [];
        if ($devId === '' && !empty($bizData['devId'])) {
            $devId = (string) $bizData['devId'];
        }

        // Fallback: productKey
        if ($devId === '') {
            $devId = (string) ($raw['productKey'] ?? $raw['productId'] ?? '');
        }

        if ($devId === '') {
            throw new BadRequestException('Missing device ID in Tuya payload');
        }

        // --- 2. Resolve the room via pack (canonical F30 model: device → pack → room) ---
        $device = $this->deviceRepo->findByExternalId($devId);
        if ($device === null) {
            throw new BadRequestException(
                'Unknown Tuya device',
                ['external_id' => $devId]
            );
        }
        $roomId = ($device->packId !== null) ? $this->deviceRepo->resolveRoomId($device->id) : null;

        // Track liveness: update last_seen_at on the Tuya device
        try {
            $this->deviceRepo->updateLastSeen($device->id);
        } catch (\Throwable $e) {
            // Non-critical
        }

        // --- 3. Extract DPs ---
        // Legacy: status[] array
        $dps = $raw['status'] ?? [];

        // IoT Core: bizData.properties[] array
        if (empty($dps) && !empty($bizData['properties'])) {
            // Normalise properties[] to look like status[]
            $dps = [];
            foreach ($bizData['properties'] as $prop) {
                if (!is_array($prop)) continue;
                $dps[] = [
                    'code'  => (string) ($prop['code'] ?? ''),
                    'value' => $prop['value'] ?? null,
                    't'     => $prop['time'] ?? $prop['t'] ?? null,
                ];
            }
            // Use bizData.ts or raw.ts as fallback timestamp
            $raw['ts'] = $raw['ts'] ?? $bizData['ts'] ?? null;
        }

        if (empty($dps)) {
            // No DPs at all — could be pure heartbeat. Still update liveness.
            // Return a "noop" event that the consumer can skip.
            return [
                'room_id'         => $roomId,
                'sensor'          => 'PROXIMITY',
                'value'           => 'CLOSED', // safe default
                'provider'        => self::PROVIDER,
                'occurred_at'     => $this->tsToIso($raw['ts'] ?? $raw['t'] ?? null),
                'source_event_id' => null,
                'meta'            => [
                    '_noop'       => true,
                    'tuya_dev_id' => $devId,
                    'tuya_t'      => $raw['ts'] ?? $raw['t'] ?? null,
                ],
            ];
        }

        // --- 3b. Persist battery info from any DP (F36) ---
        $this->persistBatteryFromDps($device->id, $dps);

        // --- 4. Map first actionable DP to canonical event ---
        foreach ($dps as $dp) {
            if (!is_array($dp)) continue;

            $code  = (string) ($dp['code'] ?? '');
            $value = $dp['value'] ?? null;
            $t     = $dp['t']    ?? $raw['ts'] ?? $raw['t'] ?? null;

            // Skip info-only DPs
            $codeLower = strtolower($code);
            if (in_array($codeLower, self::INFO_DPS, true)) {
                continue;
            }

            // Look up mapping
            $map = self::DP_MAP[$code] ?? self::DP_MAP[$codeLower] ?? null;
            if ($map === null) {
                error_log('[TuyaSensorIngress] Unknown DP: ' . $code . '=' . json_encode($value));
                continue;
            }

            $sensor = $map['sensor'];

            // Resolve value: first try raw string key, then fallback to boolean
            $key = strtolower((string)$value);
            if (!isset($map[$key])) {
                if (is_bool($value)) {
                    $key = $value ? 'true' : 'false';
                } elseif (is_numeric($value)) {
                    $key = ((int)$value !== 0) ? 'true' : 'false';
                } else {
                    $key = 'false'; // unknown string → false
                }
            }
            $canonicalValue = $map[$key] ?? null;
            if ($canonicalValue === null) {
                error_log('[TuyaSensorIngress] Unmapped value for DP ' . $code . ': ' . json_encode($value));
                continue;
            }

            // Poller override (RF-30/RF-52): if the presence poller computed an
            // effective state, use it over the raw DP. `far_detection` is a radio
            // config (cm), NOT a presence signal: it no longer forces ABSENT.
            if ($sensor === 'PRESENCE' && isset($raw['_poller_effective'])) {
                $canonicalValue = $raw['_poller_effective'];
            }

            $occurredAt = $this->tsToIso($t);

            $sourceEventId = 'tuya-' . $devId . '-' . ($t ?? ((string)(time() * 1000)));

            return [
                'room_id'         => $roomId,
                'sensor'          => $sensor,
                'value'           => $canonicalValue,
                'provider'        => self::PROVIDER,
                'occurred_at'     => $occurredAt,
                'source_event_id' => $sourceEventId,
                'meta'            => [
                    'tuya_dp'      => $code,
                    'tuya_dev_id'  => $devId,
                    'tuya_raw_val' => $value,
                    // F41: raw millisecond timestamp kept for fine-grained audit
                    // (fingerprint/ordering use the second-truncated occurred_at).
                    'tuya_t'       => $t,
                ],
            ];
        }

        // No actionable DP found
        return [
            'room_id'         => $roomId,
            'sensor'          => 'PROXIMITY',
            'value'           => 'CLOSED',
            'provider'        => self::PROVIDER,
            'occurred_at'     => $this->tsToIso($raw['ts'] ?? $raw['t'] ?? null),
            'source_event_id' => null,
            'meta'            => [
                '_noop'       => true,
                'tuya_dev_id' => $devId,
                'tuya_t'      => $raw['ts'] ?? $raw['t'] ?? null,
            ],
        ];
    }

    public function getProvider(): string
    {
        return self::PROVIDER;
    }

    /**
     * Convert a Tuya 13-digit millisecond timestamp to ISO-8601 UTC.
     */
    private function tsToIso($ts): string
    {
        if (is_numeric($ts) && $ts > 0) {
            return gmdate('Y-m-d\TH:i:s\Z', (int)($ts / 1000));
        }
        return Clock::nowUtc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Extract and persist battery info from Tuya DPs (F36).
     *
     * Looks for battery_percentage, battery_state, or battery_value
     * in the DP array and persists them to the device's battery_pct column.
     *
     * @param int $deviceId
     * @param array<int,array<string,mixed>> $dps
     */
    private function persistBatteryFromDps(int $deviceId, array $dps): void
    {
        $batteryPct = null;

        foreach ($dps as $dp) {
            if (!is_array($dp)) continue;

            $code  = strtolower((string) ($dp['code'] ?? ''));
            $value = $dp['value'] ?? null;

            if ($code === 'battery_percentage' && $value !== null) {
                // Convert to int (Tuya sends as int or numeric string)
                $pct = is_numeric($value) ? (int) $value : null;
                if ($pct !== null && $pct >= 0 && $pct <= 100) {
                    $batteryPct = $pct;
                    break; // Found the primary value, stop
                }
            }
        }

        // Only persist if we found a valid battery percentage
        // (battery_state and battery_value are secondary, only persist if percentage available)
        if ($batteryPct !== null) {
            try {
                $this->deviceRepo->updateBattery($deviceId, $batteryPct);
            } catch (\Throwable $e) {
                error_log('[TuyaSensorIngress] Battery persist failed: ' . $e->getMessage());
            }
        }
    }
}
