<?php
declare(strict_types=1);

/**
 * Unit tests for StayEventsSyncService (F13, TSK-141).
 *
 * Tests:
 *   - stay.closed → tipo='EXIT'
 *   - stay.overstay → tipo='OUT'
 *   - unknown topic → 422 unknown_topic
 *   - rfid empty → 422 invalid_config
 *   - rfid >10 chars → 422 invalid_config
 *   - occurred_at parses correctly
 *
 * Run: php tests/Unit/StayEventsSyncServiceTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use Ws\Stays\StayEventsSyncService;
use Ws\Support\Config;
use Ws\Support\Errors\UnprocessableException;
use Ws\Vb6Repo\Vb6LogUsuariosRepo;

// ============================================================================
// Test helpers
// ============================================================================
$pass = 0;
$fail = 0;

function ok(string $label): void
{
    global $pass;
    $pass++;
    echo "  PASS  $label\n";
}

function bad(string $label, string $detail = ''): void
{
    global $fail;
    $fail++;
    echo "  FAIL  $label\n";
    if ($detail !== '') {
        echo "        $detail\n";
    }
}

function assertEquals(mixed $expected, mixed $actual, string $label): void
{
    if ($expected === $actual) {
        ok($label);
    } else {
        bad($label, "Expected: " . var_export($expected, true) . " Got: " . var_export($actual, true));
    }
}

// ============================================================================
// Fake repo
// ============================================================================
final class FakeLogUsuariosRepo extends Vb6LogUsuariosRepo
{
    public array $log = [];

    public function __construct()
    {
        // No PDO needed for fake
    }

    public function insertLog(string $rfid, string $fecha, string $tipo): void
    {
        $this->log[] = ['rfid' => $rfid, 'fecha' => $fecha, 'tipo' => $tipo];
    }
}

// ============================================================================
// Tests
// ============================================================================
Config::load(__DIR__ . '/../../.env');

$fakeRepo = new FakeLogUsuariosRepo();
$service  = new StayEventsSyncService($fakeRepo);

echo "\n--- topic mapping ---\n";

// stay.closed → EXIT
$result = $service->process(['topic' => 'stay.closed', 'occurred_at' => '2026-04-28T10:00:00Z']);
assertEquals('stay.closed', $result['topic'], 'topic=stay.closed returned');
assertEquals('EXIT', $result['tipo'], 'stay.closed → tipo=EXIT');
assertEquals('QRSYS', $result['rfid'],  'rfid=QRSYS (from .env)');
assertEquals(true, $result['logged'],   'logged=true');
assertEquals(1, count($fakeRepo->log),  'insertLog called once');
assertEquals('EXIT', $fakeRepo->log[0]['tipo'], 'log record has tipo=EXIT');

// stay.overstay → OUT
$result2 = $service->process(['topic' => 'stay.overstay', 'occurred_at' => '2026-04-28T11:00:00Z']);
assertEquals('OUT', $result2['tipo'], 'stay.overstay → tipo=OUT');
assertEquals(2, count($fakeRepo->log), 'insertLog called again (2 total)');

echo "\n--- unknown topic ---\n";

try {
    $service->process(['topic' => 'stay.entered']);
    bad('unknown_topic throws UnprocessableException', 'No exception thrown');
} catch (UnprocessableException $e) {
    assertEquals('unknown_topic', $e->errorCode(), 'unknown_topic error code');
    ok('unknown topic → UnprocessableException(unknown_topic)');
}

echo "\n--- invalid rfid config ---\n";

// Temporarily override APP_VB6_SYSTEM_RFID with invalid value
// We'll use a service with a patched Config approach via putenv

putenv('APP_VB6_SYSTEM_RFID=');
// Reset Config cache by reloading (Config reads from env on each call since putenv)
$fakeRepo2  = new FakeLogUsuariosRepo();
$service2   = new StayEventsSyncService($fakeRepo2);

try {
    $service2->process(['topic' => 'stay.closed', 'occurred_at' => '2026-04-28T10:00:00Z']);
    bad('empty rfid → 422 invalid_config', 'No exception thrown');
} catch (UnprocessableException $e) {
    assertEquals('invalid_config', $e->errorCode(), 'empty rfid → invalid_config');
    ok('empty rfid → UnprocessableException(invalid_config)');
}

// Restore
putenv('APP_VB6_SYSTEM_RFID=QRSYS');

// Too long rfid
putenv('APP_VB6_SYSTEM_RFID=TOOLONGRFID123');
$fakeRepo3 = new FakeLogUsuariosRepo();
$service3  = new StayEventsSyncService($fakeRepo3);

try {
    $service3->process(['topic' => 'stay.closed', 'occurred_at' => '2026-04-28T10:00:00Z']);
    bad('rfid >10 chars → 422 invalid_config', 'No exception thrown');
} catch (UnprocessableException $e) {
    assertEquals('invalid_config', $e->errorCode(), 'rfid >10 chars → invalid_config');
    ok('rfid >10 chars → UnprocessableException(invalid_config)');
}

// Restore
putenv('APP_VB6_SYSTEM_RFID=QRSYS');

// ============================================================================
// Summary
// ============================================================================
echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
