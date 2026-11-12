<?php
declare(strict_types=1);

/**
 * F125.4 — estado estable del radar ante reportes inválidos intercalados.
 *
 * Si el último push trae `maximum_range=-334889.04` pero hay una lectura
 * válida reciente, `status()` usa la válida y deja el inválido como aviso.
 * Sin lectura válida reciente, mantiene `invalid_reported`.
 *
 * Sin BD ni MQTT reales.
 *
 * Run: php tests/Unit/RadarStatusStabilityTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function check(string $m, bool $c, string $d = ''): void { $c ? ok($m) : ko($m . ($d ? " — {$d}" : '')); }

final class RadarStatusStatement extends PDOStatement
{
    public function __construct(private RadarStatusPDO $pdo, private string $query)
    {
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->query, 'ORDER BY id DESC LIMIT 100')) {
            return $this->pdo->nextValidRow();
        }
        if (str_contains($this->query, 'ORDER BY id DESC LIMIT 1')) {
            return $this->pdo->latestRow;
        }
        return false;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return false;
    }
}

final class RadarStatusPDO extends PDO
{
    public ?array $latestRow = null;
    /** @var list<array<string,mixed>> */
    public array $validRows = [];
    private int $validIndex = 0;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new RadarStatusStatement($this, $query);
    }

    public function nextValidRow(): array|false
    {
        if ($this->validIndex >= count($this->validRows)) {
            return false;
        }
        return $this->validRows[$this->validIndex++];
    }
}

function row(int $id, float $max, int $ageSeconds): array
{
    return [
        'id' => $id,
        'value' => 'PRESENT',
        'occurred_at' => gmdate('Y-m-d H:i:s', time() - $ageSeconds),
        'meta_json' => json_encode([
            'settings' => [
                'maximum_range' => $max,
                'minimum_range' => 0,
                'radar_sensitivity' => 7,
                'detection_delay' => 0.1,
                'fading_time' => 5,
            ],
        ]),
    ];
}

echo "Radar status stability (F125.4)\n";

$saved = [
    'source' => 'radar-studio-v1',
    'maximum_range' => 2.55,
    'minimum_range' => 0,
    'radar_sensitivity' => 7,
    'detection_delay' => 0.1,
    'fading_time' => 5,
];
$device = [
    'id' => 1339,
    'external_id' => '0xa4c138718a950dcf',
    'label' => 'Dev',
    'meta' => ['provider' => 'ZIGBEE2MQTT', 'calibration' => $saved],
];

// Último push inválido + lectura válida reciente → estado estable aplicado.
$pdo = new RadarStatusPDO();
$pdo->latestRow = row(200, -334889.04, 2);
$pdo->validRows = [row(199, 2.55, 4), row(198, 9.5, 8)];
$status = (new ZigbeeRadarCalibrationService($pdo))->status(201, $device);
same('status: detecta reporte inválido', $status['reported_invalid'] ?? null, true);
same('status: motivo del inválido', $status['reported_invalid_reason'] ?? null, 'maximum_range_out_of_bounds');
same('status: usa la última lectura válida', $status['using_valid_snapshot'] ?? null, true);
same('status: máximo estable', (float) ($status['settings']['maximum_range'] ?? 0), 2.55);
same('status: sin motivo inválido en la lectura elegida', $status['settings_invalid_reason'] ?? null, null);
same('status: fresco', $status['stale'] ?? null, false);
same('status: guarda el último inválido para diagnóstico',
    (float) ($status['latest_reported_settings']['maximum_range'] ?? 0), -334889.04);
same('status: detecta configuraciones contradictorias', $status['reported_unstable'] ?? null, true);
same('status: expone las variantes', count($status['reported_variants'] ?? []), 2);

// Solo reportes inválidos recientes → invalid_reported estable.
$pdo2 = new RadarStatusPDO();
$pdo2->latestRow = row(300, -334889.04, 2);
$pdo2->validRows = [row(299, -334889.04, 4)];
$status2 = (new ZigbeeRadarCalibrationService($pdo2))->status(201, $device);
same('status sin válido: reporte inválido', $status2['reported_invalid'] ?? null, true);
same('status sin válido: no usa snapshot válido', $status2['using_valid_snapshot'] ?? null, false);
same('status sin válido: conserva el imposible',
    (float) ($status2['settings']['maximum_range'] ?? 0), -334889.04);
same('status sin válido: motivo',
    $status2['settings_invalid_reason'] ?? null, 'maximum_range_out_of_bounds');
same('status sin válido: no es inestable', $status2['reported_unstable'] ?? null, false);

// Última lectura válida + variante antigua fuera de la ventana → estable.
$pdo3 = new RadarStatusPDO();
$pdo3->latestRow = row(400, 2.55, 2);
$pdo3->validRows = [row(399, 2.55, 2), row(398, 2.55, 4), row(397, 2.55, 40)];
$status3 = (new ZigbeeRadarCalibrationService($pdo3))->status(201, $device);
same('status estable: aplicado', (float) ($status3['settings']['maximum_range'] ?? 0), 2.55);
same('status estable: no inestable', $status3['reported_unstable'] ?? null, false);

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
