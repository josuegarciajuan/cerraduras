<?php
declare(strict_types=1);

/**
 * F127 — DashboardSessionService (signed LAN cookie).
 *
 * Run: php tests/Unit/DashboardSessionTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Dashboard\DashboardSessionService;

$passed = 0;
$failed = 0;
function dsPass(string $msg): void { global $passed; $passed++; echo "  PASS  {$msg}\n"; }
function dsFail(string $msg, string $detail = ''): void { global $failed; $failed++; echo "  FAIL  {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
function dsCheck(bool $ok, string $msg, string $detail = ''): void { $ok ? dsPass($msg) : dsFail($msg, $detail); }

$now = 1_800_000_000;
$svc = new DashboardSessionService(str_repeat('S', 32), 3600);
dsCheck($svc->enabled(), 'servicio habilitado con secreto');
dsCheck($svc->ttlSeconds() === 3600, 'TTL configurado');

$token = $svc->issue($now);
dsCheck(is_string($token) && substr_count((string) $token, '.') === 1, 'token con payload y firma');
$payload = $svc->validate((string) $token, $now + 10);
dsCheck(is_array($payload) && ($payload['v'] ?? null) === 1, 'token válido devuelve payload');
dsCheck(($payload['exp'] ?? 0) === $now + 3600, 'exp = ahora + TTL');

dsCheck($svc->validate((string) $token, $now + 3601) === null, 'token expirado rechazado');
$parts = explode('.', (string) $token);
$tampered = $parts[0] . '.' . str_repeat('A', strlen($parts[1]));
dsCheck($svc->validate($tampered, $now + 10) === null, 'firma manipulada rechazada');
dsCheck($svc->validate('no-es-un-token', $now) === null, 'formato inválido rechazado');

$disabled = new DashboardSessionService('', 3600);
dsCheck(!$disabled->enabled(), 'sin secreto queda deshabilitado');
dsCheck($disabled->issue($now) === null, 'sin secreto no emite token');
dsCheck($disabled->validate((string) $token, $now) === null, 'sin secreto no valida');

$short = new DashboardSessionService(str_repeat('X', 32), 10);
dsCheck($short->ttlSeconds() === DashboardSessionService::DEFAULT_TTL_SECONDS, 'TTL demasiado corto se normaliza');

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
