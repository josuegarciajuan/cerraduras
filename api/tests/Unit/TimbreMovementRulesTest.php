<?php
declare(strict_types=1);

/**
 * Unit tests for TimbreMovementRules (F116).
 * Run: php tests/Unit/TimbreMovementRulesTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Timbre\TimbreMovementRules as R;

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }

echo "TimbreMovementRules Unit Tests\n";
echo str_repeat("=", 60) . "\n\n";

$r = R::directionFromPresence(true);
$r === R::DIRECTION_EXIT ? pass('presencia antes → salida') : fail('presencia antes → salida');
$r = R::directionFromPresence(false);
$r === R::DIRECTION_ENTRY ? pass('sin presencia antes → entrada') : fail('sin presencia antes → entrada');

R::typeFor('NFC', 'SCAN') === 'ficha' ? pass('NFC → ficha') : fail('NFC → ficha');
R::typeFor('QR', 'SCAN') === 'qr' ? pass('QR → qr') : fail('QR → qr');
R::typeFor(null, 'DOOR') === 'sinllave' ? pass('sin credencial → sin llave') : fail('sin credencial → sin llave');

R::shouldStartCrossing('IDLE', true, true, false) === true
    ? pass('puerta abierta + radar + sin movimiento → cruce')
    : fail('puerta abierta + radar + sin movimiento → cruce');
R::shouldStartCrossing('IDLE', false, true, false) === false
    ? pass('puerta cerrada no crea cruce')
    : fail('puerta cerrada no crea cruce');
R::shouldStartCrossing('RECORDING', true, true, true) === false
    ? pass('movimiento activo no crea cruce')
    : fail('movimiento activo no crea cruce');

R::stopStatus(R::DIRECTION_ENTRY, false, true, false) === R::STATUS_COMPLETED
    ? pass('entrada con presencia → completada')
    : fail('entrada con presencia → completada');
R::stopStatus(R::DIRECTION_ENTRY, false, false, false) === R::STATUS_NO_PRESENCE
    ? pass('entrada sin presencia → sin presencia')
    : fail('entrada sin presencia → sin presencia');
R::stopStatus(R::DIRECTION_EXIT, true, true, false) === R::STATUS_LEFT_OPEN
    ? pass('salida con puerta abierta → puerta abierta')
    : fail('salida con puerta abierta → puerta abierta');
R::stopStatus(R::DIRECTION_ENTRY, false, true, true) === R::STATUS_MAX_REACHED
    ? pass('tope general → tope alcanzado')
    : fail('tope general → tope alcanzado');
R::stopStatus(R::DIRECTION_EXIT, true, true, true) === R::STATUS_LEFT_OPEN
    ? pass('tope con puerta abierta → puerta abierta')
    : fail('tope con puerta abierta → puerta abierta');

R::stopsOnPresenceEnd(R::DIRECTION_ENTRY) === true ? pass('entrada para por presencia') : fail('entrada para por presencia');
R::stopsOnPresenceEnd(R::DIRECTION_EXIT) === false ? pass('salida no para por presencia') : fail('salida no para por presencia');
R::stopsOnDoorClose(R::DIRECTION_EXIT) === true ? pass('salida para por cierre') : fail('salida para por cierre');
R::stopsOnDoorClose(R::DIRECTION_ENTRY) === false ? pass('entrada no para por cierre') : fail('entrada no para por cierre');

echo "\n" . ($failed === 0 ? '✅' : '❌') . " TimbreMovementRules: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
