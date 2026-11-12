<?php
declare(strict_types=1);

/**
 * F125.4 — la convergencia no entra en ping-pong con reportes alternos.
 *
 * Un reporte aplicado tras publicar NO resetea `attempts`; se exige una racha
 * estable. Sin MQTT ni BD reales.
 *
 * Run: php tests/Unit/RadarConvergenceStabilityTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Zigbee\RadarCalibrationConvergence;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }

final class RadarConvStabilityStatement extends PDOStatement
{
    public function __construct(private RadarConvStabilityPDO $pdo, private string $query)
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

final class RadarConvStabilityPDO extends PDO
{
    public string $metaJson;

    public function __construct(array $meta)
    {
        $this->metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new RadarConvStabilityStatement($this, $query);
    }
}

function settings(float $max): array
{
    return [
        'maximum_range' => $max,
        'minimum_range' => 0,
        'radar_sensitivity' => 7,
        'detection_delay' => 0.1,
        'fading_time' => 5,
    ];
}

putenv('RADAR_RECONCILE_SETTLE_REPORTS=3');
putenv('RADAR_RECONCILE_MAX_ATTEMPTS=5');
putenv('RADAR_RECONCILE_COOLDOWN_SECONDS=1');

echo "Radar convergence stability (F125.4)\n";

$pdo = new RadarConvStabilityPDO([
    'provider' => 'ZIGBEE2MQTT',
    'calibration' => settings(2.55),
]);
$published = 0;
$conv = new RadarCalibrationConvergence(
    $pdo,
    static function () use (&$published): array { $published++; return ['ok' => true, 'error' => null]; }
);

$mismatch = $conv->onReport(201, 1339, '0xa4c138718a950dcf', settings(9.5));
same('mismatch publica', $published, 1);
same('mismatch queda convergiendo', $mismatch['status'] ?? null, RadarCalibrationConvergence::STATUS_CONVERGING);
same('mismatch incrementa intentos', $mismatch['attempts'] ?? null, 1);

$a1 = $conv->onReport(201, 1339, '0xa4c138718a950dcf', settings(2.55));
same('aplicado 1 no resetea intentos', $a1['attempts'] ?? null, 1);
same('aplicado 1 sigue convergiendo', $a1['status'] ?? null, RadarCalibrationConvergence::STATUS_CONVERGING);
same('aplicado 1 racha', $a1['applied_streak'] ?? null, 1);
same('no publica en aplicados', $published, 1);

$a2 = $conv->onReport(201, 1339, '0xa4c138718a950dcf', settings(2.55));
same('aplicado 2 racha', $a2['applied_streak'] ?? null, 2);
same('aplicado 2 sigue convergiendo', $a2['status'] ?? null, RadarCalibrationConvergence::STATUS_CONVERGING);

$a3 = $conv->onReport(201, 1339, '0xa4c138718a950dcf', settings(2.55));
same('aplicado 3 racha', $a3['applied_streak'] ?? null, 3);
same('aplicado 3 confirma', $a3['status'] ?? null, RadarCalibrationConvergence::STATUS_APPLIED);
same('aplicado 3 resetea intentos', $a3['attempts'] ?? null, 0);
same('sigue sin publicar', $published, 1);

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
