<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\FactoryDevices\FactoryCredential;
use App\Domain\FactoryDevices\FactoryDevice;
use App\Domain\FactoryDevices\FactoryDeviceRepositoryInterface;
use App\Domain\FactoryDevices\FactoryDeviceService;
use App\Support\Errors\ForbiddenException;

$passed = 0;
$failed = 0;
function credentialCheck(bool $condition, string $message): void
{
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    echo ($condition ? "PASS " : "FAIL ") . $message . "\n";
}

echo "PerDeviceCredentialTest\n";
$key = 'factory-key-for-test-only-0123456789';
$GLOBALS['key'] = $key;
$hash = FactoryCredential::hash($key);
credentialCheck($hash === hash('sha256', $key), 'stores only the SHA-256 enrollment hash');
credentialCheck(FactoryCredential::matches($key, $hash), 'matches the enrollment key without retaining plaintext');
credentialCheck(!FactoryCredential::matches('wrong-key', $hash), 'rejects an incorrect enrollment key');
credentialCheck(FactoryCredential::validKey($key), 'accepts a sufficiently long key');
credentialCheck(!FactoryCredential::validKey('short'), 'rejects a short enrollment key');
credentialCheck(FactoryCredential::validChipId('a1b2c3d4e5f6'), 'accepts canonical chip id');
credentialCheck(!FactoryCredential::validChipId('A1B2C3D4E5F6'), 'rejects non-canonical chip id');

final class CredentialRepositoryStub implements FactoryDeviceRepositoryInterface
{
    public bool $claimCalled = false;
    public function findById(int $id): ?FactoryDevice { return $this->device(); }
    public function findByChipId(string $chipId): ?FactoryDevice { return $this->device(); }
    public function announce(string $chipId, string $enrollmentHash): array { return [$this->device(), false]; }
    public function claimAndAudit(int $id, string $chipId, string $enrollmentHash, string $actor, ?int $actorClientId = null, ?string $label = null, ?int $packId = null): ?FactoryDevice
    {
        $this->claimCalled = true;
        if (!hash_equals(FactoryCredential::hash($GLOBALS['key']), $enrollmentHash)) {
            throw new ForbiddenException('invalid_factory_credential');
        }
        return $this->device();
    }
    public function list(string $status): array { return []; }
    private function device(): FactoryDevice
    {
        return new FactoryDevice(1, 'a1b2c3d4e5f6', FactoryDevice::STATUS_CLAIMED, 'now', 'now', 'now', 'tester', 'now', 'now', 10);
    }
}

$repo = new CredentialRepositoryStub();
$service = new FactoryDeviceService($repo);
$service->claim(1, 'a1b2c3d4e5f6', $key, 'tester');
credentialCheck($repo->claimCalled, 'claimed records still validate through the repository transaction');
$rejected = false;
try {
    $service->claim(1, 'a1b2c3d4e5f6', 'wrong-key-012345678901234567890123456789', 'tester');
} catch (ForbiddenException $e) {
    $rejected = $e->errorCode() === 'invalid_factory_credential' && $e->httpStatus() === 403;
}
credentialCheck($rejected, 'claimed records reject an invalid factory credential with 403');

exit($failed === 0 ? 0 : 1);
