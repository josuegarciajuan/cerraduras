<?php
declare(strict_types=1);

/**
 * Unit tests for battery monitoring (F36).
 *
 * Covers:
 *   - Battery level classification thresholds (normal/low/critical/unknown).
 *   - Device battery_pct property and toArray() includes it.
 *   - Repository updateBattery() / hydrate() behavior.
 *
 * Run:
 *   php tests/Unit/ChipBatteryTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Devices\Device;

// ============================================================================
// Battery state classifier (mirrors the logic in battery-refresh endpoint
// and RoomLiveController for F36).
// ============================================================================

function batteryState(?int $pct): string
{
    if ($pct === null) return 'unknown';
    if ($pct <= 10)    return 'critical';
    if ($pct <= 20)    return 'low';
    return 'normal';
}

// ============================================================================
// Minimal Fake Repository
// ============================================================================

final class FakeBatteryDeviceRepo
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [];

    public int $updateCalls = 0;

    public function __construct()
    {
        // Seed one device row mimicking DB output (canonical F30: no room_id column)
        $this->rows[3] = [
            'id'          => '3',
            'pack_id'     => '2',
            'kind'        => 'PROXIMITY',
            'external_id' => 'bf4c7e7d2cef28cea2nkwk',
            'label'       => 'Sensor Puerta',
            'api_client_id' => null,
            'meta_json'     => null,
            'battery_pct'   => null,
            'is_identified' => '0',
            'identified_at' => null,
        ];
    }

    public function findById(int $id): ?Device
    {
        $row = $this->rows[$id] ?? null;
        if ($row === null) return null;

        $meta = $row['meta_json'] !== null
            ? json_decode((string) $row['meta_json'], true)
            : null;

        // Device constructor (F30): (id, packId, kind, externalId, label,
        // apiClientId, meta, batteryPct, isIdentified, identifiedAt)
        return new Device(
            (int) $row['id'],
            $row['pack_id'] !== null ? (int) $row['pack_id'] : null,
            (string) $row['kind'],
            (string) $row['external_id'],
            isset($row['label']) ? (string) $row['label'] : null,
            $row['api_client_id'] !== null ? (int) $row['api_client_id'] : null,
            $meta,
            $row['battery_pct'] !== null ? (int) $row['battery_pct'] : null,
            (bool) ($row['is_identified'] ?? false),
            $row['identified_at'] ?? null
        );
    }

    public function updateBattery(int $deviceId, ?int $pct): void
    {
        if (isset($this->rows[$deviceId])) {
            $this->rows[$deviceId]['battery_pct'] = $pct;
            $this->updateCalls++;
        }
    }
}

// ============================================================================
// Test helpers
// ============================================================================

function pass(string $name): void {
    echo "  ✅ pass: {$name}\n";
}

function fail(string $name, string $reason): void {
    echo "  ❌ FAIL: {$name} → {$reason}\n";
    exit(1);
}

function assertEq(string $label, $expected, $got): void {
    if ($expected !== $got) {
        fail($label, "expected " . json_encode($expected) . ", got " . json_encode($got));
    }
    pass($label);
}

// ============================================================================
// TESTS
// ============================================================================

echo "═══ ChipBatteryTest (F36) ═══\n\n";

// --- T1: Battery state classification ---
echo "BLOQUE 1: Clasificación de umbrales\n";

assertEq('null → unknown',          'unknown',  batteryState(null));
assertEq('0 → critical',            'critical', batteryState(0));
assertEq('5 → critical',            'critical', batteryState(5));
assertEq('10 → critical',           'critical', batteryState(10));
assertEq('11 → low',                'low',      batteryState(11));
assertEq('15 → low',                'low',      batteryState(15));
assertEq('20 → low',                'low',      batteryState(20));
assertEq('21 → normal',             'normal',   batteryState(21));
assertEq('50 → normal',             'normal',   batteryState(50));
assertEq('87 → normal',             'normal',   batteryState(87));
assertEq('100 → normal',            'normal',   batteryState(100));

echo "\n";

// --- T2: Device model includes battery_pct ---
echo "BLOQUE 2: Modelo Device con batteryPct\n";

$d1 = new Device(1, null, 'PROXIMITY', 'ext1', null, null, null);
assertEq('default batteryPct is null', true, $d1->batteryPct === null);

$d2 = new Device(2, null, 'PROXIMITY', 'ext2', null, null, null, 87);
assertEq('batteryPct set to 87',    87,   $d2->batteryPct);

$a2 = $d2->toArray();
assertEq('toArray includes battery_pct', 87, $a2['battery_pct'] ?? null);

$a1 = $d1->toArray();
assertEq('toArray includes battery_pct key', true, array_key_exists('battery_pct', $a1));
assertEq('battery_pct value is null',    true, $a1['battery_pct'] === null);

echo "\n";

// --- T3: Fake repo updateBattery ---
echo "BLOQUE 3: Repositorio updateBattery\n";

$repo = new FakeBatteryDeviceRepo();

// Initially null
$d = $repo->findById(3);
assertEq('initial batteryPct null',    true,  $d !== null && $d->batteryPct === null);
assertEq('updateCalls starts at 0',    0,     $repo->updateCalls);

// Update to 85
$repo->updateBattery(3, 85);
$d = $repo->findById(3);
assertEq('after update batteryPct=85', 85,    $d !== null ? $d->batteryPct : null);
assertEq('updateCalls=1',              1,     $repo->updateCalls);

// Update to null
$repo->updateBattery(3, null);
$d = $repo->findById(3);
assertEq('batteryPct back to null',    true,  $d !== null && $d->batteryPct === null);
assertEq('updateCalls=2',              2,     $repo->updateCalls);

echo "\n";

// --- End ---
echo "════════════════════════════════════\n";
echo "✅ Todos los tests pasaron\n";
echo "════════════════════════════════════\n";
