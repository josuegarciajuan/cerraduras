<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\RadarPresenceReport;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void
{
    if ($a === $e) { ok($m); return; }
    ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}

$at = '2026-11-03T10:10:48.105Z';

echo "Radar presence report (F111)\n";

$present = RadarPresenceReport::fromMarker('p1|mw0gscxb|1|PRESENT|push', $at);
same('PRESENT: marcador', $present['marker'] ?? null, 'p1|mw0gscxb|1|PRESENT|push');
same('PRESENT: sesión', $present['session_id'] ?? null, 'mw0gscxb');
same('PRESENT: contador', $present['report_counter'] ?? null, 1);
same('PRESENT: valor', $present['value'] ?? null, 'PRESENT');
same('PRESENT: origen', $present['origin'] ?? null, 'push');
same('PRESENT: instante', $present['received_at'] ?? null, $at);

$absent = RadarPresenceReport::fromMarker('p1|mw0gscxb|2|ABSENT|push', $at);
same('ABSENT: valor', $absent['value'] ?? null, 'ABSENT');
same('ABSENT: contador', $absent['report_counter'] ?? null, 2);

$lower = RadarPresenceReport::fromMarker('p1|s|3|absent|PUSH', $at);
same('normaliza minúsculas', $lower['value'] ?? null, 'ABSENT');
same('normaliza origen', $lower['origin'] ?? null, 'push');

same('prefijo distinto → null', RadarPresenceReport::fromMarker('v1|s|3|PRESENT|push', $at), null);
same('campos de menos → null', RadarPresenceReport::fromMarker('p1|s|3|PRESENT', $at), null);
same('campos de más → null', RadarPresenceReport::fromMarker('p1|s|3|PRESENT|push|extra', $at), null);
same('sesión vacía → null', RadarPresenceReport::fromMarker('p1||3|PRESENT|push', $at), null);
same('sesión larga → null', RadarPresenceReport::fromMarker('p1|' . str_repeat('s', 65) . '|3|PRESENT|push', $at), null);
same('contador no numérico → null', RadarPresenceReport::fromMarker('p1|s|x|PRESENT|push', $at), null);
same('contador negativo → null', RadarPresenceReport::fromMarker('p1|s|-1|PRESENT|push', $at), null);
same('valor inválido → null', RadarPresenceReport::fromMarker('p1|s|3|MAYBE|push', $at), null);
same('origen inválido → null', RadarPresenceReport::fromMarker('p1|s|3|PRESENT|query', $at), null);
same('sin instante → null', RadarPresenceReport::fromMarker('p1|s|3|PRESENT|push', ''), null);
same('marcador vacío → null', RadarPresenceReport::fromMarker('', $at), null);
same('no string → null', RadarPresenceReport::fromMarker(null, $at), null);
same('marcador gigante → null', RadarPresenceReport::fromMarker('p1|s|3|PRESENT|push' . str_repeat('x', 160), $at), null);

$zero = RadarPresenceReport::fromMarker('p1|s|0|PRESENT|push', $at);
same('contador 0 → válido', $zero['report_counter'] ?? null, 0);

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
