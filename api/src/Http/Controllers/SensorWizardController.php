<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepository;
use App\Domain\Presence\DoorPolarity;
use App\Domain\Sensors\SensorCalibrationValidator;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Gateways\Switch\ZigbeeSwitchGateway;
use App\Infrastructure\Sensors\SensorContextBuilder;
use App\Infrastructure\Sensors\SensorRunStore;
use App\Infrastructure\Sensors\SensorVerdict;
use App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService;
use PDO;
use Throwable;

/**
 * SensorWizardController — F95/RF-162…RF-169.
 *
 * API del flujo universal de verificación (Zigbee): preflight, corrida,
 * intentos inmutables, polaridad automática, ajuste del radar, prueba de luz,
 * veredicto y reposo diferido.
 *
 * Solo lectura de BD/MQTT bajo demanda. Sin pollers de Tuya.
 */
final class SensorWizardController
{
    /** @var callable():PDO */
    private $pdoProvider;
    private SensorRunStore $store;
    private string $catalogFile;

    public function __construct(callable $pdoProvider, string $runsDir, string $catalogFile)
    {
        $this->pdoProvider = $pdoProvider;
        $this->store = new SensorRunStore($runsDir);
        $this->catalogFile = $catalogFile;
    }

    public function rooms(Request $request): Response
    {
        try {
            $rows = $this->pdo()->query(
                "SELECT r.id, r.code, rt.code AS room_type_code
                   FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id
                  WHERE r.pack_id IS NOT NULL
                  ORDER BY r.code ASC"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return Response::json(200, array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'code' => (string) $r['code'],
                'room_type_code' => (string) ($r['room_type_code'] ?? ''),
            ], $rows));
        } catch (Throwable $e) {
            return Response::json(500, ['error' => $e->getMessage()]);
        }
    }

    public function preflight(Request $request): Response
    {
        $roomId = (int) ($request->query['room_id'] ?? 0);
        if ($roomId <= 0) {
            return Response::json(400, ['error' => 'room_id required']);
        }
        try {
            $ctx = $this->context();
            $data = $ctx->preflight($roomId);
            if (empty($data['room']['code'])) {
                return Response::json(404, ['error' => 'room_not_found']);
            }
            $active = $this->store->findActiveAttemptForRoom($roomId);
            $data['active_attempt'] = $active;
            return Response::json(200, $data)->withHeader('Cache-Control', 'no-store');
        } catch (Throwable $e) {
            return Response::json(500, ['error' => $e->getMessage()]);
        }
    }

    public function startRun(Request $request): Response
    {
        $body = $request->jsonBody ?? [];
        $roomId = (int) ($body['room_id'] ?? 0);
        if ($roomId <= 0) {
            return Response::json(400, ['error' => 'room_id required']);
        }
        try {
            $ctx = $this->context();
            $pre = $ctx->preflight($roomId);
            if (empty($pre['room']['code'])) {
                return Response::json(404, ['error' => 'room_not_found']);
            }
            if (empty($pre['can_start'])) {
                return Response::json(409, [
                    'error' => 'core_devices_missing',
                    'issues' => $pre['issues'],
                    'message' => 'Falta la puerta o el radar de presencia.',
                ]);
            }
            $run = $this->store->create([
                'room_id' => $roomId,
                'room' => $pre['room'],
                'operator' => (string) ($body['operator'] ?? ''),
                'catalog' => 'sensores',
                'catalog_version' => 2,
                'mode' => 'universal',
                'plan' => $pre['plan'],
                'initial_settings' => $pre['settings'],
                'initial_settings_at' => $pre['settings_at'],
                'devices' => [
                    'presence' => $pre['devices']['presence'],
                    'proximity' => $pre['devices']['proximity'],
                    'switch' => $pre['devices']['switch'],
                    'transport' => $pre['devices']['transport'],
                ],
            ]);
            return Response::json(201, ['ok' => true, 'run' => $run, 'plan' => $pre['plan']]);
        } catch (Throwable $e) {
            return Response::json(500, ['error' => $e->getMessage()]);
        }
    }

    public function startAttempt(Request $request): Response
    {
        $body = $request->jsonBody ?? [];
        $runId = (string) ($body['run_id'] ?? '');
        $stepId = (string) ($body['step_id'] ?? '');
        $run = $this->store->load($runId);
        if ($run === null) {
            return Response::json(404, ['error' => 'run_not_found']);
        }
        if (($run['status'] ?? '') !== 'running') {
            return Response::json(409, ['error' => 'run_not_running']);
        }
        if (!$this->stepExists($stepId, $run)) {
            return Response::json(404, ['error' => 'step_not_found']);
        }
        if ($stepId === 'door' && empty($run['door_polarity']['checked_at'])) {
            return Response::json(409, [
                'error' => 'polarity_not_checked',
                'message' => 'Comprueba primero la polaridad con la puerta cerrada.',
            ]);
        }
        $active = $this->store->findActiveAttemptForRoom((int) $run['room_id']);
        if ($active !== null) {
            return Response::json(409, [
                'error' => 'attempt_in_progress',
                'active' => $active,
                'message' => 'Hay otra prueba en curso en esta sala.',
            ]);
        }
        try {
            $ctx = $this->context();
            $attemptId = 'a-' . bin2hex(random_bytes(5));
            $startedAt = gmdate('Y-m-d\TH:i:s.v\Z');
            $attempt = [
                'attempt_id' => $attemptId,
                'step_id' => $stepId,
                'started_at' => $startedAt,
                'checked_at' => null,
                'status' => 'ACTIVE',
                'initial_live' => $ctx->live((int) $run['room_id']),
                'initial_settings' => $this->latestSettings((int) $run['room_id']),
                'initial_effects' => $ctx->effects((int) $run['room_id']),
                'initial_door_polarity' => $this->doorPolarity((int) $run['room_id']),
                'human' => null,
                'check_id' => null,
                'verdict' => null,
                'evidence' => null,
            ];
            $run = $this->store->withLock($runId, static function (array $r) use ($stepId, $attempt): array {
                $r['steps'][$stepId] = array_merge((array) ($r['steps'][$stepId] ?? []), [
                    'status' => 'IN_PROGRESS',
                    'active_attempt' => $attempt,
                    'attempts' => (array) ($r['steps'][$stepId]['attempts'] ?? []),
                    'title' => (string) ($r['steps'][$stepId]['title'] ?? $stepId),
                ]);
                return $r;
            });
            $this->store->appendLog($runId, ['type' => 'attempt_start', 'step_id' => $stepId,
                'attempt_id' => $attemptId, 'at' => $startedAt]);
            return Response::json(201, ['ok' => true, 'attempt' => $attempt, 'step' => $run['steps'][$stepId]]);
        } catch (Throwable $e) {
            return Response::json(500, ['error' => $e->getMessage()]);
        }
    }

    public function checkAttempt(Request $request): Response
    {
        $body = $request->jsonBody ?? [];
        $runId = (string) ($body['run_id'] ?? '');
        $stepId = (string) ($body['step_id'] ?? '');
        $attemptId = (string) ($body['attempt_id'] ?? '');
        $checkId = (string) ($body['check_id'] ?? '');
        $human = is_array($body['human'] ?? null) ? $body['human'] : [];
        $run = $this->store->load($runId);
        if ($run === null) {
            return Response::json(404, ['error' => 'run_not_found']);
        }
        $step = $run['steps'][$stepId] ?? [];
        $active = $step['active_attempt'] ?? null;
        if (!is_array($active) || ($active['attempt_id'] ?? '') !== $attemptId) {
            // Idempotencia: devolver un intento ya validado con el mismo check_id.
            foreach ((array) ($step['attempts'] ?? []) as $old) {
                if (($old['attempt_id'] ?? '') === $attemptId && ($old['check_id'] ?? '') === $checkId) {
                    return Response::json(200, ['ok' => true, 'idempotent' => true,
                        'status' => $old['status'] ?? null, 'attempt' => $old,
                        'summary' => $run['summary'] ?? null]);
                }
            }
            return Response::json(409, ['error' => 'attempt_not_active']);
        }
        $catalogStep = $this->catalogStep($stepId);
        if ($catalogStep === null) {
            return Response::json(404, ['error' => 'step_not_found']);
        }
        try {
            $ctx = $this->context();
            $initialEffects = (array) ($active['initial_effects'] ?? []);
            $initialSettings = (array) ($run['calibration']['initial_settings'] ?? []);
            $data = $ctx->context(
                (int) $run['room_id'],
                (string) $active['started_at'],
                $initialEffects,
                $initialSettings
            );
            $data['initial_live'] = (array) ($active['initial_live'] ?? []);
            $data['attempt_started_at'] = (string) $active['started_at'];
            $data['door_polarity'] = $this->doorPolarity((int) $run['room_id']);
            $data['human'] = $human;
            $data['switch'] = array_merge($ctx->switchSnapshot((int) $run['room_id']),
                ['present' => $this->hasKind((int) $run['room_id'], Device::KIND_SWITCH)]);
            $verdict = SensorCalibrationValidator::validate(
                (string) ($catalogStep['rule'] ?? 'manual'),
                (array) ($catalogStep['params'] ?? []),
                $data
            );
            $checkedAt = gmdate('Y-m-d\TH:i:s.v\Z');
            $record = array_merge($active, [
                'checked_at' => $checkedAt,
                'status' => $verdict['status'],
                'check_id' => $checkId,
                'human' => $human,
                'verdict' => $verdict,
                'evidence' => [
                    'elapsed_seconds' => $data['elapsed_seconds'],
                    'live' => $data['live'],
                    'initial_live' => $data['initial_live'],
                    'settings' => $data['settings'],
                    'settings_age_seconds' => $data['settings_age_seconds'],
                    'effects' => $data['effects'],
                    'effects_delta' => $data['effects_delta'],
                    'door_polarity' => $data['door_polarity'],
                    'switch' => $data['switch'],
                    'event_counts' => [
                        'total' => count($data['events']),
                        'presence' => count(array_filter($data['events'], static fn ($e) => ($e['sensor'] ?? '') === 'PRESENCE')),
                        'door' => count(array_filter($data['events'], static fn ($e) => ($e['sensor'] ?? '') === 'PROXIMITY')),
                    ],
                ],
            ]);
            $run = $this->store->withLock($runId, static function (array $r) use ($stepId, $record, $verdict): array {
                $stepState = (array) ($r['steps'][$stepId] ?? []);
                unset($stepState['active_attempt']);
                $stepState['status'] = $verdict['status'];
                $stepState['attempts'][] = $record;
                $r['steps'][$stepId] = $stepState;
                $r['summary'] = self::summary($r);
                return $r;
            });
            $this->store->appendLog($runId, [
                'type' => 'attempt_check',
                'step_id' => $stepId,
                'attempt_id' => $attemptId,
                'status' => $verdict['status'],
                'reason' => $verdict['reason'],
                'verdict' => $verdict,
                'effects_delta' => $record['evidence']['effects_delta'],
                'settings' => $record['evidence']['settings'],
                'events' => array_slice((array) $data['events'], 0, 2000),
            ]);
            return Response::json(200, [
                'ok' => true,
                'status' => $verdict['status'],
                'verdict' => $verdict,
                'attempt' => $record,
                'summary' => $run['summary'],
            ]);
        } catch (Throwable $e) {
            return Response::json(500, ['error' => $e->getMessage()]);
        }
    }

    public function cancelAttempt(Request $request): Response
    {
        $body = $request->jsonBody ?? [];
        $runId = (string) ($body['run_id'] ?? '');
        $stepId = (string) ($body['step_id'] ?? '');
        $attemptId = (string) ($body['attempt_id'] ?? '');
        $run = $this->store->load($runId);
        if ($run === null) {
            return Response::json(404, ['error' => 'run_not_found']);
        }
        $active = $run['steps'][$stepId]['active_attempt'] ?? null;
        if (!is_array($active) || ($active['attempt_id'] ?? '') !== $attemptId) {
            return Response::json(409, ['error' => 'attempt_not_active']);
        }
        $run = $this->store->withLock($runId, static function (array $r) use ($stepId): array {
            unset($r['steps'][$stepId]['active_attempt']);
            $r['steps'][$stepId]['status'] = 'PENDING';
            return $r;
        });
        $this->store->appendLog($runId, ['type' => 'attempt_cancel', 'step_id' => $stepId,
            'attempt_id' => $attemptId]);
        return Response::json(200, ['ok' => true, 'step' => $run['steps'][$stepId]]);
    }

    public function polarity(Request $request): Response
    {
        $body = $request->jsonBody ?? [];
        $roomId = (int) ($body['room_id'] ?? 0);
        $physical = strtoupper((string) ($body['physical'] ?? 'CLOSED'));
        $restore = (bool) ($body['restore'] ?? false);
        $runId = (string) ($body['run_id'] ?? '');
        if ($roomId <= 0) {
            return Response::json(400, ['error' => 'room_id required']);
        }
        if (!$restore && $physical !== 'CLOSED') {
            return Response::json(400, ['error' => 'physical_must_be_closed']);
        }
        try {
            $pdo = $this->pdo();
            $devices = new DeviceRepository($pdo);
            $device = $devices->findForRoomKind($roomId, Device::KIND_PROXIMITY);
            if ($device === null) {
                return Response::json(404, ['error' => 'door_device_missing']);
            }
            if ($restore) {
                $meta = DoorPolarity::withPolarity($device->meta, DoorPolarity::NORMAL);
                $devices->updateMeta((int) $device->id, $meta);
                // Se desconoce el estado canónico hasta el próximo reporte.
                $pdo->prepare(
                    "UPDATE iot_sessions SET door_state='UNKNOWN', last_door_value=NULL
                      WHERE room_id = :rid"
                )->execute([':rid' => $roomId]);
                $this->recordPolarity($runId, DoorPolarity::NORMAL, true);
                return Response::json(200, ['ok' => true, 'polarity' => DoorPolarity::NORMAL,
                    'restored' => true, 'raw' => null, 'needs_report' => true,
                    'session_state' => 'UNKNOWN']);
            }
            $stmt = $pdo->prepare(
                "SELECT value, occurred_at, meta_json FROM presence_events
                  WHERE room_id = :rid AND sensor = 'PROXIMITY'
                  ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([':rid' => $roomId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return Response::json(422, ['error' => 'waiting_for_report',
                    'message' => 'Abre y cierra la puerta una vez para que el sensor envíe un evento.']);
            }
            $at = strtotime((string) $row['occurred_at'] . ' UTC');
            if ($at === false || time() - $at > 90) {
                return Response::json(422, ['error' => 'waiting_for_report',
                    'message' => 'El último evento de la puerta es antiguo. Abre y cierra la puerta una vez.']);
            }
            $meta = json_decode((string) ($row['meta_json'] ?? ''), true);
            $raw = is_array($meta) ? ($meta['zigbee_value'] ?? null) : null;
            if ($raw === null && is_array($meta) && isset($meta['canonical_before_polarity'])) {
                $rawCanonical = (string) $meta['canonical_before_polarity'];
                $raw = $rawCanonical === 'OPEN' ? 'true' : 'false';
            }
            if ($raw === null) {
                return Response::json(422, ['error' => 'raw_value_unavailable']);
            }
            $rawClosed = ($raw === false || $raw === 'false' || $raw === 0 || $raw === '0');
            $polarity = $rawClosed ? DoorPolarity::NORMAL : DoorPolarity::INVERTED;
            $newMeta = DoorPolarity::withPolarity($device->meta, $polarity);
            $devices->updateMeta((int) $device->id, $newMeta);
            // El operador ha confirmado que la puerta está CERRADA. Corregimos
            // también el estado de sesión para que el primer ciclo posterior
            // (OPEN → CLOSED) se aplique con la nueva polaridad.
            $pdo->prepare(
                "UPDATE iot_sessions
                    SET door_state='CLOSED', last_door_value='CLOSED',
                        last_door_event_at=UTC_TIMESTAMP(3), last_close_at=UTC_TIMESTAMP(3)
                  WHERE room_id = :rid"
            )->execute([':rid' => $roomId]);
            $this->recordPolarity($runId, $polarity, false);
            return Response::json(200, [
                'ok' => true,
                'polarity' => $polarity,
                'raw' => $raw,
                'needs_report' => false,
                'session_state' => 'CLOSED',
                'message' => $polarity === DoorPolarity::INVERTED
                    ? 'Montaje invertido detectado y corregido solo en este dispositivo.'
                    : 'Polaridad correcta.',
            ]);
        } catch (Throwable $e) {
            return Response::json(500, ['error' => $e->getMessage()]);
        }
    }

    public function tune(Request $request): Response
    {
        $body = $request->jsonBody ?? [];
        $runId = (string) ($body['run_id'] ?? '');
        $stepId = (string) ($body['step_id'] ?? '');
        $attemptId = (string) ($body['attempt_id'] ?? '');
        $phase = (string) ($body['phase'] ?? 'inside');
        $run = $this->store->load($runId);
        if ($run === null) {
            return Response::json(404, ['error' => 'run_not_found']);
        }
        $active = $run['steps'][$stepId]['active_attempt'] ?? null;
        if (!is_array($active) || ($active['attempt_id'] ?? '') !== $attemptId) {
            return Response::json(409, ['error' => 'attempt_not_active']);
        }
        try {
            $pdo = $this->pdo();
            $ctx = $this->context();
            $since = (string) $active['started_at'];
            $data = $ctx->context((int) $run['room_id'], $since, (array) ($active['initial_effects'] ?? []));
            $detected = $this->realPresent($data['events'], $phase === 'inside' ? 20 : 0);
            if ($phase === 'inside' && $detected) {
                return Response::json(200, ['action' => 'none', 'detected' => true,
                    'settings' => $data['settings'], 'message' => 'Ya detecta; no hace falta ajustar.']);
            }
            if ($phase === 'outside' && !$detected) {
                return Response::json(200, ['action' => 'none', 'detected' => false,
                    'settings' => $data['settings'], 'message' => 'No detecta; no hace falta ajustar.']);
            }
            $settings = (array) $data['settings'];
            $sens = isset($settings['radar_sensitivity']) ? (float) $settings['radar_sensitivity'] : 7.0;
            $max = isset($settings['maximum_range']) ? (float) $settings['maximum_range'] : 6.0;
            $params = [];
            if ($phase === 'inside') {
                if ($sens < 9) {
                    $params['radar_sensitivity'] = min(9, $sens + 2);
                } elseif ($max < 9.45) {
                    $params['maximum_range'] = min(9.45, $max + 0.75);
                } else {
                    return Response::json(200, ['action' => 'exhausted', 'detected' => false,
                        'settings' => $settings, 'message' => 'No quedan ajustes automáticos; revisa la orientación.']);
                }
            } else {
                if ($max > 0.75) {
                    $params['maximum_range'] = max(0.75, $max - 0.75);
                } elseif ($sens > 0) {
                    $params['radar_sensitivity'] = max(0, $sens - 1);
                } else {
                    return Response::json(200, ['action' => 'exhausted', 'detected' => true,
                        'settings' => $settings, 'message' => 'No quedan ajustes; revisa el montaje.']);
                }
            }
            $devices = new DeviceRepository($pdo);
            $presence = $devices->findForRoomKind((int) $run['room_id'], Device::KIND_PRESENCE);
            if ($presence === null) {
                return Response::json(404, ['error' => 'presence_device_missing']);
            }
            $cal = new ZigbeeRadarCalibrationService($pdo);
            $result = $cal->apply((int) $run['room_id'], [
                'id' => (int) $presence->id,
                'external_id' => (string) $presence->externalId,
                'meta' => is_array($presence->meta) ? $presence->meta : [],
            ], $params, true);
            return Response::json(200, [
                'action' => 'applied',
                'phase' => $phase,
                'detected' => $detected,
                'requested' => $params,
                'applied' => (bool) ($result['applied'] ?? false),
                'warning' => $result['warning'] ?? null,
                'settings' => $result['status']['settings'] ?? $settings,
            ]);
        } catch (Throwable $e) {
            return Response::json(500, ['error' => $e->getMessage()]);
        }
    }

    public function switchTest(Request $request): Response
    {
        $body = $request->jsonBody ?? [];
        $roomId = (int) ($body['room_id'] ?? 0);
        $action = strtoupper((string) ($body['action'] ?? ''));
        if ($roomId <= 0 || !in_array($action, ['ON', 'OFF'], true)) {
            return Response::json(400, ['error' => 'room_id and action ON/OFF required']);
        }
        try {
            $pdo = $this->pdo();
            $deviceRepo = new DeviceRepository($pdo);
            $device = $deviceRepo->findForRoomKind($roomId, Device::KIND_SWITCH);
            if ($device === null) {
                return Response::json(404, ['error' => 'switch_not_found']);
            }
            $gateway = new ZigbeeSwitchGateway($deviceRepo);
            $result = $action === 'ON'
                ? $gateway->turnOnForDevice($device)
                : $gateway->turnOffForDevice($device);
            $reported = $this->waitSwitchState($deviceRepo, (int) $device->id, $action, 6);
            return Response::json(200, [
                'ok' => (bool) ($result['ok'] ?? false),
                'action' => $action,
                'error' => $result['error'] ?? null,
                'pending_confirmation' => (bool) ($result['pending_confirmation'] ?? false),
                'reported_state' => $reported,
            ]);
        } catch (Throwable $e) {
            return Response::json(500, ['error' => $e->getMessage()]);
        }
    }

    public function finish(Request $request): Response
    {
        $body = $request->jsonBody ?? [];
        $runId = (string) ($body['run_id'] ?? '');
        $run = $this->store->load($runId);
        if ($run === null) {
            return Response::json(404, ['error' => 'run_not_found']);
        }
        try {
            $catalog = $this->catalog();
            $run['status'] = 'finished';
            $run['finished_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $outsideAttempt = $run['steps']['radar_outside']['attempts'][0] ?? null;
            $outside = is_array($outsideAttempt) ? ($outsideAttempt['checked_at'] ?? null) : null;
            $baseline = is_array($outsideAttempt)
                ? (array) ($outsideAttempt['evidence']['effects'] ?? [])
                : [];
            $run['soak'] = [
                'status' => 'PENDING',
                'since' => $outside ?? gmdate('Y-m-d\TH:i:s\Z'),
                'checked_at' => null,
                'result' => null,
                'initial_effects' => $baseline,
            ];
            $run['verdict'] = SensorVerdict::compute($run, $catalog);
            $run['summary'] = self::summary($run);
            $this->store->save($run);
            $this->store->appendLog($runId, ['type' => 'run_finish', 'verdict' => $run['verdict']]);
            return Response::json(200, ['ok' => true, 'run' => $run]);
        } catch (Throwable $e) {
            return Response::json(500, ['error' => $e->getMessage()]);
        }
    }

    public function soak(Request $request): Response
    {
        $body = $request->jsonBody ?? [];
        $runId = (string) ($body['run_id'] ?? '');
        $run = $this->store->load($runId);
        if ($run === null) {
            return Response::json(404, ['error' => 'run_not_found']);
        }
        if (($run['status'] ?? '') !== 'finished') {
            return Response::json(409, ['error' => 'run_not_finished']);
        }
        $since = (string) ($run['soak']['since'] ?? $run['created_at'] ?? '');
        $sinceTs = strtotime($since . ' UTC');
        if ($sinceTs === false) {
            return Response::json(400, ['error' => 'invalid_soak_since']);
        }
        $elapsed = max(0, time() - $sinceTs);
        if ($elapsed < 1800) {
            return Response::json(422, [
                'error' => 'too_early',
                'elapsed_seconds' => $elapsed,
                'remaining_seconds' => 1800 - $elapsed,
            ]);
        }
        try {
            $ctx = $this->context();
            $data = $ctx->context((int) $run['room_id'], gmdate('Y-m-d\TH:i:s\Z', $sinceTs),
                (array) ($run['soak']['initial_effects'] ?? []));
            $presents = array_values(array_filter($data['events'], static function (array $e): bool {
                if (strtoupper((string) ($e['sensor'] ?? '')) !== 'PRESENCE') return false;
                if ((string) ($e['value'] ?? '') !== 'PRESENT') return false;
                $src = strtolower((string) (($e['meta'] ?? [])['source'] ?? ''));
                return $src !== 'resync';
            }));
            $falseEffects = 0;
            foreach (['visits', 'recordings', 'stays'] as $key) {
                $falseEffects += abs((int) ($data['effects_delta'][$key] ?? 0));
            }
            $ok = $presents === [] && $falseEffects === 0;
            $run['soak'] = [
                'status' => $ok ? 'PASS' : 'FAIL',
                'since' => $since,
                'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'result' => [
                    'real_presents' => count($presents),
                    'false_effects' => $falseEffects,
                    'elapsed_seconds' => $elapsed,
                ],
            ];
            $run['verdict'] = SensorVerdict::compute($run, $this->catalog());
            $this->store->save($run);
            $this->store->appendLog($runId, ['type' => 'soak', 'status' => $run['soak']['status'],
                'result' => $run['soak']['result']]);
            return Response::json(200, ['ok' => true, 'soak' => $run['soak'], 'verdict' => $run['verdict']]);
        } catch (Throwable $e) {
            return Response::json(500, ['error' => $e->getMessage()]);
        }
    }

    public function runs(Request $request): Response
    {
        $list = array_values(array_filter($this->store->listRuns(80), static function (array $r): bool {
            return (int) ($r['catalog_version'] ?? 1) >= 2;
        }));
        return Response::json(200, $list);
    }

    public function getRun(Request $request): Response
    {
        $run = $this->store->load((string) ($request->query['id'] ?? ''));
        if ($run === null) {
            return Response::json(404, ['error' => 'run_not_found']);
        }
        return Response::json(200, $run);
    }

    public function events(Request $request): Response
    {
        $roomId = (int) ($request->query['room_id'] ?? 0);
        if ($roomId <= 0) {
            return Response::json(400, ['error' => 'room_id required']);
        }
        $ctx = $this->context();
        return Response::json(200, [
            'room_id' => $roomId,
            'events' => $ctx->latestEvents($roomId, 20),
            'live' => $ctx->live($roomId),
            'settings' => $ctx->latestSettings($roomId),
            'switch' => $ctx->switchSnapshot($roomId),
            'server_ts' => gmdate('Y-m-d\TH:i:s\Z'),
        ])->withHeader('Cache-Control', 'no-store');
    }

    public function export(Request $request): Response
    {
        $run = $this->store->load((string) ($request->query['id'] ?? ''));
        if ($run === null) {
            return Response::json(404, ['error' => 'run_not_found']);
        }
        if (($request->query['format'] ?? 'json') === 'json') {
            return new Response(200, [
                'Content-Type' => 'application/json; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="sensores-' . $run['run_id'] . '.json"',
            ], json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
        }
        $labels = ['door' => 'Puerta', 'radar_inside' => 'Radar · dentro',
            'radar_outside' => 'Radar · fuera', 'light' => 'Luz'];
        $lines = ['# Verificación de sensores — ' . $run['run_id'], '',
            '- Sala: ' . ($run['room']['code'] ?? $run['room_id']) . ' · Operador: ' . ($run['operator'] ?: '—'),
            '- Inicio: ' . $run['created_at'] . ' · Fin: ' . ($run['finished_at'] ?? '—')];
        $v = $run['verdict'] ?? [];
        $lines[] = '- Veredicto: ' . ($v['verdict'] ?? '—') . ' · Reposo: ' . ($v['soak'] ?? 'PENDING');
        $lines[] = '';
        foreach ((array) ($run['plan'] ?? []) as $stepId) {
            $step = $run['steps'][$stepId] ?? [];
            $lines[] = '## ' . ($labels[$stepId] ?? $stepId) . ' [' . ($step['status'] ?? 'PENDING') . ']';
            $last = $step['attempts'][count($step['attempts'] ?? []) - 1] ?? null;
            if (is_array($last)) {
                $lines[] = 'Resultado: ' . ($last['verdict']['status'] ?? '—') . ' · ' . ($last['verdict']['reason'] ?? '—');
                $lines[] = 'Detalle: `' . json_encode($last['verdict']['details'] ?? [], JSON_UNESCAPED_UNICODE) . '`';
            }
            $lines[] = '';
        }
        $lines[] = '## Configuración inicial';
        $lines[] = '```json';
        $lines[] = json_encode($run['calibration']['initial_settings'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
        $lines[] = '```';
        return new Response(200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="sensores-' . $run['run_id'] . '.md"',
        ], implode("\n", $lines));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function pdo(): PDO
    {
        return ($this->pdoProvider)();
    }

    private function context(): SensorContextBuilder
    {
        return new SensorContextBuilder($this->pdo());
    }

    /** @return array<string,mixed> */
    private function catalog(): array
    {
        static $catalog = null;
        if ($catalog === null) {
            $raw = @file_get_contents($this->catalogFile);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $catalog = is_array($decoded) ? $decoded : ['steps' => []];
        }
        return $catalog;
    }

    /** @return array<string,mixed>|null */
    private function catalogStep(string $stepId): ?array
    {
        foreach ((array) ($this->catalog()['steps'] ?? []) as $s) {
            if (is_array($s) && (string) ($s['id'] ?? '') === $stepId) {
                return $s;
            }
        }
        return null;
    }

    private function recordPolarity(string $runId, string $polarity, bool $restored): void
    {
        if ($runId === '' || $this->store->load($runId) === null) {
            return;
        }
        $this->store->withLock($runId, static function (array $r) use ($polarity, $restored): array {
            $r['door_polarity'] = [
                'value' => $polarity,
                'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'restored' => $restored,
            ];
            return $r;
        });
        $this->store->appendLog($runId, ['type' => 'door_polarity', 'polarity' => $polarity,
            'restored' => $restored]);
    }

    /** @param array<string,mixed> $run */
    private function stepExists(string $stepId, array $run): bool
    {
        return in_array($stepId, (array) ($run['plan'] ?? []), true);
    }

    private function latestSettings(int $roomId): array
    {
        return $this->context()->latestSettings($roomId)['settings'] ?? [];
    }

    private function doorPolarity(int $roomId): string
    {
        $stmt = $this->pdo()->prepare(
            "SELECT d.meta_json FROM devices d JOIN rooms r ON r.pack_id = d.pack_id
              WHERE r.id = :rid AND d.kind = 'PROXIMITY' LIMIT 1"
        );
        $stmt->execute([':rid' => $roomId]);
        $meta = json_decode((string) ($stmt->fetchColumn() ?: ''), true);
        return DoorPolarity::value(is_array($meta) ? $meta : null);
    }

    private function hasKind(int $roomId, string $kind): bool
    {
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM devices d JOIN rooms r ON r.pack_id = d.pack_id
              WHERE r.id = :rid AND d.kind = :kind'
        );
        $stmt->execute([':rid' => $roomId, ':kind' => $kind]);
        return (int) ($stmt->fetchColumn() ?: 0) > 0;
    }

    /** @param list<array<string,mixed>> $events */
    private function realPresent(array $events, int $windowSeconds = 0): bool
    {
        $cut = time() - $windowSeconds;
        foreach ($events as $e) {
            if (strtoupper((string) ($e['sensor'] ?? '')) !== 'PRESENCE'
                || (string) ($e['value'] ?? '') !== 'PRESENT') {
                continue;
            }
            $src = strtolower((string) (($e['meta'] ?? [])['source'] ?? ''));
            if ($src === 'resync') {
                continue;
            }
            if ($windowSeconds > 0) {
                $ts = strtotime((string) ($e['occurred_at'] ?? '') . ' UTC');
                if ($ts === false || $ts < $cut) {
                    continue;
                }
            }
            return true;
        }
        return false;
    }

    private function waitSwitchState(DeviceRepository $repo, int $deviceId, string $expected, int $attempts): string
    {
        for ($i = 0; $i < max(1, $attempts); $i++) {
            if ($i > 0) {
                usleep(500_000);
            }
            $device = $repo->findById($deviceId);
            $state = strtoupper((string) (($device->meta ?? [])['switch_state'] ?? 'UNKNOWN'));
            if ($state === $expected) {
                return $state;
            }
        }
        $device = $repo->findById($deviceId);
        return strtoupper((string) (($device->meta ?? [])['switch_state'] ?? 'UNKNOWN'));
    }

    /** @param array<string,mixed> $run */
    private static function summary(array $run): array
    {
        $counts = ['pass' => 0, 'fail' => 0, 'skip' => 0, 'unverified' => 0, 'pending' => 0];
        $plan = (array) ($run['plan'] ?? []);
        foreach ($plan as $stepId) {
            $status = strtoupper((string) ($run['steps'][$stepId]['status'] ?? 'PENDING'));
            if ($status === 'PASS') $counts['pass']++;
            elseif ($status === 'FAIL') $counts['fail']++;
            elseif ($status === 'SKIP') $counts['skip']++;
            elseif ($status === 'UNVERIFIED') $counts['unverified']++;
            else $counts['pending']++;
        }
        $counts['total'] = count($plan);
        $counts['green'] = $counts['pending'] === 0 && $counts['fail'] === 0
            && $counts['unverified'] === 0 && $counts['skip'] === 0;
        return $counts;
    }
}
