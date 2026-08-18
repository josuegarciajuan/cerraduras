<?php
declare(strict_types=1);

/**
 * Unit tests for OverstayCalculator (TSK-110).
 *
 * CA:
 *   - 0 exceso (no overstay).
 *   - Negativo exceso → treated as 0.
 *   - Positivo exceso < grace → overstay=false.
 *   - Positivo exceso = grace → overstay=false (boundary: > not >=).
 *   - Positivo exceso > grace → overstay=true.
 *   - No first_entry_at → ocupacion=0, exceso=0, overstay=false.
 *   - Uses exit_detected_at when available.
 *
 * Run:
 *   php tests/Unit/OverstayCalculatorTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Debts\OverstayCalculator;
use App\Domain\Stays\Stay;

$PASS = 0; $FAIL = 0;

function ok(string $l): void  { global $PASS; $PASS++; echo "  PASS  {$l}\n"; }
function bad(string $l, string $w=''): void { global $FAIL; $FAIL++; echo "  FAIL  {$l} {$w}\n"; }

function makeStay(
    int $duracion,
    ?string $firstEntryAt,
    ?string $exitDetectedAt = null,
    int $id = 1,
    int $roomId = 1
): Stay {
    return new Stay(
        $id, $roomId, Stay::STATUS_OCCUPIED, $duracion,
        '2026-04-28 09:00:00.000',
        $firstEntryAt, $exitDetectedAt, null,
        null, null, null, null, null, null, null, null, null,
        '2026-04-28 09:00:00.000', '2026-04-28 09:00:00.000'
    );
}

$calc = new OverstayCalculator();

// Reference: now = 2026-04-28 12:00:00 UTC  (epoch)
$now = mktime(12, 0, 0, 4, 28, 2026); // UTC

echo "OverstayCalculator\n";

// T1: No first_entry_at → ocupacion=0, exceso=0, overstay=false
$stay = makeStay(60, null);
$r    = $calc->calculate($stay, 5, $now);
if ($r['ocupacion_minutos'] === 0 && $r['exceso_minutos'] === 0 && $r['overstay'] === false)
    ok('T1: no first_entry_at → ocupacion=0, no overstay');
else bad('T1: no first_entry_at', json_encode($r));

// T2: Short stay (30 min actual vs 60 contracted) → exceso=0, overstay=false
$entry = gmdate('Y-m-d H:i:s', $now - 30 * 60) . '.000'; // 30 min ago
$stay  = makeStay(60, $entry);
$r     = $calc->calculate($stay, 5, $now);
if ($r['ocupacion_minutos'] === 30 && $r['exceso_minutos'] === 0 && $r['overstay'] === false)
    ok('T2: 30 min ocupacion, 60 duracion → exceso=0, no overstay');
else bad('T2: short stay', json_encode($r));

// T3: Exact stay (60 min actual vs 60 contracted) → exceso=0, overstay=false
$entry = gmdate('Y-m-d H:i:s', $now - 60 * 60) . '.000';
$stay  = makeStay(60, $entry);
$r     = $calc->calculate($stay, 5, $now);
if ($r['exceso_minutos'] === 0 && $r['overstay'] === false)
    ok('T3: exact stay → exceso=0, no overstay');
else bad('T3: exact stay', json_encode($r));

// T4: exceso=3, grace=5 → overstay=false (3 <= 5)
$entry = gmdate('Y-m-d H:i:s', $now - 63 * 60) . '.000'; // 63 min ago, 60 contracted
$stay  = makeStay(60, $entry);
$r     = $calc->calculate($stay, 5, $now);
if ($r['exceso_minutos'] === 3 && $r['overstay'] === false)
    ok('T4: exceso=3 < grace=5 → overstay=false');
else bad('T4: within grace', json_encode($r));

// T5: exceso=5 (exact grace boundary) → overstay=false (condition is >)
$entry = gmdate('Y-m-d H:i:s', $now - 65 * 60) . '.000';
$stay  = makeStay(60, $entry);
$r     = $calc->calculate($stay, 5, $now);
if ($r['exceso_minutos'] === 5 && $r['overstay'] === false)
    ok('T5: exceso=5 = grace=5 → overstay=false (boundary: exceso > grace)');
else bad('T5: boundary grace', json_encode($r));

// T6: exceso=6, grace=5 → overstay=true
$entry = gmdate('Y-m-d H:i:s', $now - 66 * 60) . '.000';
$stay  = makeStay(60, $entry);
$r     = $calc->calculate($stay, 5, $now);
if ($r['exceso_minutos'] === 6 && $r['overstay'] === true)
    ok('T6: exceso=6 > grace=5 → overstay=true');
else bad('T6: overstay triggered', json_encode($r));

// T7: exceso=35, grace=5 → overstay=true; respects larger excess
$entry = gmdate('Y-m-d H:i:s', $now - 95 * 60) . '.000';
$stay  = makeStay(60, $entry);
$r     = $calc->calculate($stay, 5, $now);
if ($r['exceso_minutos'] === 35 && $r['overstay'] === true)
    ok('T7: exceso=35 → overstay=true');
else bad('T7: large excess', json_encode($r));

// T8: Uses exit_detected_at instead of now when set
$entry   = gmdate('Y-m-d H:i:s', $now - 120 * 60) . '.000'; // entry 120 min ago
$exitDet = gmdate('Y-m-d H:i:s', $now - 50 * 60)  . '.000'; // exit 50 min ago → 70 min occupation
$stay    = makeStay(60, $entry, $exitDet);
$r       = $calc->calculate($stay, 5, $now);
if ($r['ocupacion_minutos'] === 70 && $r['exceso_minutos'] === 10 && $r['overstay'] === true)
    ok('T8: uses exit_detected_at (70 min occupation → exceso=10)');
else bad('T8: exit_detected_at', json_encode($r));

// T9: grace=0 → any excess triggers overstay
$entry = gmdate('Y-m-d H:i:s', $now - 61 * 60) . '.000';
$stay  = makeStay(60, $entry);
$r     = $calc->calculate($stay, 0, $now);
if ($r['exceso_minutos'] === 1 && $r['overstay'] === true)
    ok('T9: grace=0, exceso=1 → overstay=true');
else bad('T9: grace=0', json_encode($r));

// T10: Response includes all required keys from contracts §2.8
$keys = ['stay_id','duracion_minutos','ocupacion_minutos','exceso_minutos','grace_minutes','overstay'];
$all  = true;
foreach ($keys as $k) {
    if (!array_key_exists($k, $r)) { $all = false; break; }
}
if ($all) ok('T10: response contains all required keys');
else bad('T10: missing keys', 'missing: ' . implode(',', array_diff($keys, array_keys($r))));

echo "\nTotal: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
