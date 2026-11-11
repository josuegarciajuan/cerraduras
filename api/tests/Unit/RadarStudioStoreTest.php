<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Sensors\RadarStudioStore;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function check(string $m, bool $c): void { $c ? ok($m) : ko($m); }

echo "Radar Studio store\n";

$dir = sys_get_temp_dir() . '/radar-studio-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
$store = new RadarStudioStore($dir);
check('sin estado → null', $store->load(14) === null);

$store->save(14, ['action' => 'soak', 'started_at' => '2026-11-11T10:00:00Z']);
$loaded = $store->load(14);
check('estado guardado', is_array($loaded));
same('acción guardada', $loaded['action'] ?? null, 'soak');
same('sala aislada', $store->load(15), null);

$returned = $store->withLock(14, static function (?array $state): string {
    return ($state['action'] ?? 'none') . ':locked';
});
same('withLock recibe el estado', $returned, 'soak:locked');

$store->save(14, ['action' => 'auto_range']);
$store->clear(14);
check('clear borra el estado', $store->load(14) === null);

// Limpieza.
@unlink($dir . '/room-14.json.lock');
@rmdir($dir);

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
