<?php
declare(strict_types=1);

/**
 * F125.3 — reportes inválidos del radar Zigbee.
 *
 * El broker dev puede publicar `maximum_range=-334889.04`. Ese valor no es una
 * deriva real: `apply()` no debe confirmarlo y la convergencia no debe
 * republicar la calibración en bucle. Sin MQTT, BD real ni hardware.
 *
 * Run: php tests/Unit/RadarApplyInvalidReportTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Zigbee\RadarCalibrationConvergence;
use App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function check(string $m, bool $c, string $d = ''): void { $c ? ok($m) : ko($m . ($d ? " — {$d}" : '')); }

final class RadarInvalidReportStatement extends PDOStatement
{
    public function __construct(private RadarInvalidReportPDO $pdo, private string $query)
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
        if (str_starts_with($this->query, 'SELECT id, value')) {
            return $this->pdo->nextSnapshot();
        }
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

final class RadarInvalidReportPDO extends PDO
{
    public string $metaJson;
    /** @var list<array<string,mixed>|false> */
    public array $snapshots = [];
    private int $snapshotIndex = 0;

    public function __construct(array $meta, array $snapshots = [])
    {
        $this->metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES) ?: '{}';
        $this->snapshots = $snapshots;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new RadarInvalidReportStatement($this, $query);
    }

    public function nextSnapshot(): array|false
    {
        if ($this->snapshotIndex >= count($this->snapshots)) {
            return false;
        }
        return $this->snapshots[$this->snapshotIndex++];
    }
}

function invalidSnapshot(int $id): array
{
    return [
        'id' => $id,
        'value' => 'ABSENT',
        'occurred_at' => gmdate('Y-m-d H:i:s'),
        'meta_json' => json_encode([
            'settings' => [
                'maximum_range' => -334889.04,
                'minimum_range' => 0,
                'radar_sensitivity' => 7,
                'detection_delay' => 0.1,
                'fading_time' => 5,
            ],
        ]),
    ];
}

echo "Radar apply / convergence with invalid reports (F125.3)\n";

$saved = [
    'source' => 'radar-studio-v1',
    'maximum_range' => 2.55,
    'minimum_range' => 0,
    'radar_sensitivity' => 7,
    'detection_delay' => 0.1,
    'fading_time' => 5,
];
$meta = ['provider' => 'ZIGBEE2MQTT', 'calibration' => $saved];

// ── convergencia: un reporte imposible no publica ni cuenta intentos ───
$pdo = new RadarInvalidReportPDO($meta);
$published = 0;
$convergence = new RadarCalibrationConvergence(
    $pdo,
    static function () use (&$published): array { $published++; return ['ok' => true, 'error' => null]; }
);
$state = $convergence->onReport(201, 1339, '0xa4c138718a950dcf', [
    'maximum_range' => -334889.04,
    'minimum_range' => 0,
    'radar_sensitivity' => 7,
    'detection_delay' => 0.1,
    'fading_time' => 5,
]);
same('convergencia: estado invalid_reported', $state['status'] ?? null,
    RadarCalibrationConvergence::STATUS_INVALID_REPORTED);
same('convergencia: motivo del reporte', $state['invalid_reason'] ?? null, 'maximum_range_out_of_bounds');
same('convergencia: no publica MQTT', $published, 0);
$decoded = json_decode($pdo->metaJson, true);
check('convergencia: no pisa la calibración guardada',
    is_array($decoded) && (float) ($decoded['calibration']['maximum_range'] ?? 0) === 2.55);

// ── apply: tres reportes inválidos cortan la espera sin confirmar ──────
$pdo = new RadarInvalidReportPDO($meta, [invalidSnapshot(101), invalidSnapshot(102), invalidSnapshot(103)]);
$published = 0;
$service = new ZigbeeRadarCalibrationService(
    $pdo,
    static function () use (&$published): array { $published++; return ['ok' => true, 'error' => null]; }
);
$device = ['id' => 1339, 'external_id' => '0xa4c138718a950dcf', 'meta' => $meta];
$started = microtime(true);
$result = $service->apply(201, $device, $saved, true, 15, 'test-invalid-report');
$elapsed = microtime(true) - $started;
same('apply: no confirmado', $result['applied'] ?? null, false);
same('apply: motivo específico', $result['settings_invalid_reason'] ?? null, 'maximum_range_out_of_bounds');
check('apply: warning accionable', str_starts_with((string) ($result['warning'] ?? ''), 'reported_settings_invalid:'));
same('apply: cuenta reportes inválidos', $result['invalid_reports'] ?? null, 3);
check('apply: no espera los 15 intentos', $elapsed < 3.0, 'tardó ' . number_format($elapsed, 2) . ' s');
same('apply: una sola publicación', $published, 1);

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
