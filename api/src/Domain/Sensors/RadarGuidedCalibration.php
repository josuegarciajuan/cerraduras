<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

/**
 * RadarGuidedCalibration — F125.1.
 *
 * Máquina de estados PURA de la calibración guiada del radar Zigbee.
 *
 * Secuencia:
 *   1. profile      Ajuste manual + aplicar (confirmación por push).
 *   2. sees_you     ¿El radar te ve? (10 s, presencia).
 *   3. walk         Paseo manual: el operador decide si es "prueba buena".
 *   4. sensitivity  Medición 15 s + aceptar/mantener.
 *   5. outside_1    Fuera: 30 s sin detección.
 *   6. inside_1     Dentro: detección en 30 s (reentrada).
 *   7. outside_2    Fuera final: 30 s sin detección.
 *   8. done         Calibrado.
 *
 * No lee BD, no publica MQTT y no usa el reloj: recibe el estado actual, la
 * presencia auténtica y los reportes DP1/DP9. El controlador es quien persiste
 * y aplica cambios.
 */
final class RadarGuidedCalibration
{
    public const PROFILE = 'profile';
    public const SEES_YOU = 'sees_you';
    public const WALK = 'walk';
    public const SENSITIVITY = 'sensitivity';
    public const OUTSIDE_1 = 'outside_1';
    public const INSIDE_1 = 'inside_1';
    public const OUTSIDE_2 = 'outside_2';
    public const DONE = 'done';

    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_CONFIG_CHANGED = 'config_changed';

    public const STEP_PENDING = 'pending';
    public const STEP_ACTIVE = 'active';
    public const STEP_AWAITING = 'awaiting_decision';
    public const STEP_PASSED = 'passed';
    public const STEP_FAILED = 'failed';
    public const STEP_LOCKED = 'locked';

    public const SEES_YOU_SECONDS = 10;
    public const SENSITIVITY_SECONDS = 15;
    public const OUTSIDE_SECONDS = 30;
    public const INSIDE_SECONDS = 30;
    public const PRESENT_STABLE_SECONDS = 3;
    public const ARM_GRACE_SECONDS = 30;

    /** @return list<array{id:string,title:string,kind:string,location:string,duration:int,description:string}> */
    public static function definitions(): array
    {
        return [
            [
                'id' => self::PROFILE,
                'title' => 'Ajuste manual',
                'kind' => 'profile',
                'location' => 'any',
                'duration' => 0,
                'description' => 'Ajusta sensibilidad, alcance máximo, mínimo (0 permitido) y retardo. Pulsa «Aplicar al radar» y espera la confirmación por push.',
            ],
            [
                'id' => self::SEES_YOU,
                'title' => '¿El radar te ve?',
                'kind' => 'present',
                'location' => 'inside',
                'duration' => self::SEES_YOU_SECONDS,
                'description' => 'Ponte dentro de la zona que quieres cubrir, muévete ligeramente y pulsa «Probar 10 s». El radar debe detectarte.',
            ],
            [
                'id' => self::WALK,
                'title' => 'Paseo',
                'kind' => 'manual_walk',
                'location' => 'inside',
                'duration' => 0,
                'description' => 'Empieza en la distancia mínima y camina hacia atrás. Cuando deje de detectarte en el punto que querías, pulsa «Prueba buena». El sistema no decide por ti.',
            ],
            [
                'id' => self::SENSITIVITY,
                'title' => 'Sensibilidad',
                'kind' => 'sensitivity',
                'location' => 'inside',
                'duration' => self::SENSITIVITY_SECONDS,
                'description' => 'Muévete de forma natural durante 15 s. Si el sistema recomienda otra sensibilidad, acepta o mantén la actual.',
            ],
            [
                'id' => self::OUTSIDE_1,
                'title' => 'Fuera',
                'kind' => 'absent',
                'location' => 'outside',
                'duration' => self::OUTSIDE_SECONDS,
                'description' => 'Sal, cierra la puerta y permanece fuera. El contador empieza cuando el radar deja de detectarte. No debe detectarte en 30 s.',
            ],
            [
                'id' => self::INSIDE_1,
                'title' => 'Dentro',
                'kind' => 'present_reentry',
                'location' => 'inside',
                'duration' => self::INSIDE_SECONDS,
                'description' => 'Sal y espera a que el radar no te detecte. Pulsa «Empezar» y entra. Debe detectarte en 30 s.',
            ],
            [
                'id' => self::OUTSIDE_2,
                'title' => 'Fuera final',
                'kind' => 'absent',
                'location' => 'outside',
                'duration' => self::OUTSIDE_SECONDS,
                'description' => 'Sal y espera. No debe detectarte en 30 s. Este es el último reposo.',
            ],
            [
                'id' => self::DONE,
                'title' => 'Radar calibrado',
                'kind' => 'done',
                'location' => 'any',
                'duration' => 0,
                'description' => 'Todos los pasos superados. Revisa el resumen y guarda la calibración.',
            ],
        ];
    }

    /** @param array<string,mixed> $settings */
    public static function initial(int $roomId, array $settings, ?string $configKey, string $now): array
    {
        $steps = [];
        foreach (self::definitions() as $def) {
            $steps[$def['id']] = self::emptyStep($def['id']);
        }
        return [
            'version' => 1,
            'room_id' => $roomId,
            'status' => self::STATUS_RUNNING,
            'current_step' => self::PROFILE,
            'settings' => self::numericSettings($settings),
            'config_key' => $configKey,
            'steps' => $steps,
            'created_at' => $now,
            'updated_at' => $now,
            'calibration_persisted' => false,
            'calibration_error' => null,
            'summary' => null,
        ];
    }

    /**
     * El perfil se ha aplicado y confirmado. Marca el paso 1 y reinicia las
     * pruebas posteriores: no se puede validar con una configuración distinta.
     *
     * @param array<string,mixed>|null $state
     * @param array<string,mixed> $settings
     */
    public static function markProfileApplied(
        ?array $state,
        int $roomId,
        array $settings,
        ?string $configKey,
        string $now
    ): array {
        $state = $state ?? self::initial($roomId, $settings, $configKey, $now);
        $state['status'] = self::STATUS_RUNNING;
        $state['settings'] = self::numericSettings($settings);
        $state['config_key'] = $configKey;
        $state['current_step'] = self::SEES_YOU;
        $state['updated_at'] = $now;
        $state['calibration_persisted'] = false;
        $state['calibration_error'] = null;
        $state['summary'] = null;

        $state['steps'] = [];
        foreach (self::definitions() as $def) {
            $state['steps'][$def['id']] = self::emptyStep($def['id']);
        }
        $state['steps'][self::PROFILE]['status'] = self::STEP_PASSED;
        $state['steps'][self::PROFILE]['finished_at'] = $now;
        $state['steps'][self::PROFILE]['detail'] = [
            'message' => 'Perfil aplicado y confirmado.',
            'settings' => self::numericSettings($settings),
        ];
        return $state;
    }

    /**
     * La configuración cambió por fuera del wizard. Se invalida la calibración
     * y se vuelve al ajuste manual.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed>|null $settings
     */
    public static function markConfigChanged(array $state, ?array $settings, string $now): array
    {
        $state['status'] = self::STATUS_CONFIG_CHANGED;
        $state['current_step'] = self::PROFILE;
        $state['config_key'] = null;
        if ($settings !== null) {
            $state['settings'] = self::numericSettings($settings);
        }
        $state['updated_at'] = $now;
        foreach (self::definitions() as $def) {
            $state['steps'][$def['id']] = self::emptyStep($def['id']);
        }
        $state['steps'][self::PROFILE]['status'] = self::STEP_ACTIVE;
        $state['steps'][self::PROFILE]['detail'] = [
            'message' => 'La configuración del radar ha cambiado. Vuelve a aplicar el perfil para reiniciar la calibración.',
        ];
        return $state;
    }

    /** @param array<string,mixed> $ctx */
    public static function startStep(array $state, string $stepId, string $now, array $ctx): array
    {
        if (($state['status'] ?? '') !== self::STATUS_RUNNING
            || (string) ($state['current_step'] ?? '') !== $stepId
            || !isset($state['steps'][$stepId])) {
            return $state;
        }
        $step = self::emptyStep($stepId);
        $step['status'] = self::STEP_ACTIVE;
        $step['started_at'] = $now;
        $step['baseline_marker'] = (string) ($ctx['authentic_marker'] ?? '');
        $step['armed_at'] = $stepId === self::SEES_YOU ? $now : null;
        $step['detail'] = [
            'message' => self::startMessage($stepId),
            'started_at' => $now,
        ];
        $state['steps'][$stepId] = $step;
        $state['updated_at'] = $now;
        return $state;
    }

    /**
     * Evalúa un paso automático (sees_you, outside, inside). No actúa sobre
     * walk/sensitivity/profile/done.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $ctx
     */
    public static function evaluateAuto(array $state, string $now, array $ctx): array
    {
        if (($state['status'] ?? '') !== self::STATUS_RUNNING) {
            return $state;
        }
        $stepId = (string) ($state['current_step'] ?? '');
        $step = $state['steps'][$stepId] ?? null;
        if (!is_array($step) || ($step['status'] ?? '') !== self::STEP_ACTIVE) {
            return $state;
        }
        $kind = self::kindOf($stepId);
        if (!in_array($kind, ['present', 'present_reentry', 'absent'], true)) {
            return $state;
        }
        if (($ctx['stale'] ?? false) === true) {
            return self::failStep($state, $stepId, $now, 'presence_stale', 'El radar no envía datos recientes. Comprueba alimentación y red Zigbee.');
        }
        $startedAt = (string) ($step['started_at'] ?? '');
        if ($startedAt === '') {
            return $state;
        }
        $authentic = strtoupper((string) ($ctx['authentic_state'] ?? 'UNKNOWN'));
        $authenticMarker = (string) ($ctx['authentic_marker'] ?? '');
        $presence = (array) ($ctx['presence_reports'] ?? []);

        if ($kind === 'absent') {
            $result = self::evaluateAbsent($step, $startedAt, $now, $presence, $authentic, $authenticMarker);
        } else {
            $result = self::evaluatePresent(
                $step,
                $startedAt,
                $now,
                $presence,
                $authentic,
                $authenticMarker,
                $kind === 'present_reentry'
            );
        }

        $state['steps'][$stepId] = $result['step'];
        $state['updated_at'] = $now;
        if ($result['result'] === 'passed') {
            return self::markPassed($state, $stepId, $now, $result['detail']);
        }
        if ($result['result'] === 'failed') {
            return self::failStep(
                $state,
                $stepId,
                $now,
                (string) ($result['reason'] ?? 'failed'),
                (string) ($result['message'] ?? 'Paso no superado.'),
                $result['detail']
            );
        }
        return $state;
    }

    /**
     * Información del paseo. No decide si es buena: el operador lo marca.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $ctx
     */
    public static function evaluateWalk(array $state, string $now, array $ctx): array
    {
        $stepId = self::WALK;
        if (($state['status'] ?? '') !== self::STATUS_RUNNING
            || (string) ($state['current_step'] ?? '') !== $stepId) {
            return $state;
        }
        $step = $state['steps'][$stepId] ?? self::emptyStep($stepId);
        if (($step['status'] ?? '') !== self::STEP_ACTIVE) {
            return $state;
        }
        $startedAt = (string) ($step['started_at'] ?? '');
        $info = self::walkInfo(
            $startedAt,
            $now,
            (array) ($ctx['presence_reports'] ?? []),
            (array) ($ctx['distance_reports'] ?? []),
            (array) ($ctx['settings'] ?? [])
        );
        $step['detail'] = array_merge($info, [
            'started_at' => $startedAt,
            'presence' => strtoupper((string) ($ctx['authentic_state'] ?? 'UNKNOWN')),
        ]);
        $state['steps'][$stepId] = $step;
        $state['updated_at'] = $now;
        return $state;
    }

    /** @param array<string,mixed> $detail */
    public static function markPassed(array $state, string $stepId, string $now, array $detail = []): array
    {
        if (!isset($state['steps'][$stepId])) {
            return $state;
        }
        $step = $state['steps'][$stepId];
        $step['status'] = self::STEP_PASSED;
        $step['finished_at'] = $now;
        $step['detail'] = array_merge((array) ($step['detail'] ?? []), $detail, ['finished_at' => $now]);
        $state['steps'][$stepId] = $step;
        $state['updated_at'] = $now;
        $next = self::nextStepId($stepId);
        if ($next === self::DONE) {
            $state['current_step'] = self::DONE;
            $state['status'] = self::STATUS_DONE;
            $state['steps'][self::DONE] = self::emptyStep(self::DONE);
            $state['steps'][self::DONE]['status'] = self::STEP_PASSED;
            $state['steps'][self::DONE]['finished_at'] = $now;
            $state['steps'][self::DONE]['detail'] = ['message' => 'Todos los pasos superados.'];
            $state['summary'] = self::summary($state, $now);
        } else {
            $state['current_step'] = $next;
            $state['steps'][$next] = self::emptyStep($next);
        }
        return $state;
    }

    /** @param array<string,mixed> $detail */
    public static function failStep(
        array $state,
        string $stepId,
        string $now,
        string $reason,
        string $message,
        array $detail = []
    ): array {
        if (!isset($state['steps'][$stepId])) {
            return $state;
        }
        $step = $state['steps'][$stepId];
        $step['status'] = self::STEP_FAILED;
        $step['finished_at'] = $now;
        $step['detail'] = array_merge((array) ($step['detail'] ?? []), $detail, [
            'reason' => $reason,
            'message' => $message,
            'finished_at' => $now,
        ]);
        $state['steps'][$stepId] = $step;
        $state['updated_at'] = $now;
        return $state;
    }

    /** @param array<string,mixed> $state */
    public static function retryStep(array $state, string $now): array
    {
        $stepId = (string) ($state['current_step'] ?? '');
        if (!isset($state['steps'][$stepId]) || $stepId === self::DONE) {
            return $state;
        }
        $state['steps'][$stepId] = self::emptyStep($stepId);
        $state['steps'][$stepId]['status'] = self::STEP_PENDING;
        $state['status'] = self::STATUS_RUNNING;
        $state['summary'] = null;
        $state['updated_at'] = $now;
        return $state;
    }

    /** @param array<string,mixed> $recommendation */
    public static function sensitivityAwaiting(array $state, array $recommendation, string $now): array
    {
        $stepId = self::SENSITIVITY;
        if (($state['current_step'] ?? '') !== $stepId || !isset($state['steps'][$stepId])) {
            return $state;
        }
        $step = $state['steps'][$stepId];
        $step['status'] = self::STEP_AWAITING;
        $step['recommendation'] = $recommendation;
        $step['detail'] = array_merge((array) ($step['detail'] ?? []), [
            'message' => 'Sugerencia: ' . ($recommendation['value'] ?? '—') . '/9. Acepta o mantén la actual.',
            'recommendation' => $recommendation,
        ]);
        $state['steps'][$stepId] = $step;
        $state['updated_at'] = $now;
        return $state;
    }

    /** @param array<string,mixed> $state */
    public static function sensitivityDecision(array $state, string $decision, string $now): array
    {
        $stepId = self::SENSITIVITY;
        if (($state['current_step'] ?? '') !== $stepId || !isset($state['steps'][$stepId])) {
            return $state;
        }
        $step = $state['steps'][$stepId];
        $step['decision'] = $decision;
        $state['steps'][$stepId] = $step;
        return self::markPassed($state, $stepId, $now, [
            'message' => $decision === 'accept' ? 'Sensibilidad aceptada.' : 'Se mantiene la sensibilidad actual.',
            'decision' => $decision,
        ]);
    }

    /**
     * Vista para la UI: definiciones + estado + detalle.
     *
     * @param array<string,mixed>|null $state
     * @return array{current_step:string,status:string,steps:list<array<string,mixed>>}
     */
    public static function view(?array $state): array
    {
        $defs = self::definitions();
        $current = $state !== null ? (string) ($state['current_step'] ?? self::PROFILE) : self::PROFILE;
        $currentIndex = self::indexOf($current);
        $out = [];
        foreach ($defs as $i => $def) {
            $id = $def['id'];
            $entry = $state['steps'][$id] ?? null;
            if ($state === null) {
                $status = $id === self::PROFILE ? self::STEP_ACTIVE : self::STEP_LOCKED;
            } elseif ($id === self::DONE && ($state['status'] ?? '') === self::STATUS_DONE) {
                $status = self::STEP_PASSED;
            } elseif ($id === $current) {
                $status = is_array($entry) ? (string) ($entry['status'] ?? self::STEP_PENDING) : self::STEP_PENDING;
            } elseif (is_array($entry) && ($entry['status'] ?? '') === self::STEP_PASSED) {
                $status = self::STEP_PASSED;
            } elseif (is_array($entry) && ($entry['status'] ?? '') === self::STEP_FAILED) {
                $status = self::STEP_FAILED;
            } elseif ($i < $currentIndex) {
                $status = self::STEP_PASSED;
            } else {
                $status = self::STEP_LOCKED;
            }
            $out[] = array_merge($def, [
                'status' => $status,
                'detail' => is_array($entry) ? ($entry['detail'] ?? null) : null,
                'started_at' => is_array($entry) ? ($entry['started_at'] ?? null) : null,
                'finished_at' => is_array($entry) ? ($entry['finished_at'] ?? null) : null,
                'recommendation' => is_array($entry) ? ($entry['recommendation'] ?? null) : null,
            ]);
        }
        return [
            'current_step' => $current,
            'status' => $state !== null ? (string) ($state['status'] ?? self::STATUS_RUNNING) : self::STATUS_RUNNING,
            'steps' => $out,
        ];
    }

    /** @param array<string,mixed> $state */
    public static function summary(array $state, string $now): array
    {
        $walk = $state['steps'][self::WALK]['detail'] ?? [];
        $sens = $state['steps'][self::SENSITIVITY] ?? [];
        return [
            'finished_at' => $now,
            'settings' => self::numericSettings((array) ($state['settings'] ?? [])),
            'walk' => [
                'loss_distance' => $walk['loss_distance'] ?? null,
                'last_distance' => $walk['last_distance'] ?? null,
                'message' => $walk['message'] ?? null,
            ],
            'sensitivity' => [
                'recommendation' => $sens['recommendation'] ?? null,
                'decision' => $sens['decision'] ?? null,
            ],
            'steps' => array_map(static fn (array $s): array => [
                'status' => $s['status'] ?? null,
                'started_at' => $s['started_at'] ?? null,
                'finished_at' => $s['finished_at'] ?? null,
                'reason' => $s['detail']['reason'] ?? null,
            ], array_filter((array) ($state['steps'] ?? []), 'is_array')),
        ];
    }

    public static function nextStepId(string $stepId): string
    {
        $ids = array_map(static fn (array $d): string => $d['id'], self::definitions());
        $idx = array_search($stepId, $ids, true);
        if ($idx === false || $idx >= count($ids) - 1) {
            return self::DONE;
        }
        return $ids[$idx + 1];
    }

    public static function kindOf(string $stepId): string
    {
        foreach (self::definitions() as $def) {
            if ($def['id'] === $stepId) {
                return $def['kind'];
            }
        }
        return '';
    }

    private static function indexOf(string $stepId): int
    {
        $ids = array_map(static fn (array $d): string => $d['id'], self::definitions());
        $idx = array_search($stepId, $ids, true);
        return $idx === false ? 0 : (int) $idx;
    }

    /** @return array<string,mixed> */
    private static function emptyStep(string $stepId): array
    {
        return [
            'id' => $stepId,
            'status' => self::STEP_PENDING,
            'started_at' => null,
            'armed_at' => null,
            'first_present_at' => null,
            'finished_at' => null,
            'baseline_marker' => '',
            'detail' => [],
            'recommendation' => null,
            'decision' => null,
        ];
    }

    private static function startMessage(string $stepId): string
    {
        return match ($stepId) {
            self::SEES_YOU => 'Midiendo 10 s. Muévete ligeramente dentro de la zona.',
            self::WALK => 'Camina hacia atrás. Cuando deje de detectarte en el punto que querías, pulsa «Prueba buena».',
            self::SENSITIVITY => 'Midiendo 15 s. Muévete de forma natural.',
            self::OUTSIDE_1, self::OUTSIDE_2 => 'Sal y espera a que el radar deje de detectarte.',
            self::INSIDE_1 => 'Sal y espera a que el radar deje de detectarte. Después entra.',
            default => 'Paso en curso.',
        };
    }

    /**
     * @param array<string,mixed> $step
     * @param list<array<string,mixed>> $presence
     * @return array{step:array<string,mixed>,result:string,reason?:string,message?:string,detail:array<string,mixed>}
     */
    private static function evaluateAbsent(
        array $step,
        string $startedAt,
        string $now,
        array $presence,
        string $authentic,
        string $authenticMarker
    ): array {
        $duration = self::OUTSIDE_SECONDS;
        $elapsed = self::seconds($startedAt, $now);
        $armedAt = (string) ($step['armed_at'] ?? '');
        $detail = (array) ($step['detail'] ?? []);

        if ($armedAt === '') {
            if ($authentic === 'ABSENT') {
                $step['armed_at'] = $now;
                $step['baseline_marker'] = $authenticMarker;
                $step['detail'] = array_merge($detail, [
                    'message' => 'Contador iniciado. Permanece fuera; no debe detectarte.',
                    'armed_at' => $now,
                ]);
                return ['step' => $step, 'result' => 'waiting', 'detail' => $step['detail']];
            }
            if ($authentic === 'PRESENT') {
                $step['detail'] = array_merge($detail, [
                    'message' => 'Aún te detecta. Sal y espera a que el radar te pierda.',
                    'waiting_for' => 'absent',
                ]);
                if ($elapsed > $duration + self::ARM_GRACE_SECONDS) {
                    return ['step' => $step, 'result' => 'failed', 'reason' => 'no_absent',
                        'message' => 'No se ha detectado la ausencia. Sal de la sala y espera a que el radar deje de verte.',
                        'detail' => $step['detail']];
                }
                return ['step' => $step, 'result' => 'waiting', 'detail' => $step['detail']];
            }
            $step['detail'] = array_merge($detail, [
                'message' => 'Sin datos del radar. Comprueba alimentación y red Zigbee.',
                'waiting_for' => 'state',
            ]);
            if ($elapsed > $duration + self::ARM_GRACE_SECONDS) {
                return ['step' => $step, 'result' => 'failed', 'reason' => 'no_state',
                    'message' => 'El radar no reporta estado de presencia.', 'detail' => $step['detail']];
            }
            return ['step' => $step, 'result' => 'waiting', 'detail' => $step['detail']];
        }

        $armedMarker = (string) ($step['baseline_marker'] ?? '');
        if (self::hasPresentSince($presence, $armedAt, $armedMarker) || $authentic === 'PRESENT') {
            $step['detail'] = array_merge($detail, [
                'message' => 'Te ha detectado fuera. El paso no se supera: reajusta el alcance o la sensibilidad y reintenta.',
                'result' => 'detected_outside',
            ]);
            return ['step' => $step, 'result' => 'failed', 'reason' => 'detected_outside',
                'message' => 'Te ha detectado fuera de la sala.', 'detail' => $step['detail']];
        }
        $elapsedArmed = self::seconds($armedAt, $now);
        if ($elapsedArmed >= $duration) {
            $step['detail'] = array_merge($detail, [
                'message' => 'Correcto: ' . $duration . ' s sin detección.',
                'result' => 'absent_window',
            ]);
            return ['step' => $step, 'result' => 'passed', 'detail' => $step['detail']];
        }
        $remaining = max(0, (int) ceil($duration - $elapsedArmed));
        $step['detail'] = array_merge($detail, [
            'message' => 'Fuera. Quedan ' . $remaining . ' s sin detección.',
            'countdown' => $remaining,
            'waiting_for' => 'absent_window',
        ]);
        return ['step' => $step, 'result' => 'waiting', 'detail' => $step['detail']];
    }

    /**
     * @param array<string,mixed> $step
     * @param list<array<string,mixed>> $presence
     * @return array{step:array<string,mixed>,result:string,reason?:string,message?:string,detail:array<string,mixed>}
     */
    private static function evaluatePresent(
        array $step,
        string $startedAt,
        string $now,
        array $presence,
        string $authentic,
        string $authenticMarker,
        bool $requiresAbsent
    ): array {
        $duration = $requiresAbsent ? self::INSIDE_SECONDS : self::SEES_YOU_SECONDS;
        $elapsed = self::seconds($startedAt, $now);
        $armedAt = (string) ($step['armed_at'] ?? '');
        $detail = (array) ($step['detail'] ?? []);

        if ($requiresAbsent && $armedAt === '') {
            if ($authentic === 'ABSENT') {
                $step['armed_at'] = $now;
                $step['baseline_marker'] = $authenticMarker;
                $step['detail'] = array_merge($detail, [
                    'message' => 'Ahora entra. Esperando presencia.',
                    'armed_at' => $now,
                ]);
                return ['step' => $step, 'result' => 'waiting', 'detail' => $step['detail']];
            }
            if ($authentic === 'PRESENT') {
                $step['detail'] = array_merge($detail, [
                    'message' => 'Sal primero y espera a que el radar no te detecte.',
                    'waiting_for' => 'absent',
                ]);
                if ($elapsed > $duration + self::ARM_GRACE_SECONDS) {
                    return ['step' => $step, 'result' => 'failed', 'reason' => 'no_absent',
                        'message' => 'No se ha detectado la ausencia. Sal de la sala y espera a que el radar deje de verte.',
                        'detail' => $step['detail']];
                }
                return ['step' => $step, 'result' => 'waiting', 'detail' => $step['detail']];
            }
            $step['detail'] = array_merge($detail, [
                'message' => 'Sin datos del radar. Comprueba alimentación y red Zigbee.',
                'waiting_for' => 'state',
            ]);
            if ($elapsed > $duration + self::ARM_GRACE_SECONDS) {
                return ['step' => $step, 'result' => 'failed', 'reason' => 'no_state',
                    'message' => 'El radar no reporta estado de presencia.', 'detail' => $step['detail']];
            }
            return ['step' => $step, 'result' => 'waiting', 'detail' => $step['detail']];
        }

        $armedAt = $armedAt !== '' ? $armedAt : $startedAt;
        $armedMarker = (string) ($step['baseline_marker'] ?? '');
        $newPresent = self::hasPresentSince($presence, $armedAt, $armedMarker) || $authentic === 'PRESENT';

        if ($newPresent) {
            if ($authentic === 'ABSENT') {
                $step['first_present_at'] = null;
                $step['detail'] = array_merge($detail, [
                    'message' => 'Presencia perdida. Vuelve a la zona o espera a que te detecte.',
                    'waiting_for' => 'present',
                ]);
                return ['step' => $step, 'result' => 'waiting', 'detail' => $step['detail']];
            }
            if (($step['first_present_at'] ?? null) === null) {
                $step['first_present_at'] = $now;
            }
            $first = (string) ($step['first_present_at'] ?? $now);
            $stable = self::seconds($first, $now);
            $elapsedArmed = self::seconds($armedAt, $now);
            // En «¿te ve?» se respeta la ventana completa de 10 s. En la
            // reentrada basta con 3 s de presencia estable dentro del timeout.
            $pass = $requiresAbsent
                ? ($stable >= self::PRESENT_STABLE_SECONDS)
                : ($elapsedArmed >= $duration);
            if ($pass) {
                $step['detail'] = array_merge($detail, [
                    'message' => 'Presencia detectada y estable.',
                    'result' => 'present_confirmed',
                ]);
                return ['step' => $step, 'result' => 'passed', 'detail' => $step['detail']];
            }
            $remaining = $requiresAbsent
                ? max(0, (int) ceil(self::PRESENT_STABLE_SECONDS - $stable))
                : max(0, (int) ceil($duration - $elapsedArmed));
            $step['detail'] = array_merge($detail, [
                'message' => $requiresAbsent
                    ? 'Detectado. Confirmando estabilidad (' . $remaining . ' s)…'
                    : 'Detectado. Permanece en la zona (' . $remaining . ' s)…',
                'countdown' => $remaining,
                'waiting_for' => 'stable',
            ]);
            return ['step' => $step, 'result' => 'waiting', 'detail' => $step['detail']];
        }

        $step['first_present_at'] = null;
        $elapsedArmed = self::seconds($armedAt, $now);
        if ($elapsedArmed >= $duration) {
            $step['detail'] = array_merge($detail, [
                'message' => $requiresAbsent
                    ? 'No te ha detectado al entrar. Revisa sensibilidad/alcance y reintenta.'
                    : 'No te ha detectado. Revisa sensibilidad, colocación o alimentación.',
                'result' => 'no_presence',
            ]);
            return ['step' => $step, 'result' => 'failed', 'reason' => 'no_presence',
                'message' => $requiresAbsent
                    ? 'No te ha detectado al entrar.'
                    : 'No te ha detectado dentro de la zona.',
                'detail' => $step['detail']];
        }
        $remaining = max(0, (int) ceil($duration - $elapsedArmed));
        $step['detail'] = array_merge($detail, [
            'message' => $requiresAbsent
                ? 'Esperando que entres… quedan ' . $remaining . ' s.'
                : 'Esperando presencia… quedan ' . $remaining . ' s.',
            'countdown' => $remaining,
            'waiting_for' => 'present',
        ]);
        return ['step' => $step, 'result' => 'waiting', 'detail' => $step['detail']];
    }

    /**
     * @param list<array<string,mixed>> $presence
     * @param list<array<string,mixed>> $distances
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private static function walkInfo(
        string $startedAt,
        string $now,
        array $presence,
        array $distances,
        array $settings
    ): array {
        $min = is_numeric($settings['minimum_range'] ?? null) ? (float) $settings['minimum_range'] : 0.0;
        $max = is_numeric($settings['maximum_range'] ?? null) ? (float) $settings['maximum_range'] : 9.5;
        $boundary = RadarAutoTune::boundary(
            $distances,
            $presence,
            $startedAt,
            $now,
            RadarAutoTune::DEFAULT_MARGIN_M,
            $min,
            $max
        );
        $lastDistance = null;
        $lastAge = null;
        $sinceMs = RadarAutoTune::toMs($startedAt) ?? 0;
        $nowMs = RadarAutoTune::toMs($now) ?? (int) round(microtime(true) * 1000);
        $normalized = RadarAutoTune::normalizeDistances($distances, $sinceMs, $nowMs);
        if ($normalized !== []) {
            $last = $normalized[count($normalized) - 1];
            $lastDistance = round((float) $last['distance_m'], 3);
            $lastAge = round(($nowMs - (int) $last['t_ms']) / 1000, 1);
        }
        $loss = ($boundary['found'] ?? false) === true && isset($boundary['distance_m'])
            ? round((float) $boundary['distance_m'], 3) : null;
        $message = $loss !== null
            ? 'Pérdida detectada a ' . number_format($loss, 2, ',', '') . ' m (alcance configurado '
                . number_format($max, 2, ',', '') . ' m). Pulsa «Prueba buena» si es el punto que querías.'
            : 'Aún no se ha detectado la pérdida. Sigue alejándote; el sistema no decide por ti.';
        return [
            'last_distance' => $lastDistance,
            'last_distance_age_s' => $lastAge,
            'loss_distance' => $loss,
            'loss_at' => $boundary['measured_at'] ?? null,
            'configured_max' => $max,
            'message' => $message,
        ];
    }

    private static function seconds(?string $from, ?string $to): float
    {
        $a = RadarAutoTune::toMs($from ?? '');
        $b = RadarAutoTune::toMs($to ?? '');
        if ($a === null || $b === null) {
            return 0.0;
        }
        return max(0.0, ($b - $a) / 1000);
    }

    /** @param list<array<string,mixed>> $reports */
    private static function hasPresentSince(array $reports, string $since, string $baselineMarker = ''): bool
    {
        $sinceMs = RadarAutoTune::toMs($since);
        if ($sinceMs === null) {
            return false;
        }
        $until = (int) round(microtime(true) * 1000) + 60000;
        $normalized = RadarAutoTune::normalizePresence($reports, $sinceMs, $until);
        foreach ($normalized as $report) {
            if ($report['value'] !== 'PRESENT') {
                continue;
            }
            if ($baselineMarker !== '' && $report['marker'] === $baselineMarker) {
                continue;
            }
            return true;
        }
        return false;
    }

    /** @param array<string,mixed> $settings @return array<string,float|int> */
    private static function numericSettings(array $settings): array
    {
        $out = [];
        foreach (['maximum_range', 'minimum_range', 'radar_sensitivity', 'detection_delay', 'fading_time'] as $key) {
            if (isset($settings[$key]) && is_numeric($settings[$key])) {
                $out[$key] = $key === 'radar_sensitivity'
                    ? (int) round((float) $settings[$key])
                    : round((float) $settings[$key], 3);
            }
        }
        return $out;
    }
}
