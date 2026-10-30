#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/firmware-publish.php — F100/RF-180.
 *
 * Publish an immutable firmware binary into the OTA catalogue.
 *
 * Usage:
 *   php bin/firmware-publish.php \
 *     --variant=IMAN --version=2.6-ota --file=/path/to/esp-iman.ino.bin \
 *     --build-id=20261030-1 --commit=abc1234 [--notes="..."] [--no-publish]
 *
 * The binary is copied to api/storage/firmware/<sha256>.bin. Publishing does
 * NOT update devices by itself: the panel must request an operation.
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Domain\Firmware\FirmwareReleaseRepository;
use App\Infrastructure\Db\PdoFactory;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

$opts = getopt('', [
    'variant:', 'version:', 'file:', 'build-id::', 'commit::', 'board::',
    'partition-scheme::', 'protocol::', 'notes::', 'no-publish', 'help',
]);

if (isset($opts['help']) || !isset($opts['variant'], $opts['version'], $opts['file'])) {
    fwrite(STDERR, "Usage: php bin/firmware-publish.php --variant=CERRADURA|IMAN --version=<v> --file=<bin> [--build-id=] [--commit=] [--no-publish]\n");
    exit(isset($opts['help']) ? 0 : 2);
}

$variant = strtoupper(trim((string) $opts['variant']));
if (!in_array($variant, ['CERRADURA', 'IMAN'], true)) {
    fwrite(STDERR, "[firmware-publish] variant must be CERRADURA or IMAN\n");
    exit(2);
}
$version = trim((string) $opts['version']);
if ($version === '') {
    fwrite(STDERR, "[firmware-publish] version is required\n");
    exit(2);
}
$source = (string) $opts['file'];
if (!is_file($source) || !is_readable($source)) {
    fwrite(STDERR, "[firmware-publish] file not readable: {$source}\n");
    exit(2);
}
$data = file_get_contents($source);
if ($data === false || $data === '') {
    fwrite(STDERR, "[firmware-publish] file is empty or unreadable\n");
    exit(2);
}
$sha = hash('sha256', $data);
$storageDir = dirname(__DIR__) . '/storage/firmware';
if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
    fwrite(STDERR, "[firmware-publish] cannot create storage dir\n");
    exit(1);
}
$target = $storageDir . '/' . $sha . '.bin';
if (!is_file($target)) {
    if (file_put_contents($target, $data, LOCK_EX) === false) {
        fwrite(STDERR, "[firmware-publish] cannot write {$target}\n");
        exit(1);
    }
    @chmod($target, 0644);
}

try {
    $pdo = PdoFactory::make();
    $repo = new FirmwareReleaseRepository($pdo);
    $existing = $repo->findByVariantVersion($variant, $version);
    if ($existing !== null) {
        fwrite(STDERR, "[firmware-publish] release already exists: {$variant} {$version}\n");
        exit(1);
    }
    $id = $repo->create([
        'variant'          => $variant,
        'version'          => $version,
        'build_id'         => $opts['build-id'] ?? null,
        'commit_ref'       => $opts['commit'] ?? null,
        'board'            => $opts['board'] ?? 'esp32s3usbotg',
        'partition_scheme' => $opts['partition-scheme'] ?? 'ota-2app',
        'protocol'         => $opts['protocol'] ?? 'ota-v1',
        'sha256'           => $sha,
        'size_bytes'       => strlen($data),
        'binary_path'      => basename($target),
        'notes'            => $opts['notes'] ?? null,
        'published'        => !isset($opts['no-publish']),
    ]);
    echo "[firmware-publish] OK id={$id} {$variant} {$version} sha256={$sha} size=" . strlen($data) . " published=" . (isset($opts['no-publish']) ? '0' : '1') . "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "[firmware-publish] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
