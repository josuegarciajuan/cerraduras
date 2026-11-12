<?php
declare(strict_types=1);

/**
 * F125.4 — confirmación directa del radar por MQTT.
 *
 * `apply()` debe poder confirmar sin esperar a que el spool persista el evento.
 * Sin MQTT real, BD real ni hardware.
 *
 * Run: php tests/Unit/RadarDirectConfirmationTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function check(string $m, bool $c, string $d = ''): void { $c ? ok($m) : ko($m . ($d ? " — {$d}" : '')); }

final class RadarDirectStatement extends PDOStatement
{
    public function __construct(private RadarDirectPDO $pdo, private string $query)
    {
    }

    public function execute(?array $params = null): bool
    {
        if (str_starts_with($this->query, 'UPDATE devices')) {
            $meta = is_array($params) ? ($params[':meta'] ?? null) : null;
            if (is_string($meta)) {
                $this->pdo->metaJson = $meta;
            }
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return false;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if (str_starts_with($this->query, 'SELECT MAX(id)')) {
            return 100;
        }
        if (str_starts_with($this->query, 'SELECT meta_json')) {
            return $this->pdo->metaJson;
        }
        return false;
    }
}

final class RadarDirectPDO extends PDO
{
    public string $metaJson;

    public function __construct(array $meta)
    {
        $this->metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new RadarDirectStatement($this, $query);
    }
}

echo "Radar direct confirmation (F125.4)\n";

$commands = [
    'maximum_range' => 2.55,
    'minimum_range' => 0,
    'radar_sensitivity' => 7,
    'detection_delay' => 0.1,
    'fading_time' => 5,
];
$meta = ['provider' => 'ZIGBEE2MQTT'];
$pdo = new RadarDirectPDO($meta);
$published = 0;
$reads = 0;
$service = new ZigbeeRadarCalibrationService(
    $pdo,
    static function () use (&$published): array { $published++; return ['ok' => true, 'error' => null]; },
    null,
    static function (array $device, int $timeout) use (&$reads, $commands): array {
        $reads++;
        if ($reads === 1) {
            return ['ok' => true, 'settings' => ['maximum_range' => 9.5] + $commands,
                'received_at' => '2026-11-12T12:00:00Z', 'presence' => false];
        }
        return ['ok' => true, 'settings' => $commands,
            'received_at' => '2026-11-12T12:00:01Z', 'presence' => false];
    }
);

$device = ['id' => 1339, 'external_id' => '0xa4c138718a950dcf', 'meta' => $meta];
$started = microtime(true);
$result = $service->apply(201, $device, $commands, true, 15, 'test-direct');
$elapsed = microtime(true) - $started;

same('apply: confirmado', $result['applied'] ?? null, true);
same('apply: confirmado por directo', $result['confirmed_by'] ?? null, 'direct_push');
same('apply: lectura directa reintenta hasta coincidir', $reads, 2);
check('apply: no espera al spool', $elapsed < 2.0, 'tardó ' . number_format($elapsed, 2) . ' s');
same('apply: persiste la calibración', $result['persisted'] ?? null, true);
same('apply: status usa la lectura directa',
    (float) ($result['status']['settings']['maximum_range'] ?? 0), 2.55);
same('apply: status fresco', $result['status']['stale'] ?? null, false);
same('apply: una sola publicación', $published, 1);
$saved = json_decode($pdo->metaJson, true);
same('apply: meta guarda calibration.maximum_range',
    (float) ($saved['calibration']['maximum_range'] ?? 0), 2.55);

// F125.4.1: dos lecturas directas inválidas cortan sin esperar al spool.
$pdo2 = new RadarDirectPDO($meta);
$published2 = 0;
$reads2 = 0;
$service2 = new ZigbeeRadarCalibrationService(
    $pdo2,
    static function () use (&$published2): array { $published2++; return ['ok' => true, 'error' => null]; },
    null,
    static function (array $device, int $timeout) use (&$reads2): array {
        $reads2++;
        return ['ok' => true,
            'settings' => ['maximum_range' => -334889.04, 'minimum_range' => 0,
                'radar_sensitivity' => 7, 'detection_delay' => 0.1, 'fading_time' => 5],
            'received_at' => '2026-11-12T12:00:00Z', 'presence' => false];
    }
);
$started2 = microtime(true);
$result2 = $service2->apply(201, ['id' => 1339, 'external_id' => '0xa4c138718a950dcf', 'meta' => $meta],
    $commands, true, 15, 'test-direct-invalid');
$elapsed2 = microtime(true) - $started2;
same('directo inválido: no aplica', $result2['applied'] ?? null, false);
same('directo inválido: motivo', $result2['settings_invalid_reason'] ?? null, 'maximum_range_out_of_bounds');
same('directo inválido: dos lecturas', $reads2, 2);
check('directo inválido: corta rápido', $elapsed2 < 3.0, 'tardó ' . number_format($elapsed2, 2) . ' s');
same('directo inválido: una publicación', $published2, 1);

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
