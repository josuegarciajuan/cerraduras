<?php
declare(strict_types=1);

namespace App\Infrastructure\Sensors;

/**
 * SensorVerdict — F95/RF-167.
 *
 * Matriz de veredicto por dispositivo y global. Reglas:
 *   - SKIP no cuenta como PASS.
 *   - UNVERIFIED/SKIP en un dispositivo requerido bloquea el verde.
 *   - NOT_INSTALLED no bloquea si el dispositivo es opcional (luz).
 *   - Un FAIL en un dispositivo instalado → REVISAR.
 *   - El soak diferido puede degradar el veredicto.
 */
final class SensorVerdict
{
    public const CORRECT = 'CORRECT';
    public const WITH_RESERVES = 'WITH_RESERVES';
    public const REVIEW = 'REVIEW';
    public const INCOMPLETE = 'INCOMPLETE';
    public const NOT_EVALUABLE = 'NOT_EVALUABLE';

    /**
     * @param array<string,mixed> $run
     * @param array<string,mixed> $catalog
     * @return array{verdict:string,devices:array<string,string>,reasons:list<string>,soak:string}
     */
    public static function compute(array $run, array $catalog): array
    {
        $steps = (array) ($run['steps'] ?? []);
        $status = static function (string $id) use ($steps): string {
            return strtoupper((string) ($steps[$id]['status'] ?? 'PENDING'));
        };

        $door = $status('door');
        $inside = $status('radar_inside');
        $outside = $status('radar_outside');
        $light = $status('light');
        $lightInstalled = in_array('light', (array) ($run['plan'] ?? []), true);
        if (!$lightInstalled) {
            $light = 'NOT_INSTALLED';
        }

        $radar = self::combineRadar($inside, $outside);
        $devices = ['door' => $door, 'presence' => $radar, 'light' => $light];
        $reasons = [];
        $soak = strtoupper((string) ($run['soak']['status'] ?? 'PENDING'));

        $hasFail = in_array('FAIL', [$door, $radar], true);
        if ($hasFail) {
            $reasons[] = 'device_failed';
        }
        if (in_array($door, ['PENDING', 'UNVERIFIED', 'SKIP'], true)
            || in_array($radar, ['PENDING', 'UNVERIFIED', 'SKIP'], true)
        ) {
            $reasons[] = 'core_incomplete';
        }
        if ($light === 'FAIL') {
            $reasons[] = 'light_failed';
        }
        if (in_array($light, ['UNVERIFIED', 'SKIP'], true)) {
            $reasons[] = 'light_unverified';
        }
        if ($soak === 'FAIL') {
            $reasons[] = 'soak_false_positive';
        }

        if ($door === 'PENDING' && $radar === 'PENDING') {
            $verdict = self::NOT_EVALUABLE;
        } elseif (in_array('FAIL', $devices, true) || $soak === 'FAIL') {
            $verdict = self::REVIEW;
        } elseif ($door === 'PASS' && $radar === 'PASS'
            && in_array($light, ['PASS', 'NOT_INSTALLED'], true)
            && !in_array('UNVERIFIED', [$door, $radar, $light], true)
        ) {
            $verdict = ($soak === 'PASS' && $light === 'PASS')
                ? self::CORRECT
                : self::WITH_RESERVES;
            if ($soak !== 'PASS') {
                $reasons[] = 'soak_pending';
            }
            if ($light === 'NOT_INSTALLED') {
                $reasons[] = 'light_not_installed';
            }
        } else {
            $verdict = self::INCOMPLETE;
        }

        return [
            'verdict' => $verdict,
            'devices' => $devices,
            'reasons' => array_values(array_unique($reasons)),
            'soak' => $soak,
        ];
    }

    private static function combineRadar(string $inside, string $outside): string
    {
        foreach ([$inside, $outside] as $s) {
            if ($s === 'FAIL') {
                return 'FAIL';
            }
        }
        foreach ([$inside, $outside] as $s) {
            if (in_array($s, ['UNVERIFIED', 'PENDING', 'SKIP'], true)) {
                return $s === 'PENDING' ? 'PENDING' : 'UNVERIFIED';
            }
        }
        return ($inside === 'PASS' && $outside === 'PASS') ? 'PASS' : 'PENDING';
    }
}
