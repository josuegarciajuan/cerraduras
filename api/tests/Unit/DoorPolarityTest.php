<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Presence\DoorPolarity;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — got ' . var_export($a, true)); }

echo "Door polarity (F95/RF-162)\n";
same('sin meta → normal', DoorPolarity::value(null), 'normal');
same('normal no invierte OPEN', DoorPolarity::canonical(['door_polarity' => 'normal'], 'OPEN'), 'OPEN');
same('normal no invierte CLOSED', DoorPolarity::canonical(['door_polarity' => 'normal'], 'CLOSED'), 'CLOSED');
same('invertida cambia OPEN', DoorPolarity::canonical(['door_polarity' => 'inverted'], 'OPEN'), 'CLOSED');
same('invertida cambia CLOSED', DoorPolarity::canonical(['door_polarity' => 'inverted'], 'CLOSED'), 'OPEN');
same('valor desconocido intacto', DoorPolarity::canonical(['door_polarity' => 'inverted'], 'UNKNOWN'), 'UNKNOWN');
same('conPolarity normal', DoorPolarity::withPolarity(['x' => 1], 'normal')['door_polarity'], 'normal');
same('conPolarity inverted', DoorPolarity::withPolarity(null, 'inverted')['door_polarity'], 'inverted');
same('polaridad desconocida → normal', DoorPolarity::withPolarity([], 'zzz')['door_polarity'], 'normal');
echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
