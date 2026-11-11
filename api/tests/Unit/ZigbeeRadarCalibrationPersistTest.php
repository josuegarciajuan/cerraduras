<?php
declare(strict_types=1);

/**
 * F125.1.1 — regresión de `persistCalibration()`.
 *
 * Comprueba:
 *   1. Por reflexión, que la firma incluye `$extra = []` (el bug original era
 *      usar `$extra` en el cuerpo sin declararlo).
 *   2. Por comportamiento, con un PDO falso (sin driver SQLite): que se guarda
 *      la calibración con y sin `$extra`, sin warnings ni TypeError.
 *
 * No requiere MQTT, BD real ni hardware.
 *
 * Run: php tests/Unit/ZigbeeRadarCalibrationPersistTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function check(string $m, bool $c, string $d = ''): void { $c ? ok($m) : ko($m . ($d ? " — {$d}" : '')); }

/** PDOStatement falso: simula SELECT/UPDATE sobre `devices.meta_json`. */
final class FakePersistStatement extends PDOStatement
{
    private string|false $result = false;

    public function __construct(private FakePersistPDO $pdo, private string $query)
    {
    }

    public function execute(?array $params = null): bool
    {
        if (str_starts_with($this->query, 'SELECT meta_json')) {
            $this->result = $this->pdo->metaJson;
            return true;
        }
        if (str_starts_with($this->query, 'UPDATE devices')) {
            $meta = is_array($params) ? ($params[':meta'] ?? null) : null;
            if (is_string($meta)) {
                $this->pdo->metaJson = $meta;
                $this->pdo->lastUpdateMeta = $meta;
            }
            return true;
        }
        $this->result = false;
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->result;
    }

    public function rowCount(): int
    {
        return $this->result === false ? 0 : 1;
    }
}

/** PDO falso mínimo; no llama al constructor del driver. */
final class FakePersistPDO extends PDO
{
    public string $metaJson = '{"provider":"ZIGBEE2MQTT"}';
    public ?string $lastUpdateMeta = null;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new FakePersistStatement($this, $query);
    }
}

echo "Zigbee radar calibration persist (F125.1.1)\n";

// 1) La firma debe declarar $extra.
$ref = new ReflectionMethod(ZigbeeRadarCalibrationService::class, 'persistCalibration');
$names = array_map(static fn (ReflectionParameter $p): string => $p->getName(), $ref->getParameters());
$idx = array_search('extra', $names, true);
check('firma incluye $extra', $idx !== false, 'parámetros: ' . implode(',', $names));
if ($idx !== false) {
    $param = $ref->getParameters()[$idx];
    check('$extra tiene valor por defecto', $param->isDefaultValueAvailable());
    same('$extra por defecto []', $param->getDefaultValue(), []);
}

// 2) Comportamiento con PDO falso.
$pdo = new FakePersistPDO();
$service = new ZigbeeRadarCalibrationService($pdo);
$device = [
    'id' => 1339,
    'external_id' => '0xa4c138718a950dcf',
    'meta' => ['provider' => 'ZIGBEE2MQTT'],
];
$settings = [
    'maximum_range' => 3.0,
    'minimum_range' => 0.0,
    'radar_sensitivity' => 7,
    'detection_delay' => 0.1,
    'fading_time' => 15.5,
];

$caught = null;
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
try {
    $savedBase = $service->persistCalibration($device, $settings, '2026-11-11T10:00:00Z');
    $savedWizard = $service->persistCalibration(
        $device,
        $settings,
        '2026-11-11T10:00:00Z',
        'radar-studio-guided-v1',
        ['wizard' => ['status' => 'done', 'steps' => 8]]
    );
} catch (Throwable $e) {
    $caught = $e;
} finally {
    restore_error_handler();
}

same('sin warning ni TypeError', $caught, null);
if ($caught !== null) {
    echo '    ' . get_class($caught) . ': ' . $caught->getMessage() . "\n";
}

if ($caught === null) {
    same('source por defecto', $savedBase['source'], 'sensores-f104-auto');
    same('máximo guardado', $savedBase['maximum_range'], 3.0);
    same('mínimo 0 guardado', $savedBase['minimum_range'], 0.0);
    same('sensibilidad guardada', $savedBase['radar_sensitivity'], 7.0);
    check('sin extra no añade wizard', !array_key_exists('wizard', $savedBase));

    same('source del wizard', $savedWizard['source'], 'radar-studio-guided-v1');
    same('extra wizard status', $savedWizard['wizard']['status'] ?? null, 'done');
    same('extra wizard steps', $savedWizard['wizard']['steps'] ?? null, 8);
    same('extra no pierde el máximo', $savedWizard['maximum_range'], 3.0);
    same('extra no pierde la sensibilidad', $savedWizard['radar_sensitivity'], 7.0);
}

$meta = json_decode((string) $pdo->lastUpdateMeta, true);
check('meta_json válido', is_array($meta));
same('calibración persistida', $meta['calibration']['source'] ?? null, 'radar-studio-guided-v1');
same('wizard persistido en meta', $meta['calibration']['wizard']['status'] ?? null, 'done');
same('provider original conservado', $meta['provider'] ?? null, 'ZIGBEE2MQTT');

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
