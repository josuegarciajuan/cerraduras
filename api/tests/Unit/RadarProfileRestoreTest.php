<?php
declare(strict_types=1);

/**
 * F118 — protección de la calibración del radar en la regresión.
 *
 * Comprueba estáticamente que:
 *  - el restaurador acepta un perfil externo (`--profile-file`);
 *  - el runner guarda el perfil antes del asistente de sensores y lo restaura
 *    al final (incluido el trap de salida).
 */

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

function check(string $name, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  PASS  {$name}\n";
    } else {
        $fail++;
        echo "  FAIL  {$name}\n";
    }
}

$apply = (string) file_get_contents($root . '/bin/radar-apply-profile.php');
$runner = (string) file_get_contents($root . '/bin/run-tests.sh');

check('radar-apply-profile acepta --profile-file', str_contains($apply, '--profile-file'));
check('radar-apply-profile lee calibration_previous',
    str_contains($apply, 'calibration_previous'));
check('run-tests guarda el perfil del radar', str_contains($runner, '_save_radar_profile'));
check('run-tests restaura el perfil del radar', str_contains($runner, '_restore_radar_profile'));
check('run-tests encadena la restauración al trap EXIT',
    str_contains($runner, 'trap _cleanup_all EXIT') && str_contains($runner, '_cleanup_factory_test'));
check('run-tests verifica maximum_range restaurado',
    str_contains($runner, 'calibración del radar restaurada'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
