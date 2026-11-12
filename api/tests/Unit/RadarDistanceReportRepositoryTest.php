<?php
declare(strict_types=1);

/**
 * F125.1.6 — última DP9 positiva para el banner de Radar Studio.
 *
 * Usa un PDO falso: no requiere BD real.
 *
 * Run: php tests/Unit/RadarDistanceReportRepositoryTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\RadarDistanceReportRepository;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void
{
    $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}
function check(string $m, bool $c): void { $c ? ok($m) : ko($m); }

final class FakeDistanceStatement extends PDOStatement
{
    /** @var array<string,mixed> */
    public array $bound = [];

    public function __construct(private FakeDistancePDO $pdo, private string $sql)
    {
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bound[$param] = $value;
        return true;
    }

    public function execute(?array $params = null): bool
    {
        $this->pdo->lastSql = $this->sql;
        $this->pdo->lastBound = $this->bound;
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->pdo->row;
    }
}

final class FakeDistancePDO extends PDO
{
    public mixed $row = false;
    public string $lastSql = '';
    /** @var array<string,mixed> */
    public array $lastBound = [];

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new FakeDistanceStatement($this, $query);
    }
}

$pdo = new FakeDistancePDO();
$repo = new RadarDistanceReportRepository($pdo);

same('sin filas → null', $repo->latestPositiveForRoom(12, '2026-11-12T09:59:30Z'), null);
check('consulta solo distancias positivas', str_contains($pdo->lastSql, 'distance_m > 0'));
check('consulta ordenada por recepción', str_contains($pdo->lastSql, 'ORDER BY received_at DESC, id DESC'));
check('sin config_key no filtra', !str_contains($pdo->lastSql, 'config_key ='));

$pdo->row = [
    'id' => '77',
    'device_id' => '438',
    'room_id' => '12',
    'marker' => 'v1|s|1|0.570|push',
    'session_id' => 's',
    'report_counter' => '1',
    'distance_m' => '0.570',
    'origin' => 'push',
    'config_key' => 'abc',
    'received_at' => '2026-11-12 09:59:58',
];
$row = $repo->latestPositiveForRoom(12, '2026-11-12T09:59:30Z', 'abc');
same('fila normalizada: id', $row['id'], 77);
same('fila normalizada: distancia', $row['distance_m'], 0.57);
same('fila normalizada: contador', $row['report_counter'], 1);
check('config_key enviado a la consulta', str_contains($pdo->lastSql, 'config_key = :config_key'));
same('config_key bindeado', $pdo->lastBound[':config_key'] ?? null, 'abc');
same('límite de ventana bindeado', $pdo->lastBound[':since'] ?? null, '2026-11-12T09:59:30Z');
same('sala bindeada', $pdo->lastBound[':room_id'] ?? null, 12);

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
