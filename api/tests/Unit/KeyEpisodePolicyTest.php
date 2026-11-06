<?php
declare(strict_types=1);

/**
 * F118.4 — KeyEpisodePolicy: fase de ficha/NFC sin estancia.
 *
 * Puro: sin BD, sin DOM, sin MQTT. Verifica pending/inside/outside/exit y que
 * la fase inside no caduca por tiempo.
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Presence\IotSession;
use App\Domain\Presence\KeyEpisodePolicy;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  {$name}\n"; }
    else { $fail++; echo "  FAIL  {$name}\n"; }
}

$keyTs = strtotime('2026-11-06 15:00:00 UTC');
$key = [
    'key_id' => 468,
    'worker_id' => 172,
    'credential_type' => 'NFC',
    'occurred_at' => '2026-11-06 15:00:00.000',
];
function ev(string $sensor, string $value, int $ts): array
{
    return ['sensor' => $sensor, 'value' => $value,
        'occurred_at' => gmdate('Y-m-d H:i:s', $ts) . '.000'];
}

check('sin acceso → idle', KeyEpisodePolicy::derive(null, [], 'ABSENT', null, $keyTs)['phase'] === 'idle');

$entryOpen = ev('PROXIMITY', 'OPEN', $keyTs + 2);
$entryClose = ev('PROXIMITY', 'CLOSED', $keyTs + 10);
$present = ev('PRESENCE', 'PRESENT', $keyTs + 5);

$inside = KeyEpisodePolicy::derive($key, [$entryOpen, $present, $entryClose], 'PRESENT', null, $keyTs + 20);
check('entrada con presencia → inside', $inside['phase'] === 'inside' && $inside['entry_confirmed'] === true);
check('entrada inside no caduca por tiempo', KeyEpisodePolicy::derive($key, [$entryOpen, $present, $entryClose], 'ABSENT', null, $keyTs + 86400)['phase'] === 'inside');

$noEntry = KeyEpisodePolicy::derive($key, [$entryOpen, $entryClose], 'ABSENT', null, $keyTs + 20);
check('cierre sin presencia → outside', $noEntry['phase'] === 'outside' && $noEntry['entry_confirmed'] === false);

$pending = KeyEpisodePolicy::derive($key, [$entryOpen], 'ABSENT', null, $keyTs + 5);
check('apertura sin cierre → pending', $pending['phase'] === 'pending');

$exitOpen = ev('PROXIMITY', 'OPEN', $keyTs + 100);
$exitClose = ev('PROXIMITY', 'CLOSED', $keyTs + 110);
$exit = KeyEpisodePolicy::derive($key, [$entryOpen, $present, $entryClose, $exitOpen, $exitClose], 'PRESENT', null, $keyTs + 115, 15);
check('salida abierta/cerrada con presencia → exit (verificando)', $exit['phase'] === 'exit');

$outside = KeyEpisodePolicy::derive($key, [$entryOpen, $present, $entryClose, $exitOpen, $exitClose], 'ABSENT', gmdate('Y-m-d H:i:s', $keyTs + 112) . '.000', $keyTs + 115, 15);
check('ausencia posterior al cierre de salida → outside', $outside['phase'] === 'outside');

$max = KeyEpisodePolicy::derive($key, [$entryOpen, $present, $entryClose, $exitOpen, $exitClose], 'PRESENT', null, $keyTs + 130, 15);
check('máximo de 15 s de salida → outside', $max['phase'] === 'outside');

$events = [$entryOpen, $present, $entryClose];
$other = KeyEpisodePolicy::derive($key, $events, 'PRESENT', null, $keyTs + 20);
check('fase determinista para los mismos datos', $inside['phase'] === $other['phase']);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
