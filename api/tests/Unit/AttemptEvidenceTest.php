<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\AttemptEvidence;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function check(string $m, bool $c): void { $c ? ok($m) : ko($m); }
function ev(string $sensor, string $value, int $sec, bool $applied = true, string $src = 'zigbee2mqtt'): array
{
    return [
        'sensor' => $sensor, 'value' => $value, 'applied' => $applied,
        'occurred_at' => '2026-10-29 12:00:' . str_pad((string) $sec, 2, '0', STR_PAD_LEFT) . '.000',
        'meta' => ['source' => $src],
    ];
}

echo "Attempt evidence (F95/RF-168)\n";
$zero = AttemptEvidence::build([ev('PROXIMITY', 'OPEN', 1, false)]);
same('solo eventos no aplicados → 0 ciclos', $zero['door']['count'], 0);
same('estado inicial → esperar apertura', $zero['door']['waiting_for'], 'open');

$one = AttemptEvidence::build([ev('PROXIMITY', 'OPEN', 1), ev('PROXIMITY', 'CLOSED', 3)]);
same('un par → 1 ciclo', $one['door']['count'], 1);
same('tras cerrar → esperar apertura', $one['door']['waiting_for'], 'open');
check('un par no completa', !$one['door']['complete']);

$open = AttemptEvidence::build([ev('PROXIMITY', 'OPEN', 1), ev('PROXIMITY', 'CLOSED', 3), ev('PROXIMITY', 'OPEN', 5)]);
same('apertura sin cierre → esperar cierre', $open['door']['waiting_for'], 'close');
same('apertura sin cierre no suma ciclo', $open['door']['count'], 1);

$three = AttemptEvidence::build([
    ev('PROXIMITY', 'OPEN', 1), ev('PROXIMITY', 'CLOSED', 3),
    ev('PROXIMITY', 'OPEN', 5), ev('PROXIMITY', 'CLOSED', 7),
    ev('PROXIMITY', 'OPEN', 9), ev('PROXIMITY', 'CLOSED', 11),
]);
same('tres pares → 3 ciclos', $three['door']['count'], 3);
check('tres pares completa', $three['door']['complete']);
same('tres pares → completo', $three['door']['waiting_for'], 'complete');

$badClosed = AttemptEvidence::build([ev('PROXIMITY', 'CLOSED', 1), ev('PROXIMITY', 'OPEN', 3), ev('PROXIMITY', 'CLOSED', 5)]);
same('cierre sin apertura se ignora', $badClosed['door']['count'], 1);

$presence = AttemptEvidence::build([
    ev('PRESENCE', 'PRESENT', 1, true), ev('PRESENCE', 'PRESENT', 11, false),
    ev('PRESENCE', 'PRESENT', 31, false),
]);
same('presencia real incluye noop', $presence['presence']['presents'], 3);
same('span de presencia', $presence['presence']['span_seconds'], 30.0);
check('presencia continua', $presence['presence']['continuous']);

$lost = AttemptEvidence::build([
    ev('PRESENCE', 'PRESENT', 1), ev('PRESENCE', 'ABSENT', 10), ev('PRESENCE', 'PRESENT', 20, false),
]);
check('pérdida de presencia no es continua', !$lost['presence']['continuous']);
same('última presencia', $lost['presence']['last_value'], 'PRESENT');

$resync = AttemptEvidence::build([ev('PRESENCE', 'PRESENT', 1, true, 'resync')]);
same('resync no cuenta', $resync['presence']['reports'], 0);

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
