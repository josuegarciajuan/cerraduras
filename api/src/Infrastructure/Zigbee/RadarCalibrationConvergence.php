<?php
declare(strict_types=1);

namespace App\Infrastructure\Zigbee;

use App\Domain\Sensors\RadarCalibrationDrift;
use App\Support\Config;
use PDO;
use Throwable;

/**
 * RadarCalibrationConvergence — F125.2.
 *
 * Hace cumplir un invariante: la configuración reportada por el radar debe
 * converger a la calibración guardada en `devices.meta_json.calibration`.
 *
 * - No inventa valores: si no hay calibración guardada válida, no actúa.
 * - No consulta Tuya ni crea pollers: se invoca con cada push de presencia.
 * - No persiste la configuración del dispositivo como calibración; la fuente
 *   de verdad sigue siendo la BD.
 * - Durante una prueba (apply/wizard/autotune) el objetivo temporal es
 *   `calibration_pending`; así el reconciliador no revierte la prueba.
 *
 * Estado de convergencia en `devices.meta_json.calibration_state`.
 */
final class RadarCalibrationConvergence
{
    public const STATE_KEY = 'calibration_state';
    public const PENDING_KEY = ZigbeeRadarCalibrationService::PENDING_KEY;
    public const PENDING_UNTIL_KEY = ZigbeeRadarCalibrationService::PENDING_UNTIL_KEY;

    public const STATUS_APPLIED = 'applied';
    public const STATUS_CONVERGING = 'converging';
    public const STATUS_ERROR = 'error';
    public const STATUS_MISSING = 'missing';
    public const STATUS_SUSPENDED = 'suspended';
    /**
     * F125.3: el radar publicó una configuración imposible (p. ej.
     * `maximum_range=-334889.04`). No es una deriva real: no se publica nada,
     * no se cuentan intentos y no se pisa la calibración guardada.
     */
    public const STATUS_INVALID_REPORTED = 'invalid_reported';

    public const DEFAULT_COOLDOWN_SECONDS = 3;
    public const DEFAULT_MAX_ATTEMPTS = 6;

    private ZigbeeRadarCalibrationService $service;

    /**
     * @param callable(string,string,array=):array{ok:bool,error:?string}|null $publisher
     */
    public function __construct(
        private PDO $pdo,
        ?callable $publisher = null,
        ?string $profileConfigFile = null
    ) {
        $this->service = new ZigbeeRadarCalibrationService($pdo, $publisher, $profileConfigFile);
    }

    /**
     * Reacciona a un reporte real de presencia.
     *
     * @param array<string,mixed> $reported Configuración reportada por el radar.
     * @return array<string,mixed> Estado de convergencia resultante.
     */
    public function onReport(int $roomId, int $deviceId, string $externalId, array $reported): array
    {
        $meta = $this->loadMeta($deviceId);
        if ($meta === null) {
            return ['status' => self::STATUS_MISSING, 'reason' => 'device_missing'];
        }
        $desired = self::desired($meta);
        if ($desired === null) {
            return ['status' => self::STATUS_MISSING, 'reason' => 'calibration_missing'];
        }

        $invalidReason = $reported === []
            ? null : ZigbeeRadarCalibrationService::settingsInvalidReason($reported);
        if ($invalidReason !== null) {
            // No es una deriva que se pueda corregir publicando: el broker o
            // el converter está entregando un valor imposible. Se audita en la
            // respuesta y NO se toca `calibration_state`.
            return [
                'status' => self::STATUS_INVALID_REPORTED,
                'reason' => 'reported_settings_invalid',
                'invalid_reason' => $invalidReason,
                'desired_source' => $desired['source'],
                'desired' => self::comparable($desired['settings']),
                'reported' => self::comparable($reported),
                'differences' => [],
            ];
        }

        $drift = RadarCalibrationDrift::evaluate($desired['settings'], $reported);
        $now = gmdate('Y-m-d\TH:i:s.v\Z');

        if ($drift['status'] === RadarCalibrationDrift::STATUS_APPLIED) {
            $previousState = is_array($meta[self::STATE_KEY] ?? null) ? $meta[self::STATE_KEY] : [];
            $convergedAt = ($previousState['status'] ?? '') === self::STATUS_APPLIED
                && isset($previousState['converged_at'])
                ? $previousState['converged_at']
                : $now;
            $state = [
                'status' => self::STATUS_APPLIED,
                'desired_source' => $desired['source'],
                'desired' => self::comparable($desired['settings']),
                'reported' => self::comparable($reported),
                'differences' => [],
                'attempts' => 0,
                'last_error' => null,
                'converged_at' => $convergedAt,
                'updated_at' => $now,
            ];
            $this->persistIfChanged($deviceId, $meta, $state);
            return $state;
        }

        if ($drift['status'] !== RadarCalibrationDrift::STATUS_MISMATCH) {
            return [
                'status' => $drift['status'],
                'reason' => 'reported_settings_incomplete',
                'desired_source' => $desired['source'],
                'differences' => [],
            ];
        }

        $previous = is_array($meta[self::STATE_KEY] ?? null) ? $meta[self::STATE_KEY] : [];
        $attempts = (int) ($previous['attempts'] ?? 0);
        $maxAttempts = self::maxAttempts();

        if ($attempts >= $maxAttempts) {
            $state = $this->stateFor(
                $desired,
                $reported,
                $drift,
                self::STATUS_ERROR,
                $attempts,
                (string) ($previous['last_error'] ?? 'max_attempts')
            );
            $state['last_attempt_at'] = $previous['last_attempt_at'] ?? null;
            $state['updated_at'] = $now;
            $this->persistIfChanged($deviceId, $meta, $state);
            return $state;
        }

        $lastAttempt = self::timestamp($previous['last_attempt_at'] ?? null);
        if ($lastAttempt !== null && (time() - $lastAttempt) < self::cooldownSeconds()) {
            return array_merge(
                ['status' => self::STATUS_CONVERGING],
                $this->stateFor($desired, $reported, $drift, self::STATUS_CONVERGING, $attempts, null),
                [
                    'attempts' => $attempts,
                    'last_error' => $previous['last_error'] ?? null,
                    'last_attempt_at' => $previous['last_attempt_at'] ?? null,
                ]
            );
        }

        $commands = self::commands($desired['settings']);
        $error = null;
        try {
            $result = $this->service->publish(
                $roomId,
                ['id' => $deviceId, 'external_id' => $externalId, 'meta' => $meta],
                $commands,
                false
            );
            if (($result['ok'] ?? false) !== true) {
                $error = (string) ($result['error'] ?? 'publish_failed');
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $state = $this->stateFor(
            $desired,
            $reported,
            $drift,
            $error === null ? self::STATUS_CONVERGING : self::STATUS_ERROR,
            $attempts + 1,
            $error
        );
        $state['last_attempt_at'] = $now;
        $state['updated_at'] = $now;
        $this->persistIfChanged($deviceId, $meta, $state);
        return $state;
    }

    /**
     * Objetivo vigente: pending (prueba en curso) o calibración guardada.
     *
     * @param array<string,mixed> $meta
     * @return array{settings:array<string,mixed>,source:string}|null
     */
    public static function desired(array $meta): ?array
    {
        $pending = is_array($meta[self::PENDING_KEY] ?? null) ? $meta[self::PENDING_KEY] : null;
        $until = self::timestamp($meta[self::PENDING_UNTIL_KEY] ?? null);
        if ($pending !== null
            && ($until === null || $until >= time())
            && ZigbeeRadarCalibrationService::isValidSettings($pending)) {
            return ['settings' => $pending, 'source' => 'pending'];
        }

        foreach (['calibration', 'calibration_previous'] as $key) {
            $candidate = $meta[$key] ?? null;
            if (is_array($candidate) && ZigbeeRadarCalibrationService::isValidSettings($candidate)) {
                return ['settings' => $candidate, 'source' => $key];
            }
        }
        return null;
    }

    /**
     * Vista de solo lectura para el panel: deriva entre guardada y reportada,
     * más el último estado del reconciliador.
     *
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $reported
     * @return array<string,mixed>
     */
    public static function statusFor(array $meta, array $reported): array
    {
        $state = is_array($meta[self::STATE_KEY] ?? null) ? $meta[self::STATE_KEY] : null;
        $desired = self::desired($meta);
        if ($desired === null) {
            return [
                'status' => self::STATUS_MISSING,
                'desired_source' => null,
                'desired' => null,
                'reported' => self::comparable($reported),
                'differences' => [],
                'state' => $state,
            ];
        }
        $invalidReason = $reported === []
            ? null : ZigbeeRadarCalibrationService::settingsInvalidReason($reported);
        if ($invalidReason !== null) {
            return [
                'status' => self::STATUS_INVALID_REPORTED,
                'reason' => 'reported_settings_invalid',
                'invalid_reason' => $invalidReason,
                'desired_source' => $desired['source'],
                'desired' => self::comparable($desired['settings']),
                'reported' => self::comparable($reported),
                'differences' => [],
                'state' => $state,
            ];
        }
        $drift = RadarCalibrationDrift::evaluate($desired['settings'], $reported);
        return [
            'status' => $drift['status'],
            'desired_source' => $desired['source'],
            'desired' => self::comparable($desired['settings']),
            'reported' => self::comparable($reported),
            'differences' => $drift['differences'] ?? [],
            'state' => $state,
        ];
    }

    public static function cooldownSeconds(): int
    {
        $raw = Config::get('RADAR_RECONCILE_COOLDOWN_SECONDS', (string) self::DEFAULT_COOLDOWN_SECONDS);
        $value = is_numeric($raw) ? (int) $raw : self::DEFAULT_COOLDOWN_SECONDS;
        return max(1, min(300, $value));
    }

    public static function maxAttempts(): int
    {
        $raw = Config::get('RADAR_RECONCILE_MAX_ATTEMPTS', (string) self::DEFAULT_MAX_ATTEMPTS);
        $value = is_numeric($raw) ? (int) $raw : self::DEFAULT_MAX_ATTEMPTS;
        return max(1, min(50, $value));
    }

    /** @return array<string,float> */
    private static function commands(array $settings): array
    {
        $commands = [];
        foreach (RadarCalibrationDrift::KEYS as $key) {
            if (isset($settings[$key]) && is_numeric($settings[$key])) {
                $commands[$key] = (float) $settings[$key];
            }
        }
        return $commands;
    }

    /** @return array<string,float> */
    private static function comparable(array $settings): array
    {
        return self::commands($settings);
    }

    /**
     * @param array{settings:array<string,mixed>,source:string} $desired
     * @param array<string,mixed> $reported
     * @param array<string,mixed> $drift
     * @return array<string,mixed>
     */
    private function stateFor(
        array $desired,
        array $reported,
        array $drift,
        string $status,
        int $attempts,
        ?string $error
    ): array {
        return [
            'status' => $status,
            'desired_source' => $desired['source'],
            'desired' => self::comparable($desired['settings']),
            'reported' => self::comparable($reported),
            'differences' => $drift['differences'] ?? [],
            'attempts' => $attempts,
            'last_error' => $error,
            'updated_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    /** @return array<string,mixed>|null */
    private function loadMeta(int $deviceId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT meta_json FROM devices WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $deviceId]);
        $raw = $stmt->fetchColumn();
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Solo escribe si el estado cambia materialmente. El radar publica ~1/s;
     * no se puede reescribir la ficha del dispositivo en cada reporte.
     *
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $state
     */
    private function persistIfChanged(int $deviceId, array $meta, array $state): void
    {
        $previous = is_array($meta[self::STATE_KEY] ?? null) ? $meta[self::STATE_KEY] : null;
        $comparableState = $state;
        unset($comparableState['updated_at'], $comparableState['desired'], $comparableState['reported']);
        if ($previous !== null) {
            $comparablePrevious = $previous;
            unset($comparablePrevious['updated_at'], $comparablePrevious['desired'], $comparablePrevious['reported']);
            if ($comparablePrevious == $comparableState) {
                return;
            }
        }
        $meta[self::STATE_KEY] = $state;
        $stmt = $this->pdo->prepare('UPDATE devices SET meta_json = :meta WHERE id = :id');
        $stmt->execute([
            ':meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id' => $deviceId,
        ]);
    }

    private static function timestamp(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : $ts;
    }
}
