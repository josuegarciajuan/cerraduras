<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepository;
use App\Domain\Presence\RoomSensorStateRepository;
use App\Domain\Presence\DoorPolarity;
use App\Domain\Sensors\AttemptEvidence;
use App\Domain\Sensors\RadarBoundaryScan;
use App\Domain\Sensors\RadarCalibrationPlanner;
use App\Domain\Sensors\RadarDistanceQuality;
use App\Domain\Sensors\RadarEvidencePolicy;
use App\Domain\Sensors\RadarMeasurement;
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
            $running = $this->store->findRunningRunForRoom($roomId);
            $data['active_attempt'] = $active;
            $data['active_run'] = $running === null ? null : [
                'run_id' => (string) ($running['run_id'] ?? ''),
                'status' => (string) ($running['status'] ?? ''),
                'updated_at' => (string) ($running['updated_at'] ?? ''),
                'operator' => (string) ($running['operator'] ?? ''),
                'active_attempt' => $active,
            ];
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
                'catalog_version' => 3,
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

    /**
     * Archiva la corrida en curso, cancela su intento activo y crea otra desde
     * el principio. Conserva la anterior para auditoría.
     */
    public function resetRun(Request $request): Response
    {
        $body = $request->jsonBody ?? [];
        $runId = (string) ($body['run_id'] ?? '');
        $old = $this->store->load($runId);
        if ($old === null) {
            return Response::json(404, ['error' => 'run_not_found']);
        }
        $roomId = (int) ($old['room_id'] ?? 0);
        if ($roomId <= 0 || ($old['status'] ?? '') !== 'running') {
            return Response::json(409, ['error' => 'run_not_active']);
        }
        try {
            $result = $this->store->withRoomLock($roomId, function () use ($runId, $roomId, $body): array {
                $oldRun = $this->store->load($runId);
                if ($oldRun === null || ($oldRun['status'] ?? '') !== 'running') {
                    throw new \RuntimeException('run_not_active');
                }
                $pre = $this->context()->preflight($roomId);
                if (empty($pre['can_start'])) {
                    return ['ok' => false, 'old_run_id' => $runId, 'preflight' => $pre];
                }
                $now = gmdate('Y-m-d\TH:i:s\Z');
                foreach ((array) ($oldRun['steps'] ?? []) as $stepId => $step) {
                    $step = (array) $step;
                    $active = $step['active_attempt'] ?? null;
                    if (is_array($active)) {
                        $active['status'] = 'CANCELLED';
                        $active['checked_at'] = $now;
                        $active['verdict'] = ['status' => 'SKIP', 'reason' => 'run_reset', 'details' => []];
                        $active['check_id'] = 'reset-' . bin2hex(random_bytes(4));
                        $step['attempts'][] = $active;
                        unset($step['active_attempt']);
                        $step['status'] = 'SKIP';
                        $oldRun['steps'][$stepId] = $step;
                    }
                }
                $oldRun['status'] = 'abandoned';
                $oldRun['abandoned_at'] = $now;
                $oldRun['abandon_reason'] = 'operator_restart';
                $oldRun['summary'] = self::summary($oldRun);
                $oldRun['verdict'] = [
                    'verdict' => SensorVerdict::INCOMPLETE,
                    'devices' => [],
                    'reasons' => ['operator_restart'],
                    'soak' => (string) ($oldRun['soak']['status'] ?? 'PENDING'),
                ];
                $this->store->save($oldRun);
                $this->store->appendLog($runId, ['type' => 'run_abandoned', 'at' => $now,
                    'reason' => 'operator_restart']);

                $newRun = $this->store->create([
                    'room_id' => $roomId,
                    'room' => $pre['room'],
                    'operator' => (string) ($body['operator'] ?? $oldRun['operator'] ?? ''),
                    'catalog' => 'sensores',
                    'catalog_version' => 3,
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
                $this->store->appendLog($newRun['run_id'], ['type' => 'run_reset_from',
                    'old_run_id' => $runId, 'at' => $now]);
                return ['ok' => true, 'old_run_id' => $runId, 'run' => $newRun, 'plan' => $pre['plan']];
            });
            if (($result['ok'] ?? false) !== true) {
                return Response::json(409, ['error' => 'core_devices_missing', 'result' => $result]);
            }
            return Response::json(201, $result);
        } catch (\Throwable $e) {
            $code = $e->getMessage() === 'run_not_active' ? 409 : 500;
            return Response::json($code, ['error' => $e->getMessage()]);
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
        if (in_array($stepId, ['radar_inside', 'radar_outside'], true)) {
            try {
                $run = $this->ensureRadarSessionForStep($runId, $stepId);
            } catch (Throwable $e) {
                if ($e->getMessage() === 'inside_not_valid') {
                    return Response::json(409, [
                        'error' => 'inside_not_valid',
                        'message' => 'Primero completa una medición interior suficiente.',
                    ]);
                }
                return Response::json(500, ['error' => $e->getMessage()]);
            }
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
            $data['initial_effects'] = $initialEffects;
            $data['attempt_started_at'] = (string) $active['started_at'];
            $data['calibration_session'] = (array) ($run['calibration_session'] ?? []);
            $data['settings_valid'] = ZigbeeRadarCalibrationService::isValidSettings(
                $this->latestSettings((int) $run['room_id'])
            );
            $data['calibration_since'] = $run['calibration_session']['candidate']['applied_at'] ?? null;
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

    /**
     * F97.3: confirmación manual del estado físico del contacto.
     * El operador debe enviar confirm=true; no se usa para adivinar.
     */
    public function doorState(Request $request): Response
    {
        $body = $request->jsonBody ?? [];
        $roomId = (int) ($body['room_id'] ?? 0);
        $state = strtoupper(trim((string) ($body['state'] ?? '')));
        $confirmed = (bool) ($body['confirm'] ?? false);
        if ($roomId <= 0) {
            return Response::json(400, ['error' => 'room_id required']);
        }
        if (!$confirmed) {
            return Response::json(400, ['error' => 'confirm_required']);
        }
        if (!in_array($state, ['OPEN', 'CLOSED'], true)) {
            return Response::json(422, ['error' => 'invalid_state']);
        }
        try {
            $pdo = $this->pdo();
            $devices = new DeviceRepository($pdo);
            $device = $devices->findForRoomKind($roomId, Device::KIND_PROXIMITY);
            if ($device === null) {
                return Response::json(404, ['error' => 'door_device_missing']);
            }
            $provider = $device->transport === Device::TRANSPORT_ZIGBEE2MQTT ? 'ZIGBEE2MQTT' : 'TUYA';
            $repo = new RoomSensorStateRepository($pdo);
            $barrier = $repo->maxAppliedEventId($roomId, 'PROXIMITY', $provider);
            $repo->setManual($roomId, 'PROXIMITY', $provider, $state, $barrier, $device->externalId);
            return Response::json(200, [
                'ok' => true, 'room_id' => $roomId, 'state' => $state,
                'provider' => $provider, 'state_source' => 'manual_confirm',
                'barrier_event_id' => $barrier,
            ]);
        } catch (Throwable $e) {
            return Response::json(500, ['error' => $e->getMessage()]);
        }
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
                $stateRepo = new RoomSensorStateRepository($pdo);
                $provider = $device->transport === Device::TRANSPORT_ZIGBEE2MQTT ? 'ZIGBEE2MQTT' : 'TUYA';
                $stateRepo->setUnknown(
                    $roomId,
                    'PROXIMITY',
                    $provider,
                    $stateRepo->maxAppliedEventId($roomId, 'PROXIMITY', $provider),
                    $device->externalId
                );
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
            // La puerta Zigbee es edge-triggered: si no se ha movido, el último
            // evento sigue siendo el estado real. El técnico confirma que está
            // cerrada al empezar, así que la antigüedad no invalida la lectura
            // de polaridad. No se pide abrir/cerrar antes de medir.
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
            // F97.3: la confirmación manual del operador también fija la
            // proyección física, con barrera para no restaurar eventos previos.
            $stateRepo = new RoomSensorStateRepository($pdo);
            $provider = $device->transport === Device::TRANSPORT_ZIGBEE2MQTT ? 'ZIGBEE2MQTT' : 'TUYA';
            $stateRepo->setManual(
                $roomId,
                'PROXIMITY',
                $provider,
                'CLOSED',
                $stateRepo->maxAppliedEventId($roomId, 'PROXIMITY', $provider),
                $device->externalId
            );
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
            // En "fuera" solo cuentan los últimos 20 s: el fading inicial tras
            // salir no debe provocar un ajuste a la baja.
            $detected = $this->realPresent($data['events'], 20);
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
            $commandAt = gmdate('Y-m-d\TH:i:s.v\Z');
            $result = $action === 'ON'
                ? $gateway->turnOnForDevice($device)
                : $gateway->turnOffForDevice($device);
            $wait = $this->waitSwitchState($deviceRepo, (int) $device->id, $action);
            return Response::json(200, [
                'ok' => (bool) ($result['ok'] ?? false),
                'action' => $action,
                'error' => $result['error'] ?? null,
                'command_at' => $commandAt,
                'pending_confirmation' => (bool) ($result['pending_confirmation'] ?? false),
                'reported_state' => $wait['state'],
                'state_at' => $wait['state_at'],
                'fresh' => $wait['fresh'],
            ]);
        } catch (Throwable $e) {
            return Response::json(500, ['error' => $e->getMessage()]);
        }
    }

    public function attemptEvents(Request $request): Response
    {
        $runId = (string) ($request->query['run_id'] ?? '');
        $stepId = (string) ($request->query['step_id'] ?? '');
        $attemptId = (string) ($request->query['attempt_id'] ?? '');
        $run = $this->store->load($runId);
        if ($run === null) {
            return Response::json(404, ['error' => 'run_not_found']);
        }
        $active = $run['steps'][$stepId]['active_attempt'] ?? null;
        if (!is_array($active) || ($active['attempt_id'] ?? '') !== $attemptId) {
            return Response::json(409, ['error' => 'attempt_not_active']);
        }
        try {
            $ctx = $this->context();
            $startedAt = (string) $active['started_at'];
            $events = $ctx->eventsSince((int) $run['room_id'], $startedAt, 3000);
            $evidence = AttemptEvidence::build($events);
            $samples = [];
            foreach ($events as $e) {
                if (strtoupper((string) ($e['sensor'] ?? '')) !== 'PRESENCE') {
                    continue;
                }
                $settings = is_array($e['meta']['settings'] ?? null) ? $e['meta']['settings'] : [];
                if (array_key_exists('target_distance', $settings)) {
                    $samples[] = (float) $settings['target_distance'];
                }
            }
            $startedTs = strtotime($startedAt . ' UTC');
            return Response::json(200, [
                'ok' => true,
                'run_id' => $runId,
                'step_id' => $stepId,
                'attempt_id' => $attemptId,
                'started_at' => $startedAt,
                'elapsed_seconds' => $startedTs !== false ? max(0, time() - $startedTs) : 0,
                'door' => $evidence['door'],
                'presence' => $evidence['presence'],
                'distance' => RadarDistanceQuality::evaluate($samples),
                'calibration_session' => (array) ($run['calibration_session'] ?? []),
                'switch' => $ctx->switchSnapshot((int) $run['room_id']),
                'settings' => $ctx->latestSettings((int) $run['room_id'])['settings'],
                'server_ts' => gmdate('Y-m-d\TH:i:s\Z'),
            ])->withHeader('Cache-Control', 'no-store');
        } catch (Throwable $e) {
            return Response::json(500, ['error' => $e->getMessage()]);
        }
    }

    public function radarCalibrate(Request $request): Response
    {
        $body = $request->jsonBody ?? [];
        $runId = (string) ($body['run_id'] ?? '');
        $stepId = (string) ($body['step_id'] ?? '');
        $attemptId = (string) ($body['attempt_id'] ?? '');
        $action = (string) ($body['action'] ?? '');
        $run = $this->store->load($runId);
        if ($run === null) {
            return Response::json(404, ['error' => 'run_not_found']);
        }
        if (!in_array($stepId, ['radar_inside', 'radar_outside'], true)) {
            return Response::json(400, ['error' => 'step_not_radar']);
        }
        // F96.5: restaurar no exige una ventana activa; sirve precisamente
        // cuando el radar ha quedado en un estado inválido y hay que recuperarlo.
        if ($action === 'restore') {
            try {
                $pdo = $this->pdo();
                $initial = (array) ($run['calibration']['initial_settings'] ?? []);
                $params = [];
                foreach (['maximum_range', 'radar_sensitivity', 'fading_time', 'detection_delay'] as $key) {
                    if (isset($initial[$key]) && is_numeric($initial[$key])) {
                        $params[$key] = (float) $initial[$key];
                    }
                }
                if ($params === []) {
                    return Response::json(400, ['error' => 'no_initial_settings']);
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
                $session = (array) ($run['calibration_session'] ?? []);
                $session['restored'] = [
                    'ok' => (bool) ($result['applied'] ?? false),
                    'at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'settings' => $result['status']['settings'] ?? [],
                    'warning' => $result['warning'] ?? null,
                ];
                $run['calibration_session'] = $session;
                $this->store->save($run);
                return Response::json(200, [
                    'ok' => (bool) ($result['applied'] ?? false),
                    'action' => 'restore',
                    'session' => $session,
                    'settings' => $result['status']['settings'] ?? [],
                    'warning' => $result['warning'] ?? null,
                ]);
            } catch (Throwable $e) {
                return Response::json(500, ['error' => $e->getMessage()]);
            }
        }
        $active = $run['steps'][$stepId]['active_attempt'] ?? null;
        if (!is_array($active) || ($active['attempt_id'] ?? '') !== $attemptId) {
            return Response::json(409, ['error' => 'attempt_not_active']);
        }
        try {
            $pdo = $this->pdo();
            $ctx = $this->context();
            $startedAt = (string) $active['started_at'];
            $events = $ctx->eventsSince((int) $run['room_id'], $startedAt, 3000);
            $evidence = AttemptEvidence::build($events);
            $samples = [];
            foreach ($events as $e) {
                if (strtoupper((string) ($e['sensor'] ?? '')) !== 'PRESENCE') {
                    continue;
                }
                $settings = is_array($e['meta']['settings'] ?? null) ? $e['meta']['settings'] : [];
                if (array_key_exists('target_distance', $settings)) {
                    $samples[] = (float) $settings['target_distance'];
                }
            }
            $stats = RadarDistanceQuality::evaluate($samples);
            $phase = $stepId === 'radar_inside' ? 'inside' : 'outside';
            $session = (array) ($run['calibration_session'] ?? []);

            if ($action === 'measure') {
                $measureSince = $startedAt;
                $appliedAt = (string) ($session['candidate']['applied_at'] ?? '');
                if ($appliedAt !== '' && strtotime($appliedAt . ' UTC') > strtotime($startedAt . ' UTC')) {
                    $measureSince = $appliedAt;
                }
                $filtered = array_values(array_filter($events, static function (array $e) use ($measureSince): bool {
                    $ts = strtotime((string) ($e['occurred_at'] ?? '') . ' UTC');
                    return $ts !== false && $ts >= strtotime($measureSince . ' UTC');
                }));
                $measureEvidence = AttemptEvidence::build($filtered);
                $measureSamples = [];
                foreach ($filtered as $e) {
                    if (strtoupper((string) ($e['sensor'] ?? '')) !== 'PRESENCE') continue;
                    $st = is_array($e['meta']['settings'] ?? null) ? $e['meta']['settings'] : [];
                    if (array_key_exists('target_distance', $st)) $measureSamples[] = (float) $st['target_distance'];
                }
                $stats = RadarDistanceQuality::evaluate($measureSamples);
                $session[$phase] = [
                    'stats' => $stats,
                    'presence' => (bool) ($measureEvidence['presence']['presents'] > 0 && $measureEvidence['presence']['active']),
                    'span_seconds' => $measureEvidence['presence']['span_seconds'],
                    'measured_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'attempt_id' => $attemptId,
                ];
                $run['calibration_session'] = $session;
                $this->store->save($run);
                return Response::json(200, ['ok' => true, 'phase' => $phase,
                    'session' => $session, 'distance' => $stats, 'presence' => $measureEvidence['presence']]);
            }

            if ($action === 'apply') {
                if (empty($session['inside']) && empty($session['outside'])) {
                    return Response::json(409, ['error' => 'measure_first',
                        'message' => 'Mide primero el punto interior o el exterior.']);
                }
                $settings = $ctx->latestSettings((int) $run['room_id'])['settings'];
                $caps = [
                    'range_min' => (float) ZigbeeRadarCalibrationService::PARAMETERS['maximum_range']['min'],
                    'range_max' => (float) ZigbeeRadarCalibrationService::PARAMETERS['maximum_range']['max'],
                    // Paso de ajuste práctico: 0,75 m (múltiplo del paso real 0,15).
                    'range_step' => 0.75,
                    'sens_min' => (int) ZigbeeRadarCalibrationService::PARAMETERS['radar_sensitivity']['min'],
                    'sens_max' => (int) ZigbeeRadarCalibrationService::PARAMETERS['radar_sensitivity']['max'],
                ];
                $plan = RadarCalibrationPlanner::plan(
                    (array) ($session['inside']['stats'] ?? []),
                    (array) ($session['outside']['stats'] ?? []),
                    $settings,
                    $caps,
                    [
                        'inside_present' => (bool) ($session['inside']['presence'] ?? false),
                        'outside_present' => (bool) ($session['outside']['presence'] ?? false),
                        'reentry_failed' => (bool) (($session['candidate']['applied'] ?? false)
                            && (($session['reentry']['detected'] ?? null) === false)),
                    ]
                );
                if ($plan['action'] !== 'apply') {
                    return Response::json(200, ['ok' => true, 'action' => $plan['action'],
                        'plan' => $plan, 'session' => $session, 'settings' => $settings]);
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
                ], $plan['params'], true);
                $session['candidate'] = [
                    'params' => $plan['params'],
                    'reason' => $plan['reason'],
                    'confidence' => $plan['confidence'],
                    'applied' => (bool) ($result['applied'] ?? false),
                    'applied_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'warning' => $result['warning'] ?? null,
                ];
                $run['calibration_session'] = $session;
                $this->store->save($run);
                $invalid = str_starts_with((string) ($result['warning'] ?? ''), 'invalid_device_settings');
                return Response::json(200, ['ok' => true, 'action' => 'apply',
                    'plan' => $plan, 'applied' => (bool) ($result['applied'] ?? false),
                    'warning' => $result['warning'] ?? null,
                    'invalid_settings' => $invalid,
                    'settings_invalid_reason' => $result['settings_invalid_reason'] ?? null,
                    'session' => $session, 'settings' => $result['status']['settings'] ?? $settings]);
            }

            if ($action === 'reentry') {
                $real = false;
                $firstPresentAt = null;
                $cut = $this->parseTs((string) ($session['candidate']['applied_at'] ?? ''));
                foreach ($events as $e) {
                    if (strtoupper((string) ($e['sensor'] ?? '')) !== 'PRESENCE'
                        || (string) ($e['value'] ?? '') !== 'PRESENT') {
                        continue;
                    }
                    $src = strtolower((string) (($e['meta'] ?? [])['source'] ?? ''));
                    if ($src === 'resync') continue;
                    $ts = $this->parseTs((string) ($e['occurred_at'] ?? ''));
                    if ($cut === null || ($ts !== null && $ts >= $cut)) {
                        $real = true;
                        $firstPresentAt = (string) ($e['occurred_at'] ?? null);
                        break;
                    }
                }
                $attempts = (int) ($session['reentry']['attempts'] ?? 0) + 1;
                $session['reentry'] = [
                    'detected' => $real,
                    'at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'first_present_at' => $firstPresentAt,
                    'attempts' => $attempts,
                    'presence' => $evidence['presence'],
                ];
                $run['calibration_session'] = $session;
                $this->store->save($run);
                return Response::json(200, [
                    'ok' => true,
                    'action' => 'reentry',
                    'detected' => $real,
                    'attempts' => $attempts,
                    'message' => $real
                        ? 'Reentrada detectada. Ya puedes validar el radar.'
                        : 'No se detectó presencia al volver a entrar. Este ajuste ha reducido demasiado la detección: ajusta de nuevo o restaura los valores iniciales.',
                    'session' => $session,
                ]);
            }

            if ($action === 'restore') {
                $initial = (array) ($run['calibration']['initial_settings'] ?? []);
                $params = [];
                foreach (['maximum_range', 'radar_sensitivity', 'fading_time', 'detection_delay'] as $key) {
                    if (isset($initial[$key]) && is_numeric($initial[$key])) {
                        $params[$key] = (float) $initial[$key];
                    }
                }
                if ($params === []) {
                    return Response::json(400, ['error' => 'no_initial_settings']);
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
                $session['restored'] = [
                    'ok' => (bool) ($result['applied'] ?? false),
                    'at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'settings' => $result['status']['settings'] ?? [],
                ];
                $run['calibration_session'] = $session;
                $this->store->save($run);
                return Response::json(200, ['ok' => (bool) ($result['applied'] ?? false),
                    'action' => 'restore', 'session' => $session,
                    'settings' => $result['status']['settings'] ?? []]);
            }

            return Response::json(400, ['error' => 'unknown_action']);
        } catch (Throwable $e) {
            return Response::json(500, ['error' => $e->getMessage()]);
        }
    }

    public function radarState(Request $request): Response
    {
        $runId = (string) ($request->query['run_id'] ?? '');
        $stepId = (string) ($request->query['step_id'] ?? '');
        $run = $this->store->load($runId);
        if ($run === null) {
            return Response::json(404, ['error' => 'run_not_found']);
        }
        if (!in_array($stepId, ['radar_inside', 'radar_outside'], true)) {
            return Response::json(400, ['error' => 'step_not_radar']);
        }
        try {
            $pdo = $this->pdo();
            $run = $this->store->withLock($runId, function (array $r) use ($pdo): array {
                return $this->reconcileRadarRecord($r, $pdo);
            });
            return $this->radarStateResponse($run, $stepId);
        } catch (Throwable $e) {
            return Response::json(500, ['error' => $e->getMessage()]);
        }
    }

    public function radarOperation(Request $request): Response
    {
        $body = $request->jsonBody ?? [];
        $runId = (string) ($body['run_id'] ?? '');
        $stepId = (string) ($body['step_id'] ?? '');
        $attemptId = (string) ($body['attempt_id'] ?? '');
        $action = (string) ($body['action'] ?? '');
        $run = $this->store->load($runId);
        if ($run === null) {
            return Response::json(404, ['error' => 'run_not_found']);
        }
        if (!in_array($stepId, ['radar_inside', 'radar_outside'], true)) {
            return Response::json(400, ['error' => 'step_not_radar']);
        }
        if (($run['status'] ?? '') !== 'running') {
            return Response::json(409, ['error' => 'run_not_running']);
        }
        try {
            $pdo = $this->pdo();
            $run = $this->store->withLock($runId, function (array $r) use ($stepId, $attemptId, $action, $pdo): array {
                $r = $this->reconcileRadarRecord($r, $pdo);
                return $this->applyRadarOperation($r, $stepId, $attemptId, $action, $pdo);
            });
            return $this->radarStateResponse($run, $stepId);
        } catch (Throwable $e) {
            $code = in_array($e->getMessage(), [
                'operation_pending', 'measure_in_progress', 'measure_not_ready',
                'measure_insufficient', 'inside_not_valid', 'invalid_state', 'unknown_action',
                'presence_stale', 'reentry_not_clear', 'scan_must_start_inside',
                'legacy_calibration', 'inside_not_detected', 'no_distance_separation',
            ], true) ? 409 : 500;
            return Response::json($code, ['error' => $e->getMessage()]);
        }
    }

    /** @param array<string,mixed> $run */
    private function radarStateResponse(array $run, string $stepId): Response
    {
        $session = (array) ($run['calibration_session'] ?? []);
        if (($session['version'] ?? 0) < 2) {
            return Response::json(409, ['error' => 'legacy_calibration', 'message' => 'Reinicia esta verificación para usar el calibrador nuevo.']);
        }
        $snapshot = $this->latestPresenceSnapshot((int) $run['room_id']);
        $settings = (array) ($snapshot['settings'] ?? []);
        $valid = ZigbeeRadarCalibrationService::isValidSettings($settings);
        $copy = $this->radarCopy($session);
        $live = $this->context()->live((int) $run['room_id']);
        $staleSeconds = (int) ($live['presence_stale_seconds'] ?? 180);
        if ($staleSeconds <= 0) {
            $staleSeconds = 180;
        }
        $rawAge = isset($snapshot['settings_age_seconds']) && is_numeric($snapshot['settings_age_seconds'])
            ? (int) $snapshot['settings_age_seconds'] : null;
        $rawState = strtoupper(trim((string) ($snapshot['value'] ?? '')));
        $rawStale = $rawAge !== null && in_array($rawState, ['PRESENT', 'ABSENT'], true)
            && $rawAge > $staleSeconds;
        return Response::json(200, [
            'ok' => true,
            'run_id' => (string) $run['run_id'],
            'step_id' => $stepId,
            'session_version' => (int) ($session['version'] ?? 2),
            'phase' => (string) ($session['phase'] ?? 'INSIDE'),
            'state' => (string) ($session['state'] ?? 'IDLE'),
            'model' => (string) ($session['model'] ?? 'legacy'),
            'config_version' => (int) ($session['config_version'] ?? 1),
            'current_settings' => $settings,
            'scan' => $session['outside_scan'] ?? null,
            'scan_preview' => $session['scan_preview'] ?? null,
            'calibration' => $session['calibration'] ?? null,
            'settings_valid' => $valid,
            'settings_invalid_reason' => $valid ? null : ZigbeeRadarCalibrationService::settingsInvalidReason($settings),
            'active_operation' => $session['active_operation'] ?? null,
            'last_measurement' => $session['last_measurement'] ?? null,
            'inside_valid' => $session['inside_valid'] ?? null,
            'outside_valid' => $session['outside_valid'] ?? null,
            'outside_pass' => $session['outside_pass'] ?? null,
            'reentry' => $session['reentry'] ?? null,
            'result' => $session['result'] ?? null,
            'manual_start_required' => (bool) ($session['manual_start_required'] ?? false),
            'next_actions' => $this->radarNextActions($session),
            'countdown' => $this->radarCountdown($session),
            'title' => $copy['title'],
            'instruction' => $copy['instruction'],
            'message' => $copy['message'],
            'last_presence_at' => $live['last_presence_event_at'] ?? null,
            'presence_age_seconds' => $live['presence_age_seconds'] ?? null,
            'presence_stale' => $live['presence_stale'] ?? false,
            'presence_state' => $live['presence_state'] ?? 'UNKNOWN',
            'presence_stale_seconds' => $staleSeconds,
            // F103: señal directa del radar (la que usa la calibración).
            'raw_presence_state' => in_array($rawState, ['PRESENT', 'ABSENT'], true) ? $rawState : 'UNKNOWN',
            'raw_presence_at' => $snapshot['settings_at'] ?? null,
            'raw_presence_age_seconds' => $rawAge,
            'raw_presence_stale' => $rawStale,
            'server_ts' => gmdate('Y-m-d\TH:i:s\Z'),
        ])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function ensureRadarSessionForStep(string $runId, string $stepId): array
    {
        return $this->store->withLock($runId, function (array $r) use ($stepId): array {
            $session = (array) ($r['calibration_session'] ?? []);
            if (($session['version'] ?? 0) < 3) {
                $session = $this->newRadarSession($r);
            }
            if ($stepId === 'radar_inside') {
                $session['phase'] = 'INSIDE';
                if (($session['state'] ?? '') === 'DONE') {
                    $session['state'] = 'IDLE';
                }
            } else {
                $inside = (array) ($session['inside_valid'] ?? []);
                if (($inside['sufficient'] ?? false) !== true || ($inside['detected'] ?? false) !== true) {
                    throw new \RuntimeException('inside_not_valid');
                }
                if (!in_array((string) ($session['phase'] ?? ''), ['OUTSIDE_SCAN', 'OUTSIDE_VERIFY', 'OUTSIDE_PASS', 'REENTRY', 'DONE'], true)) {
                    $session['phase'] = 'OUTSIDE_SCAN';
                    $session['state'] = 'IDLE';
                }
            }
            $r['calibration_session'] = $session;
            return $r;
        });
    }

    /** @param array<string,mixed> $run */
    private function newRadarSession(array $run): array
    {
        $roomId = (int) $run['room_id'];
        $baseline = (array) ($run['calibration']['initial_settings'] ?? []);
        $current = $this->latestSettings($roomId);
        return [
            'version' => 3,
            'model' => 'boundary_scan_v1',
            'config_version' => 1,
            'phase' => 'INSIDE',
            'state' => 'IDLE',
            'baseline' => $baseline,
            'current' => $current,
            'active_operation' => null,
            'operations' => [],
            'measurements' => [],
            'candidates' => [],
            'inside_valid' => null,
            'inside_ref' => null,
            'outside_scan' => null,
            'outside_valid' => null,
            'outside_pass' => null,
            'reentry' => null,
            'last_measurement' => null,
            'calibration' => null,
            'result' => null,
            'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Reconcilia una orden pendiente con el último reporte. No publica nada.
     *
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function reconcileRadarRecord(array $run, \PDO $pdo): array
    {
        $session = (array) ($run['calibration_session'] ?? []);
        $version = (int) ($session['version'] ?? 0);
        if ($version < 2) {
            return $run;
        }
        $isV3 = $version >= 3;
        $changed = false;
        $now = time();

        $op = $session['active_operation'] ?? null;
        if (is_array($op)) {
            $state = (string) ($op['state'] ?? '');
            if ($state === 'AWAITING_CONFIRM') {
                $cal = new ZigbeeRadarCalibrationService($pdo);
                $conf = $cal->confirm(
                    (int) $run['room_id'],
                    (int) ($op['before_event_id'] ?? 0),
                    (array) ($op['requested'] ?? [])
                );
                $op['observed'] = $conf['settings'] ?? [];
                $op['confirmed_event_id'] = $conf['event_id'] ?? null;
                $op['confirmed_at'] = $conf['state'] === 'confirmed' ? ($conf['at'] ?? gmdate('Y-m-d\TH:i:s\Z')) : null;
                if ($conf['state'] === 'confirmed') {
                    $previous = (array) ($session['current'] ?? []);
                    $confirmed = (array) ($conf['settings'] ?? []);
                    $session['current'] = $confirmed;
                    if (!$isV3 || $this->settingsDiffer($previous, $confirmed)) {
                        $session['config_version'] = (int) ($session['config_version'] ?? 1) + 1;
                    }
                    $op['state'] = 'CONFIRMED';
                    $op['confirmed_settings'] = $confirmed;
                    foreach ((array) ($session['candidates'] ?? []) as $ci => $cand) {
                        if (($cand['candidate_id'] ?? '') === ($op['operation_id'] ?? '')) {
                            $session['candidates'][$ci]['status'] = 'CONFIRMED';
                            $session['candidates'][$ci]['confirmed_at'] = $conf['at'] ?? gmdate('Y-m-d\TH:i:s\Z');
                        }
                    }
                    if ($isV3) {
                        $deviceRepo = new DeviceRepository($pdo);
                        $presence = $deviceRepo->findForRoomKind((int) $run['room_id'], Device::KIND_PRESENCE);
                        if ($presence !== null) {
                            $cal->persistCalibration([
                                'id' => (int) $presence->id,
                                'external_id' => (string) $presence->externalId,
                                'meta' => is_array($presence->meta) ? $presence->meta : [],
                            ], $confirmed, $conf['at'] ?? null);
                        }
                        if (isset($session['calibration']) && is_array($session['calibration'])) {
                            $session['calibration']['sensitivity'] = (int) round((float) ($confirmed['radar_sensitivity'] ?? 0));
                            if (isset($confirmed['maximum_range']) && is_numeric($confirmed['maximum_range'])) {
                                $session['calibration']['target_range'] = (float) $confirmed['maximum_range'];
                            }
                            $session['calibration']['attempts'] = count((array) ($session['candidates'] ?? []));
                            $session['calibration']['applied'] = true;
                            $session['calibration']['updated_at'] = $conf['at'] ?? gmdate('Y-m-d\TH:i:s\Z');
                        }
                    }
                    $session['state'] = 'STABILIZING';
                    $fading = (float) (($conf['settings'] ?? [])['fading_time'] ?? 15);
                    $session['stabilize_until'] = gmdate('Y-m-d\TH:i:s\Z', $now + (int) max(8, min(60, $fading + 5)));
                    if (($op['kind'] ?? '') === 'RESTORE') {
                        $session['current'] = (array) ($session['baseline'] ?? $conf['settings']);
                        $session['state'] = 'IDLE';
                        $session['stabilize_until'] = null;
                        $op['state'] = 'CONFIRMED';
                        $session['active_operation'] = null;
                        if ($isV3) {
                            $restorePhase = (string) ($op['phase'] ?? 'INSIDE');
                            $session['phase'] = $restorePhase === 'INSIDE' ? 'INSIDE' : 'OUTSIDE_SCAN';
                            $session['result'] = null;
                            $session['manual_start_required'] = true;
                            foreach (['outside_scan', 'outside_valid', 'outside_pass', 'reentry',
                                         'last_measurement', 'scan_preview', 'calibration',
                                         'pending_phase'] as $key) {
                                unset($session[$key]);
                            }
                            if ($restorePhase === 'INSIDE') {
                                unset($session['inside_valid'], $session['inside_ref']);
                            }
                            $session['candidates'] = [];
                        }
                    } elseif ($isV3 && isset($op['next_phase'])) {
                        $session['pending_phase'] = (string) $op['next_phase'];
                    }
                } elseif ($conf['state'] === 'invalid') {
                    $op['state'] = 'INVALID_SETTINGS';
                    $session['state'] = 'INVALID_SETTINGS';
                } elseif ($conf['state'] === 'mismatch') {
                    $op['state'] = 'INDETERMINATE';
                    $session['state'] = 'INDETERMINATE';
                } else {
                    $deadline = isset($op['published_at']) ? strtotime((string) $op['published_at'] . ' UTC') + 15 : null;
                    if ($deadline !== null && $now > $deadline) {
                        $op['state'] = 'INDETERMINATE';
                        $op['reason'] = 'no_confirmation_within_deadline';
                        $session['state'] = 'INDETERMINATE';
                    }
                }
                if (!in_array($op['state'], ['CONFIRMED'], true)) {
                    foreach ((array) ($session['candidates'] ?? []) as $ci => $cand) {
                        if (($cand['candidate_id'] ?? '') === ($op['operation_id'] ?? '')) {
                            $session['candidates'][$ci]['status'] = 'FAILED';
                        }
                    }
                }
                $changed = true;
            }

            if (($session['state'] ?? '') === 'STABILIZING') {
                $until = (string) ($session['stabilize_until'] ?? '');
                if ($until !== '' && $now >= (strtotime($until . ' UTC') ?: 0)) {
                    $opId = (string) ($session['active_operation']['operation_id'] ?? '');
                    $session['state'] = 'IDLE';
                    $session['stabilize_until'] = null;
                    if ($opId !== '') {
                        $session['active_operation'] = null;
                        if (isset($session['operations'][$opId])) {
                            $session['operations'][$opId]['state'] = 'CONFIRMED';
                        }
                    }
                    if ($isV3) {
                        $nextPhase = (string) ($session['pending_phase'] ?? '');
                        if ($nextPhase !== '') {
                            $session['phase'] = $nextPhase;
                        }
                    }
                    unset($session['pending_phase']);
                    $changed = true;
                }
            }

            if (isset($session['active_operation']['operation_id'])) {
                $opId = (string) $session['active_operation']['operation_id'];
                $session['active_operation'] = $op;
                $session['operations'][$opId] = $op;
            }
        }

        if ($isV3) {
            $active = $session['active_operation'] ?? null;
            if (is_array($active) && ($session['state'] ?? '') === 'MEASURING') {
                $kind = (string) ($active['kind'] ?? '');
                $windowEnd = (string) ($active['window_end'] ?? '');
                $ended = $windowEnd !== '' && $now >= (strtotime($windowEnd . ' UTC') ?: 0);
                if ($kind === 'SCAN') {
                    $ctx = $this->context();
                    $events = $ctx->eventsSince((int) $run['room_id'], (string) ($active['window_start'] ?? ''), 3000);
                    $summary = RadarBoundaryScan::summarize(
                        $events,
                        (string) ($active['window_start'] ?? ''),
                        gmdate('Y-m-d\TH:i:s\Z'),
                        (int) ($active['config_version'] ?? 1),
                        (array) ($session['current'] ?? []),
                        (array) ($active['seed'] ?? []),
                        ['min_clear_seconds' => 6, 'min_absent_reports' => 2]
                    );
                    if (($summary['sufficient'] ?? false) === true || $ended) {
                        $session['scan_preview'] = $summary;
                        $session['state'] = 'SCAN_READY';
                        $changed = true;
                    }
                } elseif ($kind === 'VERIFY' && $ended) {
                    $session['state'] = 'VERIFY_READY';
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $run['calibration_session'] = $session;
            $this->store->save($run);
        }
        return $run;
    }

    /** @param array<string,mixed> $a @param array<string,mixed> $b */
    private function settingsDiffer(array $a, array $b): bool
    {
        foreach (['maximum_range', 'minimum_range', 'radar_sensitivity', 'fading_time', 'detection_delay'] as $key) {
            $av = isset($a[$key]) && is_numeric($a[$key]) ? (float) $a[$key] : null;
            $bv = isset($b[$key]) && is_numeric($b[$key]) ? (float) $b[$key] : null;
            if ($av === null && $bv === null) {
                continue;
            }
            if ($av === null || $bv === null || abs($av - $bv) > 0.001) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function applyRadarOperation(array $run, string $stepId, string $attemptId, string $action, \PDO $pdo): array
    {
        $session = (array) ($run['calibration_session'] ?? []);
        if (($session['version'] ?? 0) >= 3) {
            return $this->applyRadarOperationV3($run, $stepId, $attemptId, $action, $pdo);
        }
        if (($session['version'] ?? 0) !== 2) {
            throw new \RuntimeException('legacy_calibration');
        }
        $phase = (string) ($session['phase'] ?? 'INSIDE');
        $state = (string) ($session['state'] ?? 'IDLE');
        $active = $session['active_operation'] ?? null;
        $now = time();

        if ($action === 'cancel') {
            if (is_array($active)) {
                $opId = (string) ($active['operation_id'] ?? '');
                $active['state'] = 'CANCELLED';
                $session['operations'][$opId] = $active;
            }
            $session['active_operation'] = null;
            $session['state'] = 'IDLE';
            $session['stabilize_until'] = null;
            $run['calibration_session'] = $session;
            $this->store->save($run);
            return $run;
        }

        if (in_array($action, ['start_measure', 'start_reentry'], true)) {
            if (is_array($active)) {
                throw new \RuntimeException('operation_pending');
            }
            if ($state === 'STABILIZING') {
                throw new \RuntimeException('measure_not_ready');
            }
            $roomId = (int) $run['room_id'];
            $live = $this->context()->live($roomId);
            $staleSeconds = (int) ($live['presence_stale_seconds'] ?? 180);
            if ($staleSeconds <= 0) {
                $staleSeconds = 180;
            }
            $snapshot = $this->latestPresenceSnapshot($roomId);
            // F103: la calibración usa la señal CRUDA del radar. El estado
            // fusionado puede descartar un PRESENT como candidato y no reflejar
            // que el sensor sí está detectando.
            $isReentryStart = $action === 'start_reentry'
                || ($action === 'start_measure' && $phase === 'REENTRY');
            $block = $isReentryStart
                ? RadarEvidencePolicy::reentryBlockReason($snapshot)
                : RadarEvidencePolicy::startBlockReason($snapshot, $staleSeconds);
            if ($block !== null) {
                throw new \RuntimeException($block);
            }
            $seed = $isReentryStart ? null : RadarEvidencePolicy::seed($snapshot, $staleSeconds);
            $window = $this->radarWindowSeconds(
                (array) ($snapshot['settings'] ?? []),
                (array) ($session['current'] ?? [])
            );
            $windowStart = gmdate('Y-m-d\TH:i:s\Z');
            $opId = 'op-' . bin2hex(random_bytes(5));
            $opPhase = $isReentryStart ? 'REENTRY' : $phase;
            $op = [
                'operation_id' => $opId,
                'kind' => $isReentryStart ? 'REENTRY' : 'MEASURE',
                'phase' => $opPhase,
                'state' => 'MEASURING',
                'started_at' => $windowStart,
                'window_start' => $windowStart,
                'window_end' => gmdate('Y-m-d\TH:i:s\Z', $now + $window),
                'window_seconds' => $window,
                'config_version' => (int) ($session['config_version'] ?? 1),
                'requested' => [],
                'before_settings' => $session['current'] ?? [],
                'seed' => $seed,
                'raw_presence_at_start' => $snapshot['value'] ?? null,
                'raw_presence_age_seconds' => $snapshot['settings_age_seconds'] ?? null,
            ];
            if ($isReentryStart) {
                // Snapshot ANTES de entrar: los efectos posteriores (visita de
                // reentrada) son esperados y no deben invalidar el exterior.
                $session['effects_before_reentry'] = $this->context()->effects($roomId);
                $session['reentry'] = null;
            }
            unset($session['manual_start_required']);
            $session['phase'] = $opPhase;
            $session['state'] = 'MEASURING';
            $session['active_operation'] = $op;
            $session['operations'][$opId] = $op;
            $run['calibration_session'] = $session;
            $this->store->save($run);
            return $run;
        }

        if ($action === 'finish_measure' || $action === 'finish_reentry') {
            if (!is_array($active) || ($active['state'] ?? '') !== 'MEASURING') {
                throw new \RuntimeException('invalid_state');
            }
            $end = strtotime((string) ($active['window_end'] ?? '') . ' UTC');
            if ($end !== false && $now < $end) {
                throw new \RuntimeException('measure_in_progress');
            }
            $ctx = $this->context();
            $events = $ctx->eventsSince((int) $run['room_id'], (string) $active['window_start'], 3000);
            $mPhase = (string) ($active['phase'] ?? $phase);
            $isReentry = (($active['kind'] ?? '') === 'REENTRY') || $mPhase === 'REENTRY';
            $requirements = $isReentry
                ? ['min_reports' => 1, 'min_span_seconds' => 0, 'require_new_present' => true]
                : [];
            $seed = $isReentry ? [] : (array) ($active['seed'] ?? []);
            $measurement = RadarMeasurement::build(
                $events,
                (string) $active['window_start'],
                (string) $active['window_end'],
                (int) ($active['config_version'] ?? 1),
                (array) ($session['current'] ?? []),
                $requirements,
                $seed
            );
            // El resultado de la fase no es siempre "último valor": dentro exige
            // continuidad y fuera exige ausencia de cualquier PRESENT.
            $phaseDetected = RadarMeasurement::outcomeForPhase($measurement, $mPhase);
            $measurement['last_state'] = $measurement['last_value'];
            $measurement['detected'] = $phaseDetected;
            $measurement['measurement_id'] = 'm-' . bin2hex(random_bytes(4));
            $measurement['phase'] = $mPhase;
            $session['measurements'][] = $measurement;
            $session['last_measurement'] = $measurement;
            $compat = [
                'stats' => $measurement['distance'],
                'presence' => $phaseDetected,
                'span_seconds' => $measurement['span_seconds'],
                'measured_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'attempt_id' => $attemptId,
                'measurement_id' => $measurement['measurement_id'],
                'config_version' => $measurement['config_version'],
                'sufficient' => $measurement['sufficient'],
                'evidence_mode' => $measurement['evidence_mode'],
            ];
            $sufficient = (bool) ($measurement['sufficient'] ?? false);
            if ($mPhase === 'INSIDE') {
                if ($sufficient) {
                    $session['inside_valid'] = $measurement;
                    $session['inside'] = $compat;
                }
            } elseif ($mPhase === 'OUTSIDE_FIXED') {
                if ($sufficient) {
                    $session['outside_valid'] = $measurement;
                    $session['outside'] = $compat;
                }
            } elseif ($mPhase === 'OUTSIDE_PASS') {
                if ($sufficient) {
                    $session['outside_pass'] = $measurement;
                }
            } elseif ($mPhase === 'REENTRY') {
                if ($sufficient) {
                    $session['reentry'] = [
                        'detected' => $phaseDetected,
                        'at' => gmdate('Y-m-d\TH:i:s\Z'),
                        'first_present_at' => $measurement['first_present_at'] ?? null,
                        'presence' => $measurement,
                        'effects_before_reentry' => $session['effects_before_reentry'] ?? [],
                    ];
                    if (!$phaseDetected) {
                        $session['state'] = 'REJECTED';
                        if (isset($session['candidates'][count($session['candidates']) - 1])) {
                            $idx = count($session['candidates']) - 1;
                            $session['candidates'][$idx]['status'] = 'REJECTED';
                        }
                    }
                }
            }
            $active['state'] = 'MEASURED';
            $active['result'] = $measurement;
            $session['operations'][(string) $active['operation_id']] = $active;
            $session['active_operation'] = null;
            $session['state'] = ($session['state'] ?? '') === 'REJECTED' ? 'REJECTED' : 'MEASURED';
            $run['calibration_session'] = $session;
            $this->store->save($run);
            return $run;
        }

        if ($action === 'advance') {
            $cv = (int) ($session['config_version'] ?? 1);
            if ($phase === 'INSIDE') {
                $inside = (array) ($session['inside_valid'] ?? []);
                if (($inside['sufficient'] ?? false) !== true
                    || ($inside['detected'] ?? false) !== true
                    || (int) ($inside['config_version'] ?? 0) !== $cv) {
                    throw new \RuntimeException('inside_not_valid');
                }
                $run = $this->finalizeInsideAttempt($run, $stepId);
                $session = (array) ($run['calibration_session'] ?? []);
                $session['phase'] = 'OUTSIDE_FIXED';
                $session['state'] = 'IDLE';
            } elseif ($phase === 'OUTSIDE_FIXED') {
                $out = (array) ($session['outside_valid'] ?? []);
                if (($out['sufficient'] ?? false) !== true
                    || ($out['detected'] ?? false) === true
                    || (int) ($out['config_version'] ?? 0) !== $cv) {
                    throw new \RuntimeException('invalid_state');
                }
                $session['phase'] = 'OUTSIDE_PASS';
                $session['state'] = 'IDLE';
            } elseif ($phase === 'OUTSIDE_PASS') {
                $pass = (array) ($session['outside_pass'] ?? []);
                if (($pass['sufficient'] ?? false) !== true
                    || ($pass['detected'] ?? false) === true
                    || (int) ($pass['config_version'] ?? 0) !== $cv) {
                    throw new \RuntimeException('invalid_state');
                }
                if (!empty($session['candidates'])) {
                    $session['phase'] = 'REENTRY';
                    $session['state'] = 'IDLE';
                } else {
                    // Sin ajustes no hace falta reentrada: el radar queda
                    // calibrado con interior + exterior + pasillo.
                    $session['phase'] = 'DONE';
                    $session['state'] = 'DONE';
                    $session['result'] = 'PASS';
                }
            } elseif ($phase === 'REENTRY') {
                if (($session['reentry']['detected'] ?? false) !== true
                    || (int) ($session['reentry']['presence']['config_version'] ?? 0) !== $cv) {
                    throw new \RuntimeException('invalid_state');
                }
                $session['phase'] = 'DONE';
                $session['state'] = 'DONE';
                $session['result'] = 'PASS';
            } else {
                throw new \RuntimeException('invalid_state');
            }
            $run['calibration_session'] = $session;
            $this->store->save($run);
            return $run;
        }

        if ($action === 'adjust') {
            if (is_array($active)) {
                throw new \RuntimeException('operation_pending');
            }
            $inside = (array) ($session['inside_valid'] ?? []);
            $outside = (array) ($session['outside_valid'] ?? []);
            $cv = (int) ($session['config_version'] ?? 1);
            $insideValid = ($inside['sufficient'] ?? false) === true
                && ($inside['detected'] ?? false) === true;
            $insideOk = $insideValid && (int) ($inside['config_version'] ?? 0) === $cv;
            $outsideOk = ($outside['sufficient'] ?? false) === true
                && (int) ($outside['config_version'] ?? 0) === $cv;
            if ($phase === 'INSIDE') {
                if (!$insideOk) throw new \RuntimeException('measure_insufficient');
                $outside = [];
            } else {
                // Un ajuste exterior invalida la medición interior por versión,
                // pero la reentrada posterior la vuelve a verificar. Solo el
                // exterior debe pertenecer a la configuración actual.
                if (!$insideValid || !$outsideOk) throw new \RuntimeException('measure_insufficient');
            }
            $outsidePresent = (bool) ($outside['detected'] ?? false);
            if ($phase === 'OUTSIDE_PASS' && (($session['outside_pass']['detected'] ?? false) === true)) {
                // La pasada por el pasillo es la evidencia de comportamiento;
                // no usamos su distancia como punto exterior fijo.
                $outside = ['quality' => RadarDistanceQuality::POOR, 'min' => null, 'max' => null, 'median' => null];
                $outsidePresent = true;
            }
            $current = $this->latestSettings((int) $run['room_id']);
            $caps = [
                'range_min' => (float) ZigbeeRadarCalibrationService::PARAMETERS['maximum_range']['min'],
                'range_max' => (float) ZigbeeRadarCalibrationService::PARAMETERS['maximum_range']['max'],
                'range_step' => 0.75,
                'sens_min' => (int) ZigbeeRadarCalibrationService::PARAMETERS['radar_sensitivity']['min'],
                'sens_max' => (int) ZigbeeRadarCalibrationService::PARAMETERS['radar_sensitivity']['max'],
            ];
            $plan = RadarCalibrationPlanner::plan(
                (array) ($inside['distance'] ?? []),
                (array) ($outside['distance'] ?? []),
                $current,
                $caps,
                [
                    'inside_present' => (bool) ($inside['detected'] ?? false),
                    'outside_present' => $outsidePresent,
                    'reentry_failed' => (($session['reentry']['detected'] ?? null) === false),
                ]
            );
            if ($plan['action'] !== 'apply') {
                $session['state'] = 'STOPPED';
                $session['result'] = $plan['reason'];
                $run['calibration_session'] = $session;
                $this->store->save($run);
                return $run;
            }
            // Evitar repetir un candidato ya probado.
            foreach ((array) ($session['candidates'] ?? []) as $cand) {
                if (($cand['params'] ?? []) === $plan['params']) {
                    $session['state'] = 'STOPPED';
                    $session['result'] = 'candidate_already_tried';
                    $run['calibration_session'] = $session;
                    $this->store->save($run);
                    return $run;
                }
            }
            $devices = new DeviceRepository($pdo);
            $presence = $devices->findForRoomKind((int) $run['room_id'], Device::KIND_PRESENCE);
            if ($presence === null) {
                throw new \RuntimeException('presence_device_missing');
            }
            $cal = new ZigbeeRadarCalibrationService($pdo);
            $pub = $cal->publish((int) $run['room_id'], [
                'id' => (int) $presence->id,
                'external_id' => (string) $presence->externalId,
                'meta' => is_array($presence->meta) ? $presence->meta : [],
            ], $plan['params']);
            if (($pub['ok'] ?? false) !== true) {
                throw new \RuntimeException('publish_failed:' . (string) ($pub['error'] ?? 'unknown'));
            }
            $opId = 'op-' . bin2hex(random_bytes(5));
            if (in_array($phase, ['OUTSIDE_PASS', 'REENTRY'], true)) {
                // Tras una pasada detectada o una reentrada fallida hay que
                // volver a verificar el exterior con la nueva configuración:
                // subir sensibilidad/alcance podría romper la separación.
                $session['phase'] = 'OUTSIDE_FIXED';
                $session['manual_start_required'] = true;
                $phase = 'OUTSIDE_FIXED';
            }
            $op = [
                'operation_id' => $opId,
                'kind' => 'ADJUST',
                'phase' => $phase,
                'state' => 'AWAITING_CONFIRM',
                'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'published_at' => $pub['published_at'],
                'before_event_id' => $pub['before_event_id'],
                'requested' => $plan['params'],
                'before_settings' => $current,
                'reason' => $plan['reason'],
                'confidence' => $plan['confidence'],
            ];
            $session['candidates'][] = [
                'candidate_id' => $opId,
                'params' => $plan['params'],
                'reason' => $plan['reason'],
                'status' => 'PENDING',
                'published_at' => $pub['published_at'],
                'before_settings' => $current,
            ];
            $session['active_operation'] = $op;
            $session['operations'][$opId] = $op;
            $session['state'] = 'AWAITING_CONFIRM';
            $run['calibration_session'] = $session;
            $this->store->save($run);
            return $run;
        }

        if ($action === 'check_confirmation') {
            $run['calibration_session'] = $session;
            $this->store->save($run);
            return $run;
        }

        if ($action === 'restore') {
            if (is_array($active)) {
                throw new \RuntimeException('operation_pending');
            }
            $baseline = (array) ($session['baseline'] ?? []);
            $params = [];
            foreach (['maximum_range', 'radar_sensitivity', 'fading_time', 'detection_delay'] as $key) {
                if (isset($baseline[$key]) && is_numeric($baseline[$key])) {
                    $params[$key] = (float) $baseline[$key];
                }
            }
            if ($params === []) {
                throw new \RuntimeException('no_initial_settings');
            }
            $devices = new DeviceRepository($pdo);
            $presence = $devices->findForRoomKind((int) $run['room_id'], Device::KIND_PRESENCE);
            if ($presence === null) {
                throw new \RuntimeException('presence_device_missing');
            }
            $cal = new ZigbeeRadarCalibrationService($pdo);
            $pub = $cal->publish((int) $run['room_id'], [
                'id' => (int) $presence->id,
                'external_id' => (string) $presence->externalId,
                'meta' => is_array($presence->meta) ? $presence->meta : [],
            ], $params);
            if (($pub['ok'] ?? false) !== true) {
                throw new \RuntimeException('publish_failed:' . (string) ($pub['error'] ?? 'unknown'));
            }
            $opId = 'op-' . bin2hex(random_bytes(5));
            $op = [
                'operation_id' => $opId,
                'kind' => 'RESTORE',
                'phase' => $phase,
                'state' => 'AWAITING_CONFIRM',
                'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'published_at' => $pub['published_at'],
                'before_event_id' => $pub['before_event_id'],
                'requested' => $params,
                'before_settings' => $current,
                'reason' => 'restore',
            ];
            $session['active_operation'] = $op;
            $session['operations'][$opId] = $op;
            $session['state'] = 'AWAITING_CONFIRM';
            $run['calibration_session'] = $session;
            $this->store->save($run);
            return $run;
        }

        throw new \RuntimeException('unknown_action');
    }

    /**
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */

    /**
     * F104/RF-213..RF-216: máquina de autocalibración por barrido.
     *
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function applyRadarOperationV3(array $run, string $stepId, string $attemptId, string $action, \PDO $pdo): array
    {
        $session = (array) ($run['calibration_session'] ?? []);
        if (($session['version'] ?? 0) < 3) {
            throw new \RuntimeException('legacy_calibration');
        }
        $phase = (string) ($session['phase'] ?? 'INSIDE');
        $state = (string) ($session['state'] ?? 'IDLE');
        $active = $session['active_operation'] ?? null;
        $now = time();

        if ($action === 'cancel') {
            if (is_array($active)) {
                $opId = (string) ($active['operation_id'] ?? '');
                $active['state'] = 'CANCELLED';
                $session['operations'][$opId] = $active;
            }
            $session['active_operation'] = null;
            $session['state'] = 'IDLE';
            $session['stabilize_until'] = null;
            unset($session['scan_preview'], $session['manual_start_required']);
            $run['calibration_session'] = $session;
            $this->store->save($run);
            return $run;
        }

        if (in_array($action, ['start_measure', 'start_scan', 'start_verify', 'start_reentry'], true)) {
            if (is_array($active)) {
                throw new \RuntimeException('operation_pending');
            }
            if ($state === 'STABILIZING') {
                throw new \RuntimeException('measure_not_ready');
            }
            if ($action === 'start_measure' && $phase === 'OUTSIDE_SCAN') {
                $action = 'start_scan';
            }
            if ($action === 'start_measure' && $phase === 'OUTSIDE_VERIFY') {
                $action = 'start_verify';
            }
            $roomId = (int) $run['room_id'];
            $snapshot = $this->latestPresenceSnapshot($roomId);
            $live = $this->context()->live($roomId);
            $staleSeconds = (int) ($live['presence_stale_seconds'] ?? 180);
            if ($staleSeconds <= 0) {
                $staleSeconds = 180;
            }
            $kind = 'MEASURE';
            $opPhase = $phase;
            $seed = [];
            $window = $this->radarWindowSeconds((array) ($snapshot['settings'] ?? []), (array) ($session['current'] ?? []));
            if ($action === 'start_reentry') {
                if ($phase !== 'REENTRY') {
                    throw new \RuntimeException('invalid_state');
                }
                $block = RadarEvidencePolicy::reentryBlockReason($snapshot);
                if ($block !== null) {
                    throw new \RuntimeException($block);
                }
                $kind = 'REENTRY';
                $window = 30;
                $session['effects_before_reentry'] = $this->context()->effects($roomId);
                $session['reentry'] = null;
            } elseif ($action === 'start_scan') {
                if ($phase !== 'OUTSIDE_SCAN') {
                    throw new \RuntimeException('invalid_state');
                }
                $block = RadarEvidencePolicy::startBlockReason($snapshot, $staleSeconds);
                if ($block !== null) {
                    throw new \RuntimeException($block);
                }
                $rawState = strtoupper(trim((string) ($snapshot['value'] ?? '')));
                if ($rawState !== 'PRESENT') {
                    throw new \RuntimeException('scan_must_start_inside');
                }
                $kind = 'SCAN';
                $seed = RadarEvidencePolicy::seed($snapshot, $staleSeconds);
                $fading = (float) (($snapshot['settings'] ?? [])['fading_time'] ?? 15.0);
                $window = (int) max(60, min(120, ceil($fading + 30)));
            } elseif ($action === 'start_verify') {
                if ($phase !== 'OUTSIDE_VERIFY') {
                    throw new \RuntimeException('invalid_state');
                }
                $block = RadarEvidencePolicy::startBlockReason($snapshot, $staleSeconds);
                if ($block !== null) {
                    throw new \RuntimeException($block);
                }
                $kind = 'VERIFY';
                $seed = RadarEvidencePolicy::seed($snapshot, $staleSeconds);
                $fading = (float) (($snapshot['settings'] ?? [])['fading_time'] ?? 15.0);
                $window = (int) max(20, min(30, ceil($fading + 5)));
            } else {
                if (!in_array($phase, ['INSIDE', 'OUTSIDE_PASS'], true)) {
                    throw new \RuntimeException('invalid_state');
                }
                $block = RadarEvidencePolicy::startBlockReason($snapshot, $staleSeconds);
                if ($block !== null) {
                    throw new \RuntimeException($block);
                }
                $seed = RadarEvidencePolicy::seed($snapshot, $staleSeconds);
            }

            $windowStart = gmdate('Y-m-d\TH:i:s\Z');
            $opId = 'op-' . bin2hex(random_bytes(5));
            $op = [
                'operation_id' => $opId,
                'kind' => $kind,
                'phase' => $opPhase,
                'state' => 'MEASURING',
                'started_at' => $windowStart,
                'window_start' => $windowStart,
                'window_end' => gmdate('Y-m-d\TH:i:s\Z', $now + $window),
                'window_seconds' => $window,
                'config_version' => (int) ($session['config_version'] ?? 1),
                'requested' => [],
                'before_settings' => $session['current'] ?? [],
                'seed' => $seed,
                'raw_presence_at_start' => $snapshot['value'] ?? null,
                'raw_presence_age_seconds' => $snapshot['settings_age_seconds'] ?? null,
            ];
            unset($session['manual_start_required'], $session['scan_preview']);
            $session['phase'] = $opPhase;
            $session['state'] = 'MEASURING';
            $session['active_operation'] = $op;
            $session['operations'][$opId] = $op;
            $run['calibration_session'] = $session;
            $this->store->save($run);
            return $run;
        }

        if (in_array($action, ['finish_measure', 'finish_scan', 'finish_verify', 'finish_reentry'], true)) {
            if (!is_array($active) || !in_array((string) ($active['state'] ?? ''), ['MEASURING', 'SCAN_READY', 'VERIFY_READY'], true)) {
                throw new \RuntimeException('invalid_state');
            }
            $kind = (string) ($active['kind'] ?? 'MEASURE');
            if ($action === 'finish_scan' && $kind !== 'SCAN') {
                throw new \RuntimeException('invalid_state');
            }
            if ($action === 'finish_verify' && $kind !== 'VERIFY') {
                throw new \RuntimeException('invalid_state');
            }
            if ($action === 'finish_reentry' && $kind !== 'REENTRY') {
                throw new \RuntimeException('invalid_state');
            }
            $end = strtotime((string) ($active['window_end'] ?? '') . ' UTC');
            if ($action === 'finish_measure' && $end !== false && $now < $end) {
                throw new \RuntimeException('measure_in_progress');
            }
            $ctx = $this->context();
            $events = $ctx->eventsSince((int) $run['room_id'], (string) $active['window_start'], 3000);
            $mPhase = (string) ($active['phase'] ?? $phase);
            $windowEnd = gmdate('Y-m-d\TH:i:s\Z');
            $settings = (array) ($session['current'] ?? []);

            if ($kind === 'SCAN') {
                $scan = RadarBoundaryScan::summarize(
                    $events,
                    (string) $active['window_start'],
                    $windowEnd,
                    (int) ($active['config_version'] ?? 1),
                    $settings,
                    (array) ($active['seed'] ?? []),
                    ['min_clear_seconds' => 6, 'min_absent_reports' => 2]
                );
                $active['state'] = 'MEASURED';
                $active['result'] = $scan;
                $session['operations'][(string) $active['operation_id']] = $active;
                $session['active_operation'] = null;
                $session['outside_scan'] = $scan;
                $session['last_measurement'] = $scan;
                unset($session['scan_preview']);
                if (($scan['sufficient'] ?? false) !== true) {
                    $session['state'] = 'STOPPED';
                    $session['result'] = 'scan_insufficient:' . (string) ($scan['reason'] ?? 'unknown');
                    $run['calibration_session'] = $session;
                    $this->store->save($run);
                    return $run;
                }
                $plan = RadarCalibrationPlanner::planMidpoint(
                    $this->insideInputV3($session),
                    $scan,
                    null,
                    $settings,
                    $this->midpointCapsV3(),
                    ['inside_present' => true, 'tried' => (array) ($session['candidates'] ?? [])]
                );
                $session['calibration'] = $this->calibrationRecordV3($session, $plan, $scan, null);
                if ($plan['action'] === 'apply') {
                    $session['phase'] = 'OUTSIDE_SCAN';
                    $run = $this->publishRadarCandidateV3($run, $session, $pdo, (array) $plan['params'],
                        (string) $plan['reason'], 'OUTSIDE_SCAN', 'OUTSIDE_VERIFY', $plan['candidate']);
                    $this->store->save($run);
                    return $run;
                }
                if ($plan['action'] === 'none') {
                    $session['phase'] = 'OUTSIDE_VERIFY';
                    $session['state'] = 'IDLE';
                    $run['calibration_session'] = $session;
                    $this->store->save($run);
                    return $run;
                }
                $session['state'] = 'STOPPED';
                $session['result'] = (string) $plan['reason'];
                $run['calibration_session'] = $session;
                $this->store->save($run);
                return $run;
            }

            $requirements = $kind === 'REENTRY'
                ? ['min_reports' => 1, 'min_span_seconds' => 0, 'require_new_present' => true]
                : [];
            $seed = $kind === 'REENTRY' ? [] : (array) ($active['seed'] ?? []);
            $measurement = RadarMeasurement::build(
                $events,
                (string) $active['window_start'],
                $windowEnd,
                (int) ($active['config_version'] ?? 1),
                $settings,
                $requirements,
                $seed
            );
            if ($kind === 'VERIFY') {
                $detected = (bool) ($measurement['any_present'] ?? false);
            } elseif ($kind === 'REENTRY') {
                $detected = ((int) ($measurement['presence_reports'] ?? 0) > 0)
                    && (($measurement['last_value'] ?? '') === 'PRESENT');
            } else {
                $detected = RadarMeasurement::outcomeForPhase($measurement, $mPhase);
            }
            $measurement['last_state'] = $measurement['last_value'];
            $measurement['detected'] = $detected;
            $measurement['measurement_id'] = 'm-' . bin2hex(random_bytes(4));
            $measurement['phase'] = $mPhase;
            $session['measurements'][] = $measurement;
            $session['last_measurement'] = $measurement;
            $active['state'] = 'MEASURED';
            $active['result'] = $measurement;
            $session['operations'][(string) $active['operation_id']] = $active;
            $session['active_operation'] = null;

            if ($kind === 'SCAN' || $kind === 'VERIFY') {
                // Ya tratados arriba (SCAN) o a continuación (VERIFY).
            }

            if ($kind === 'MEASURE') {
                if ($mPhase === 'INSIDE') {
                    if (($measurement['sufficient'] ?? false) === true) {
                        $session['inside_valid'] = $measurement;
                        $session['inside_ref'] = [
                            'reference' => RadarCalibrationPlanner::ref($measurement, 'inside'),
                            'distance' => $measurement['distance'] ?? [],
                            'quality' => $measurement['distance']['quality'] ?? 'poor',
                            'config_version' => $measurement['config_version'] ?? 1,
                            'measured_at' => gmdate('Y-m-d\TH:i:s\Z'),
                        ];
                    }
                } elseif ($mPhase === 'OUTSIDE_PASS') {
                    if (($measurement['sufficient'] ?? false) === true) {
                        $session['outside_pass'] = $measurement;
                    }
                }
                $session['state'] = 'MEASURED';
                $run['calibration_session'] = $session;
                $this->store->save($run);
                return $run;
            }

            if ($kind === 'VERIFY') {
                if (($measurement['sufficient'] ?? false) !== true) {
                    $session['state'] = 'STOPPED';
                    $session['result'] = 'verify_insufficient:' . (string) ($measurement['reason'] ?? 'unknown');
                    $run['calibration_session'] = $session;
                    $this->store->save($run);
                    return $run;
                }
                $session['outside_valid'] = $measurement;
                if (!$detected) {
                    $session['phase'] = 'OUTSIDE_PASS';
                    $session['state'] = 'IDLE';
                    $session['calibration'] = $this->calibrationRecordV3($session, [], $session['outside_scan'] ?? [], $measurement);
                    $run['calibration_session'] = $session;
                    $this->store->save($run);
                    return $run;
                }
                $plan = RadarCalibrationPlanner::planMidpoint(
                    $this->insideInputV3($session),
                    (array) ($session['outside_scan'] ?? []),
                    $this->outsideInputV3($measurement),
                    $settings,
                    $this->midpointCapsV3(),
                    ['inside_present' => true, 'outside_present' => true,
                        'tried' => (array) ($session['candidates'] ?? [])]
                );
                $session['calibration'] = $this->calibrationRecordV3($session, $plan,
                    (array) ($session['outside_scan'] ?? []), $measurement);
                if ($plan['action'] === 'apply') {
                    $session['phase'] = 'OUTSIDE_VERIFY';
                    $run = $this->publishRadarCandidateV3($run, $session, $pdo, (array) $plan['params'],
                        (string) $plan['reason'], 'OUTSIDE_VERIFY', 'OUTSIDE_VERIFY', $plan['candidate']);
                    $this->store->save($run);
                    return $run;
                }
                $session['state'] = 'STOPPED';
                $session['result'] = (string) $plan['reason'];
                $run['calibration_session'] = $session;
                $this->store->save($run);
                return $run;
            }

            if ($kind === 'REENTRY') {
                if (($measurement['sufficient'] ?? false) !== true) {
                    $session['state'] = 'STOPPED';
                    $session['result'] = 'reentry_insufficient:' . (string) ($measurement['reason'] ?? 'unknown');
                    $run['calibration_session'] = $session;
                    $this->store->save($run);
                    return $run;
                }
                $session['reentry'] = [
                    'detected' => $detected,
                    'at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'first_present_at' => $measurement['first_present_at'] ?? null,
                    'presence' => $measurement,
                    'effects_before_reentry' => $session['effects_before_reentry'] ?? [],
                ];
                if (!$detected) {
                    $plan = RadarCalibrationPlanner::planMidpoint(
                        $this->insideInputV3($session),
                        (array) ($session['outside_scan'] ?? []),
                        null,
                        $settings,
                        $this->midpointCapsV3(),
                        ['inside_present' => true, 'reentry_failed' => true,
                            'tried' => (array) ($session['candidates'] ?? [])]
                    );
                    $session['calibration'] = $this->calibrationRecordV3($session, $plan,
                        (array) ($session['outside_scan'] ?? []), null);
                    if ($plan['action'] === 'apply') {
                        $session['phase'] = 'REENTRY';
                        $run = $this->publishRadarCandidateV3($run, $session, $pdo, (array) $plan['params'],
                            (string) $plan['reason'], 'REENTRY', 'REENTRY', $plan['candidate']);
                        $this->store->save($run);
                        return $run;
                    }
                    $session['state'] = 'REJECTED';
                    $session['result'] = (string) $plan['reason'];
                    $run['calibration_session'] = $session;
                    $this->store->save($run);
                    return $run;
                }
                $session['state'] = 'MEASURED';
                $run['calibration_session'] = $session;
                $this->store->save($run);
                return $run;
            }

            $session['state'] = 'MEASURED';
            $run['calibration_session'] = $session;
            $this->store->save($run);
            return $run;
        }

        if ($action === 'advance') {
            $cv = (int) ($session['config_version'] ?? 1);
            if ($phase === 'INSIDE') {
                $inside = (array) ($session['inside_valid'] ?? []);
                if (($inside['sufficient'] ?? false) !== true
                    || ($inside['detected'] ?? false) !== true
                    || (int) ($inside['config_version'] ?? 0) !== $cv) {
                    throw new \RuntimeException('inside_not_valid');
                }
                $run = $this->finalizeInsideAttempt($run, $stepId);
                $session = (array) ($run['calibration_session'] ?? []);
                $session['phase'] = 'OUTSIDE_SCAN';
                $session['state'] = 'IDLE';
            } elseif ($phase === 'OUTSIDE_VERIFY') {
                $out = (array) ($session['outside_valid'] ?? []);
                if (($out['sufficient'] ?? false) !== true
                    || ($out['detected'] ?? false) === true
                    || (int) ($out['config_version'] ?? 0) !== $cv) {
                    throw new \RuntimeException('invalid_state');
                }
                $session['phase'] = 'OUTSIDE_PASS';
                $session['state'] = 'IDLE';
            } elseif ($phase === 'OUTSIDE_PASS') {
                $pass = (array) ($session['outside_pass'] ?? []);
                if (($pass['sufficient'] ?? false) !== true
                    || ($pass['detected'] ?? false) === true
                    || (int) ($pass['config_version'] ?? 0) !== $cv) {
                    throw new \RuntimeException('invalid_state');
                }
                if (!empty($session['candidates'])) {
                    $session['phase'] = 'REENTRY';
                    $session['state'] = 'IDLE';
                } else {
                    $session['phase'] = 'DONE';
                    $session['state'] = 'DONE';
                    $session['result'] = 'PASS';
                }
            } elseif ($phase === 'REENTRY') {
                if (($session['reentry']['detected'] ?? false) !== true
                    || (int) ($session['reentry']['presence']['config_version'] ?? 0) !== $cv) {
                    throw new \RuntimeException('invalid_state');
                }
                $session['phase'] = 'DONE';
                $session['state'] = 'DONE';
                $session['result'] = 'PASS';
            } else {
                throw new \RuntimeException('invalid_state');
            }
            $run['calibration_session'] = $session;
            $this->store->save($run);
            return $run;
        }

        if ($action === 'restore') {
            if (is_array($active)) {
                throw new \RuntimeException('operation_pending');
            }
            $baseline = (array) ($session['baseline'] ?? []);
            $params = [];
            foreach (['maximum_range', 'radar_sensitivity', 'fading_time', 'detection_delay'] as $key) {
                if (isset($baseline[$key]) && is_numeric($baseline[$key])) {
                    $params[$key] = (float) $baseline[$key];
                }
            }
            if ($params === []) {
                throw new \RuntimeException('no_initial_settings');
            }
            $devices = new DeviceRepository($pdo);
            $presence = $devices->findForRoomKind((int) $run['room_id'], Device::KIND_PRESENCE);
            if ($presence === null) {
                throw new \RuntimeException('presence_device_missing');
            }
            $cal = new ZigbeeRadarCalibrationService($pdo);
            $pub = $cal->publish((int) $run['room_id'], [
                'id' => (int) $presence->id,
                'external_id' => (string) $presence->externalId,
                'meta' => is_array($presence->meta) ? $presence->meta : [],
            ], $params);
            if (($pub['ok'] ?? false) !== true) {
                throw new \RuntimeException('publish_failed:' . (string) ($pub['error'] ?? 'unknown'));
            }
            $opId = 'op-' . bin2hex(random_bytes(5));
            $op = [
                'operation_id' => $opId,
                'kind' => 'RESTORE',
                'phase' => $phase,
                'state' => 'AWAITING_CONFIRM',
                'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'published_at' => $pub['published_at'],
                'before_event_id' => $pub['before_event_id'],
                'requested' => $params,
                'before_settings' => $session['current'] ?? [],
                'reason' => 'restore',
                'next_phase' => 'INSIDE',
            ];
            $session['active_operation'] = $op;
            $session['operations'][$opId] = $op;
            $session['state'] = 'AWAITING_CONFIRM';
            $run['calibration_session'] = $session;
            $this->store->save($run);
            return $run;
        }

        if ($action === 'check_confirmation') {
            $run['calibration_session'] = $session;
            $this->store->save($run);
            return $run;
        }

        throw new \RuntimeException('unknown_action');
    }

    /**
     * Publica un candidato y deja la operación pendiente de confirmación push.
     *
     * @param array<string,mixed> $run
     * @param array<string,mixed> $session
     * @param array<string,int|float> $params
     * @return array<string,mixed>
     */
    private function publishRadarCandidateV3(
        array $run,
        array $session,
        \PDO $pdo,
        array $params,
        string $reason,
        string $phase,
        string $nextPhase,
        ?float $candidate
    ): array {
        $devices = new DeviceRepository($pdo);
        $presence = $devices->findForRoomKind((int) $run['room_id'], Device::KIND_PRESENCE);
        if ($presence === null) {
            throw new \RuntimeException('presence_device_missing');
        }
        $cal = new ZigbeeRadarCalibrationService($pdo);
        $pub = $cal->publish((int) $run['room_id'], [
            'id' => (int) $presence->id,
            'external_id' => (string) $presence->externalId,
            'meta' => is_array($presence->meta) ? $presence->meta : [],
        ], $params);
        if (($pub['ok'] ?? false) !== true) {
            throw new \RuntimeException('publish_failed:' . (string) ($pub['error'] ?? 'unknown'));
        }
        $opId = 'op-' . bin2hex(random_bytes(5));
        $op = [
            'operation_id' => $opId,
            'kind' => 'ADJUST',
            'phase' => $phase,
            'state' => 'AWAITING_CONFIRM',
            'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'published_at' => $pub['published_at'],
            'before_event_id' => $pub['before_event_id'],
            'requested' => $params,
            'before_settings' => $session['current'] ?? [],
            'reason' => $reason,
            'candidate' => $candidate,
            'next_phase' => $nextPhase,
        ];
        $session['candidates'][] = [
            'candidate_id' => $opId,
            'params' => $params,
            'reason' => $reason,
            'status' => 'PENDING',
            'published_at' => $pub['published_at'],
            'before_settings' => $session['current'] ?? [],
        ];
        $session['active_operation'] = $op;
        $session['operations'][$opId] = $op;
        $session['state'] = 'AWAITING_CONFIRM';
        $run['calibration_session'] = $session;
        return $run;
    }

    /** @param array<string,mixed> $session */
    private function insideInputV3(array $session): array
    {
        $inside = (array) ($session['inside_valid'] ?? []);
        $ref = (array) ($session['inside_ref'] ?? []);
        return [
            'detected' => ($inside['detected'] ?? false) === true,
            'sufficient' => ($inside['sufficient'] ?? false) === true,
            'reference' => isset($ref['reference']) ? (float) $ref['reference'] : null,
            'distance' => $ref['distance'] ?? ($inside['distance'] ?? []),
            'quality' => $ref['quality'] ?? ($inside['distance']['quality'] ?? 'poor'),
        ];
    }

    /** @param array<string,mixed> $measurement */
    private function outsideInputV3(array $measurement): array
    {
        $distance = (array) ($measurement['distance'] ?? []);
        return [
            'detected' => true,
            'reference' => RadarCalibrationPlanner::ref($measurement, 'outside'),
            'distance' => $distance,
            'quality' => $distance['quality'] ?? 'poor',
        ];
    }

    /** @return array{range_min:float,range_max:float,range_step:float,sens_min:int,sens_max:int,min_separation:float,margin:float,coarse_step:float} */
    private function midpointCapsV3(): array
    {
        return [
            'range_min' => (float) ZigbeeRadarCalibrationService::PARAMETERS['maximum_range']['min'],
            'range_max' => (float) ZigbeeRadarCalibrationService::PARAMETERS['maximum_range']['max'],
            'range_step' => 0.15,
            'sens_min' => (int) ZigbeeRadarCalibrationService::PARAMETERS['radar_sensitivity']['min'],
            'sens_max' => (int) ZigbeeRadarCalibrationService::PARAMETERS['radar_sensitivity']['max'],
            'min_separation' => 0.45,
            'margin' => 0.15,
            'coarse_step' => 0.75,
        ];
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $plan @param array<string,mixed> $scan @param array<string,mixed>|null $outside */
    private function calibrationRecordV3(array $session, array $plan, array $scan, ?array $outside): array
    {
        $ref = (array) ($session['inside_ref'] ?? []);
        $previous = is_array($session['calibration'] ?? null) ? $session['calibration'] : [];
        return [
            'inside_reference' => $plan['inside_reference'] ?? ($ref['reference'] ?? null),
            'boundary' => $plan['boundary'] ?? ($scan['boundary_distance'] ?? null),
            'outside_point' => $plan['outside_point'] ?? ($outside !== null
                ? RadarCalibrationPlanner::ref($outside, 'outside') : null),
            'target_range' => $plan['candidate']
                ?? ($previous['target_range'] ?? (($session['current'] ?? [])['maximum_range'] ?? null)),
            'sensitivity' => (int) round((float) (($session['current'] ?? [])['radar_sensitivity'] ?? 0)),
            'reason' => $plan['reason'] ?? 'verified',
            'confidence' => $plan['confidence'] ?? ($previous['confidence'] ?? 'medium'),
            'attempts' => count((array) ($session['candidates'] ?? [])),
            'applied' => (bool) ($previous['applied'] ?? false),
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function finalizeInsideAttempt(array $run, string $stepId): array
    {
        $step = (array) ($run['steps'][$stepId] ?? []);
        $active = $step['active_attempt'] ?? null;
        if (is_array($active)) {
            $active['status'] = 'PASS';
            $active['checked_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $active['verdict'] = [
                'status' => 'PASS',
                'reason' => 'inside_calibrated',
                'details' => [
                    'measurement' => $run['calibration_session']['inside_valid'] ?? null,
                ],
            ];
            $step['attempts'][] = $active;
            unset($step['active_attempt']);
            $step['status'] = 'PASS';
            $run['steps'][$stepId] = $step;
        }
        return $run;
    }

    /**
     * @param array<string,mixed> $session
     * @return list<string>
     */
    private function radarNextActions(array $session): array
    {
        if ((int) ($session['version'] ?? 0) >= 3) {
            return $this->radarNextActionsV3($session);
        }
        $phase = (string) ($session['phase'] ?? 'INSIDE');
        $state = (string) ($session['state'] ?? 'IDLE');
        $active = is_array($session['active_operation'] ?? null);
        if ($phase === 'DONE') {
            return ['validate', 'restore'];
        }
        if ($state === 'AWAITING_CONFIRM') {
            return ['check_confirmation', 'restore', 'cancel'];
        }
        if ($state === 'STABILIZING') {
            return ['cancel'];
        }
        if ($state === 'INDETERMINATE') {
            return ['check_confirmation', 'restore', 'cancel'];
        }
        if ($state === 'INVALID_SETTINGS') {
            return ['restore', 'cancel'];
        }
        if ($state === 'REJECTED') {
            return ['adjust', 'restore', 'cancel'];
        }
        if ($state === 'MEASURING') {
            $end = isset($session['active_operation']['window_end'])
                ? (strtotime((string) $session['active_operation']['window_end'] . ' UTC') ?: 0)
                : 0;
            return $end > 0 && time() >= $end ? ['finish_measure', 'cancel'] : ['cancel'];
        }
        if ($state === 'MEASURED') {
            $last = (array) ($session['last_measurement'] ?? []);
            if (($last['sufficient'] ?? false) !== true) {
                // Sin evidencia no se ofrece ajustar ni avanzar: solo repetir la
                // medición, restaurar o cancelar. En reentrada se repite el clic
                // de entrada, no una medición genérica.
                return $phase === 'REENTRY'
                    ? ['start_reentry', 'restore', 'cancel']
                    : ['start_measure', 'restore', 'cancel'];
            }
            if ($phase === 'INSIDE') {
                $m = (array) ($session['inside_valid'] ?? []);
                return ($m['detected'] ?? false) === true
                    ? ['advance'] : ['adjust', 'restore', 'cancel'];
            }
            if ($phase === 'OUTSIDE_FIXED') {
                $m = (array) ($session['outside_valid'] ?? []);
                return ($m['detected'] ?? false) === false
                    ? ['advance'] : ['adjust', 'restore', 'cancel'];
            }
            if ($phase === 'OUTSIDE_PASS') {
                $m = (array) ($session['outside_pass'] ?? []);
                return ($m['detected'] ?? false) === false
                    ? ['advance'] : ['adjust', 'restore', 'cancel'];
            }
            if ($phase === 'REENTRY') {
                return (($session['reentry']['detected'] ?? false) === true)
                    ? ['advance', 'restore'] : ['adjust', 'restore', 'cancel'];
            }
        }
        if ($phase === 'REENTRY') {
            return ['start_reentry', 'restore', 'cancel'];
        }
        if ($state === 'STOPPED') {
            return ['restore', 'cancel'];
        }
        return ['start_measure', 'restore', 'cancel'];
    }

    /**
     * @param array<string,mixed> $session
     * @return array{kind:string,seconds_remaining:int}|null
     */
    private function radarCountdown(array $session): ?array
    {
        if ((int) ($session['version'] ?? 0) >= 3) {
            return $this->radarCountdownV3($session);
        }
        $op = $session['active_operation'] ?? null;
        if (!is_array($op)) {
            return null;
        }
        $now = time();
        $state = (string) ($op['state'] ?? '');
        if ($state === 'MEASURING') {
            $end = strtotime((string) ($op['window_end'] ?? '') . ' UTC');
            return ['kind' => 'measure', 'seconds_remaining' => $end === false ? 0 : max(0, $end - $now)];
        }
        if ($state === 'AWAITING_CONFIRM') {
            $deadline = strtotime((string) ($op['published_at'] ?? '') . ' UTC') + 15;
            return ['kind' => 'confirm', 'seconds_remaining' => max(0, $deadline - $now)];
        }
        if (($session['state'] ?? '') === 'STABILIZING') {
            $until = strtotime((string) ($session['stabilize_until'] ?? '') . ' UTC');
            return ['kind' => 'stabilize', 'seconds_remaining' => $until === false ? 0 : max(0, $until - $now)];
        }
        return null;
    }

    /**
     * @param array<string,mixed> $session
     * @return array{title:string,instruction:string,message:string}
     */
    private function radarCopy(array $session): array
    {
        if ((int) ($session['version'] ?? 0) >= 3) {
            return $this->radarCopyV3($session);
        }
        $phase = (string) ($session['phase'] ?? 'INSIDE');
        $state = (string) ($session['state'] ?? 'IDLE');
        if ($state === 'AWAITING_CONFIRM') {
            return ['title' => 'Ajuste pendiente', 'instruction' => 'Espera la confirmación del radar. No hagas otra medición.', 'message' => 'El ajuste se ha enviado; falta que el dispositivo lo reporte.'];
        }
        if ($state === 'STABILIZING') {
            return ['title' => 'Estabilizando el radar', 'instruction' => 'Mantente en la posición de la prueba; el radar está aplicando el ajuste.', 'message' => 'Espera a que termine la estabilización.'];
        }
        if ($state === 'INDETERMINATE') {
            return ['title' => 'Confirmación pendiente', 'instruction' => 'No sabemos si el ajuste se aplicó. Comprueba la confirmación o restaura.', 'message' => 'No se ha recibido un reporte nuevo que coincida con la orden.'];
        }
        if ($state === 'INVALID_SETTINGS') {
            return ['title' => 'Configuración inválida', 'instruction' => 'Restaura los ajustes iniciales antes de continuar.', 'message' => 'El radar reporta valores que no permiten detectar correctamente.'];
        }
        if ($state === 'REJECTED') {
            return ['title' => 'Reentrada no detectada', 'instruction' => 'El ajuste ha reducido demasiado la detección. Ajusta de nuevo o restaura.', 'message' => 'No se detectó presencia al volver a entrar.'];
        }
        if ($state === 'MEASURING') {
            return ['title' => $phase === 'REENTRY' ? 'Comprobando reentrada' : 'Midiendo ' . ($phase === 'INSIDE' ? 'dentro' : 'fuera'),
                'instruction' => $phase === 'REENTRY' ? 'Permanece dentro y quieto.' : ($phase === 'INSIDE' ? 'Permanece quieto; el radar solo envía cuando cambia algo.' : 'Mantente fuera.'),
                'message' => 'Ventana de medición en curso.'];
        }
        if ($state === 'MEASURED') {
            $last = (array) ($session['last_measurement'] ?? []);
            if (($last['sufficient'] ?? false) !== true) {
                if ($phase === 'REENTRY') {
                    return ['title' => 'No se detectó tu entrada', 'instruction' => 'Sal, espera a que el radar te pierda y pulsa «Voy a entrar» antes de entrar.', 'message' => 'La ventana no recibió un PRESENT real.'];
                }
                if (($last['reason'] ?? '') === 'stale_state') {
                    return ['title' => 'Estado del radar antiguo', 'instruction' => 'Muévete ligeramente para que el radar reporte y vuelve a medir.', 'message' => 'No hay un estado reciente que sirva de referencia.'];
                }
                if ((int) ($last['reports'] ?? 0) === 0 && ($last['seed_used'] ?? false) !== true) {
                    return ['title' => 'Sin datos del radar', 'instruction' => 'No llegó ningún reporte en la ventana. Muévete ligeramente y vuelve a medir.', 'message' => 'El radar no está enviando datos nuevos.'];
                }
                return ['title' => 'Evidencia insuficiente', 'instruction' => 'No hay evidencia suficiente en esta ventana. Mantén la posición y vuelve a medir.', 'message' => 'La ventana no ha reunido evidencia suficiente.'];
            }
            if ($phase === 'INSIDE' && (($session['inside_valid']['detected'] ?? false) !== true)) {
                if (($last['continuous'] ?? true) === false && ($last['last_value'] ?? '') === 'PRESENT') {
                    return ['title' => 'La presencia no fue continua', 'instruction' => 'Mantente quieto y vuelve a medir. Si se repite, ajusta sensibilidad/alcance o revisa la orientación.', 'message' => 'El radar dejó de detectarte durante la ventana.'];
                }
                return ['title' => 'No se detectó presencia', 'instruction' => 'Colócate en el punto más lejano y ajusta la sensibilidad o el alcance. Si no mejora, revisa la orientación del radar.', 'message' => 'La medición fue válida, pero el radar no te detectó.'];
            }
            if ($phase === 'OUTSIDE_FIXED' && (($session['outside_valid']['detected'] ?? false) === true)) {
                return ['title' => 'El radar detecta fuera', 'instruction' => 'Ajusta el alcance para dejar de detectar en el punto exterior y vuelve a medir.', 'message' => 'La medición fue válida, pero el radar alcanza el punto exterior.'];
            }
            if ($phase === 'OUTSIDE_PASS' && (($session['outside_pass']['detected'] ?? false) === true)) {
                return ['title' => 'El radar detecta el pasillo', 'instruction' => 'Ajusta el alcance o la sensibilidad para no detectar el paso por el pasillo.', 'message' => 'La medición fue válida, pero el radar detecta el pasillo.'];
            }
            if ($phase === 'REENTRY') {
                return ['title' => 'Reentrada detectada', 'instruction' => 'Continúa para cerrar la fase del radar.', 'message' => 'El radar volvió a detectarte al entrar.'];
            }
            $held = ($last['evidence_mode'] ?? '') === RadarMeasurement::EVIDENCE_STATE_HOLD;
            return [
                'title' => 'Medición completada',
                'instruction' => 'Revisa el resultado y continúa con la siguiente acción.',
                'message' => $held
                    ? 'El radar mantuvo el estado sin nuevos reportes (solo envía al cambiar).'
                    : 'La ventana se ha cerrado.',
            ];
        }
        if ($phase === 'INSIDE') {
            return ['title' => 'Comprobar cobertura interior', 'instruction' => 'Colócate en el punto más lejano donde debe detectarte. Pulsa Empezar medición y permanece quieto hasta que termine la cuenta atrás. El radar solo envía cambios; si no hay reportes nuevos, se usa el estado al inicio.', 'message' => 'Primero verificamos el interior.'];
        }
        if ($phase === 'OUTSIDE_FIXED') {
            return ['title' => 'Comprobar punto exterior', 'instruction' => 'Sal y cierra la puerta. Espera a que el radar deje de detectarte. Colócate en el punto que no debe detectarse y pulsa Empezar medición.', 'message' => 'Comprobamos el punto exterior fijo.'];
        }
        if ($phase === 'OUTSIDE_PASS') {
            return ['title' => 'Pasada por el pasillo', 'instruction' => 'Colócate fuera y pulsa Empezar medición. Durante la ventana, pasa tres veces por delante de la puerta.', 'message' => 'Comprobamos el movimiento en el pasillo.'];
        }
        if ($phase === 'REENTRY') {
            return ['title' => 'Comprobar reentrada', 'instruction' => 'Sal y espera a que el radar deje de detectarte. Pulsa «Voy a entrar» y entra, cierra y quédate dentro.', 'message' => 'Comprobamos que el ajuste sigue detectando al volver.'];
        }
        if ($phase === 'DONE') {
            return ['title' => 'Radar calibrado', 'instruction' => 'Valida el radar para cerrar la fase.', 'message' => 'Todas las comprobaciones están completas.'];
        }
        return ['title' => 'Radar', 'instruction' => '', 'message' => ''];
    }


    /**
     * @param array<string,mixed> $session
     * @return list<string>
     */
    private function radarNextActionsV3(array $session): array
    {
        $phase = (string) ($session['phase'] ?? 'INSIDE');
        $state = (string) ($session['state'] ?? 'IDLE');
        $op = $session['active_operation'] ?? null;
        if ($phase === 'DONE') {
            return ['validate', 'restore'];
        }
        if ($state === 'AWAITING_CONFIRM') {
            return ['check_confirmation', 'restore', 'cancel'];
        }
        if ($state === 'STABILIZING') {
            return ['cancel'];
        }
        if ($state === 'INDETERMINATE') {
            return ['check_confirmation', 'restore', 'cancel'];
        }
        if ($state === 'INVALID_SETTINGS') {
            return ['restore', 'cancel'];
        }
        if ($state === 'SCAN_READY') {
            return ['finish_scan', 'cancel'];
        }
        if ($state === 'VERIFY_READY') {
            return ['finish_verify', 'cancel'];
        }
        if ($state === 'STOPPED') {
            return match ($phase) {
                'OUTSIDE_SCAN' => ['start_scan', 'restore', 'cancel'],
                'OUTSIDE_VERIFY' => ['start_verify', 'restore', 'cancel'],
                'REENTRY' => ['start_reentry', 'restore', 'cancel'],
                default => ['restore', 'cancel'],
            };
        }
        if ($state === 'REJECTED') {
            return ['start_reentry', 'restore', 'cancel'];
        }
        if ($state === 'MEASURING' && is_array($op)) {
            $kind = (string) ($op['kind'] ?? 'MEASURE');
            $end = isset($op['window_end']) ? (strtotime((string) $op['window_end'] . ' UTC') ?: 0) : 0;
            if ($kind === 'SCAN') {
                return ['cancel'];
            }
            if ($kind === 'VERIFY') {
                return $end > 0 && time() >= $end ? ['finish_verify', 'cancel'] : ['cancel'];
            }
            if ($kind === 'REENTRY') {
                return $end > 0 && time() >= $end ? ['finish_reentry', 'cancel'] : ['cancel'];
            }
            return $end > 0 && time() >= $end ? ['finish_measure', 'cancel'] : ['cancel'];
        }
        if ($state === 'MEASURED') {
            $last = (array) ($session['last_measurement'] ?? []);
            if (($last['sufficient'] ?? false) !== true) {
                return match ($phase) {
                    'OUTSIDE_SCAN' => ['start_scan', 'restore', 'cancel'],
                    'OUTSIDE_VERIFY' => ['start_verify', 'restore', 'cancel'],
                    'REENTRY' => ['start_reentry', 'restore', 'cancel'],
                    default => ['start_measure', 'restore', 'cancel'],
                };
            }
            if ($phase === 'INSIDE') {
                return (($session['inside_valid']['detected'] ?? false) === true)
                    ? ['advance'] : ['start_measure', 'restore', 'cancel'];
            }
            if ($phase === 'OUTSIDE_PASS') {
                return (($session['outside_pass']['detected'] ?? false) === false)
                    ? ['advance'] : ['start_measure', 'restore', 'cancel'];
            }
            if ($phase === 'REENTRY') {
                return (($session['reentry']['detected'] ?? false) === true)
                    ? ['advance', 'restore'] : ['start_reentry', 'restore', 'cancel'];
            }
        }
        return match ($phase) {
            'OUTSIDE_SCAN' => ['start_scan', 'restore', 'cancel'],
            'OUTSIDE_VERIFY' => ['start_verify', 'restore', 'cancel'],
            'OUTSIDE_PASS' => ['start_measure', 'restore', 'cancel'],
            'REENTRY' => ['start_reentry', 'restore', 'cancel'],
            default => ['start_measure', 'restore', 'cancel'],
        };
    }

    /** @param array<string,mixed> $session */
    private function radarCountdownV3(array $session): ?array
    {
        $op = $session['active_operation'] ?? null;
        if (!is_array($op)) {
            return null;
        }
        $now = time();
        $state = (string) ($op['state'] ?? '');
        if ($state === 'MEASURING') {
            $end = strtotime((string) ($op['window_end'] ?? '') . ' UTC');
            $kind = match ((string) ($op['kind'] ?? '')) {
                'SCAN' => 'scan',
                'VERIFY' => 'verify',
                default => 'measure',
            };
            return ['kind' => $kind, 'seconds_remaining' => $end === false ? 0 : max(0, $end - $now)];
        }
        if ($state === 'AWAITING_CONFIRM') {
            $deadline = strtotime((string) ($op['published_at'] ?? '') . ' UTC') + 15;
            return ['kind' => 'confirm', 'seconds_remaining' => max(0, $deadline - $now)];
        }
        if (($session['state'] ?? '') === 'STABILIZING') {
            $until = strtotime((string) ($session['stabilize_until'] ?? '') . ' UTC');
            return ['kind' => 'stabilize', 'seconds_remaining' => $until === false ? 0 : max(0, $until - $now)];
        }
        return null;
    }

    /**
     * @param array<string,mixed> $session
     * @return array{title:string,instruction:string,message:string}
     */
    private function radarCopyV3(array $session): array
    {
        $phase = (string) ($session['phase'] ?? 'INSIDE');
        $state = (string) ($session['state'] ?? 'IDLE');
        if ($state === 'AWAITING_CONFIRM') {
            return ['title' => 'Aplicando ajuste', 'instruction' => 'Espera la confirmación del radar. No hagas otra medición.', 'message' => 'El ajuste se ha enviado; falta que el dispositivo lo reporte.'];
        }
        if ($state === 'STABILIZING') {
            return ['title' => 'Estabilizando el radar', 'instruction' => 'El radar está aplicando el ajuste. Mantente en la posición actual.', 'message' => 'Espera a que termine la estabilización.'];
        }
        if ($state === 'INDETERMINATE') {
            return ['title' => 'Confirmación pendiente', 'instruction' => 'No sabemos si el ajuste se aplicó. Comprueba la confirmación o restaura.', 'message' => 'No se ha recibido un reporte nuevo que coincida con la orden.'];
        }
        if ($state === 'INVALID_SETTINGS') {
            return ['title' => 'Configuración inválida', 'instruction' => 'Restaura los ajustes iniciales antes de continuar.', 'message' => 'El radar reporta valores que no permiten detectar correctamente.'];
        }
        if ($state === 'STOPPED') {
            $reason = (string) ($session['result'] ?? '');
            if ($reason === 'no_distance_separation') {
                return ['title' => 'Sin separación por distancia', 'instruction' => 'El radar te detecta fuera más cerca que el punto interior. No voy a bajar la sensibilidad a ciegas: revisa la colocación y repite el barrido.', 'message' => 'No existe un alcance que detecte dentro y deje de detectar fuera.'];
            }
            if (str_starts_with($reason, 'scan_insufficient:')) {
                return ['title' => 'Barrido incompleto', 'instruction' => 'No se pudo medir la frontera. Entra, colócate en el punto interior y repite el barrido caminando recto.', 'message' => $reason];
            }
            if (str_starts_with($reason, 'verify_insufficient:')) {
                return ['title' => 'Verificación incompleta', 'instruction' => 'No hubo evidencia suficiente. Repite la comprobación exterior sin moverte.', 'message' => $reason];
            }
            return ['title' => 'Calibración detenida', 'instruction' => 'Revisa el resultado o restaura los ajustes iniciales.', 'message' => $reason];
        }
        if ($state === 'REJECTED') {
            return ['title' => 'Reentrada no detectada', 'instruction' => 'El ajuste ha reducido demasiado la detección. Se ha intentado corregir; si sigue igual, restaura.', 'message' => 'No se detectó presencia al volver a entrar.'];
        }
        if ($state === 'SCAN_READY') {
            return ['title' => 'Frontera detectada', 'instruction' => 'Cerrando el barrido y calculando el punto medio…', 'message' => 'El radar ha dejado de detectarte.'];
        }
        if ($state === 'VERIFY_READY') {
            return ['title' => 'Comprobación terminada', 'instruction' => 'Cerrando la verificación exterior…', 'message' => 'La ventana ha terminado.'];
        }
        if ($state === 'MEASURING') {
            $kind = (string) ($session['active_operation']['kind'] ?? 'MEASURE');
            return match ($kind) {
                'SCAN' => ['title' => 'Barrido de salida', 'instruction' => 'Camina despacio hacia fuera hasta que el radar te pierda. No cambies ningún ajuste.', 'message' => 'Midiendo la frontera real de detección.'],
                'VERIFY' => ['title' => 'Comprobando el punto exterior', 'instruction' => 'Quédate quieto en el punto donde no quieres que te detecte.', 'message' => 'Ventana de verificación exterior.'],
                'REENTRY' => ['title' => 'Comprobando reentrada', 'instruction' => 'Permanece dentro y quieto.', 'message' => 'Ventana de reentrada.'],
                default => ['title' => 'Midiendo', 'instruction' => 'Permanece quieto.', 'message' => 'Ventana de medición en curso.'],
            };
        }
        if ($state === 'MEASURED') {
            $last = (array) ($session['last_measurement'] ?? []);
            if (($last['sufficient'] ?? false) !== true) {
                if ($phase === 'OUTSIDE_SCAN') {
                    return ['title' => 'Barrido sin frontera', 'instruction' => 'No se detectó la transición. Entra, colócate en el punto interior y repite el barrido.', 'message' => (string) ($last['reason'] ?? '')];
                }
                if ($phase === 'OUTSIDE_VERIFY') {
                    return ['title' => 'Verificación sin evidencia', 'instruction' => 'Mantente quieto en el punto exterior y repite la comprobación.', 'message' => (string) ($last['reason'] ?? '')];
                }
                return ['title' => 'Evidencia insuficiente', 'instruction' => 'Mantén la posición y vuelve a medir.', 'message' => 'La ventana no ha reunido evidencia suficiente.'];
            }
            if ($phase === 'INSIDE') {
                if (($session['inside_valid']['detected'] ?? false) !== true) {
                    return ['title' => 'No se detectó presencia', 'instruction' => 'Colócate en el punto más lejano y vuelve a medir. Si se repite, revisa la orientación del radar.', 'message' => 'La medición fue válida, pero el radar no te detectó.'];
                }
                return ['title' => 'Interior medido', 'instruction' => 'Continúa para hacer el barrido de salida.', 'message' => 'Referencia interior guardada.'];
            }
            if ($phase === 'OUTSIDE_PASS' && (($session['outside_pass']['detected'] ?? false) === true)) {
                return ['title' => 'El radar detecta el pasillo', 'instruction' => 'Vuelve a medir la pasada. Si se repite, restaura o revisa la colocación.', 'message' => 'La medición fue válida, pero el radar detecta el pasillo.'];
            }
            if ($phase === 'REENTRY') {
                return ['title' => 'Reentrada detectada', 'instruction' => 'Continúa para cerrar la fase del radar.', 'message' => 'El radar volvió a detectarte al entrar.'];
            }
            return ['title' => 'Medición completada', 'instruction' => 'Revisa el resultado y continúa.', 'message' => 'La ventana se ha cerrado.'];
        }
        return match ($phase) {
            'INSIDE' => ['title' => 'Comprobar cobertura interior', 'instruction' => 'Colócate en el punto más lejano donde SÍ debe detectarte. Pulsa Empezar medición y permanece quieto hasta que termine la cuenta atrás.', 'message' => 'Primero guardamos la referencia interior.'],
            'OUTSIDE_SCAN' => ['title' => 'Barrido de salida', 'instruction' => 'Vuelve al punto interior y pulsa Empezar barrido. Después sal caminando despacio hasta que el radar te pierda; la app calculará el punto medio y lo aplicará sola.', 'message' => 'Vamos a medir la frontera exterior real.'],
            'OUTSIDE_VERIFY' => ['title' => 'Verificar el punto exterior', 'instruction' => 'Colócate en el punto donde NO quieres que te detecte y pulsa Verificar fuera. Permanece quieto durante la ventana.', 'message' => 'Comprobamos que el ajuste deja de detectarte fuera.'],
            'OUTSIDE_PASS' => ['title' => 'Pasada por el pasillo', 'instruction' => 'Colócate fuera y pulsa Empezar medición. Durante la ventana, pasa tres veces por delante de la puerta.', 'message' => 'Comprobamos el movimiento en el pasillo.'],
            'REENTRY' => ['title' => 'Comprobar reentrada', 'instruction' => 'Sal, espera a que el radar te pierda, pulsa «Voy a entrar» y entra, cierra y quédate dentro.', 'message' => 'Comprobamos que el ajuste sigue detectando al volver.'],
            'DONE' => ['title' => 'Radar calibrado', 'instruction' => 'Valida el radar para cerrar la fase.', 'message' => 'Todas las comprobaciones están completas.'],
            default => ['title' => 'Radar', 'instruction' => '', 'message' => ''],
        };
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

    /**
     * F103: último reporte CRUDO del radar (valor, instante y edad). Es la
     * fuente de evidencia de la calibración; el estado fusionado de la sala se
     * usa solo para el panel.
     *
     * @return array{settings:array<string,mixed>,settings_at:?string,settings_age_seconds:?int,value:?string}
     */
    private function latestPresenceSnapshot(int $roomId): array
    {
        return $this->context()->latestSettings($roomId);
    }

    /**
     * Ventana de medición. El radar solo reporta cambios y el fading del
     * dispositivo puede ser >20 s; 30 s cubre el valor actual (15,5 s) y deja
     * margen para detectar una pérdida. Se limita a 60 s.
     *
     * @param array<string,mixed> $primary
     * @param array<string,mixed> $fallback
     */
    private function radarWindowSeconds(array $primary, array $fallback = []): int
    {
        $settings = $primary !== [] ? $primary : $fallback;
        $fading = isset($settings['fading_time']) && is_numeric($settings['fading_time'])
            ? (float) $settings['fading_time'] : 15.0;
        return (int) max(30, min(60, ceil($fading + 5)));
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

    /**
     * Espera un reporte NUEVO del switch (posterior a la orden). Un estado
     * antiguo que coincide no cuenta como confirmación.
     *
     * @return array{state:string,state_at:?string,fresh:bool}
     */
    private function waitSwitchState(DeviceRepository $repo, int $deviceId, string $expected): array
    {
        $commandTs = microtime(true);
        $lastState = 'UNKNOWN';
        $lastAt = null;
        for ($i = 0; $i < 16; $i++) {
            if ($i > 0) {
                usleep(500_000);
            }
            $device = $repo->findById($deviceId);
            $meta = is_array($device->meta ?? null) ? $device->meta : [];
            $state = strtoupper((string) ($meta['switch_state'] ?? 'UNKNOWN'));
            $at = isset($meta['switch_state_at']) ? (string) $meta['switch_state_at'] : null;
            $lastState = $state;
            $lastAt = $at;
            if ($state !== $expected || $at === null) {
                continue;
            }
            $ts = $this->parseTs($at);
            // Margen de 1 s por la resolución de timestamps del gateway.
            if ($ts !== null && $ts >= $commandTs - 1.0) {
                return ['state' => $state, 'state_at' => $at, 'fresh' => true];
            }
        }
        return ['state' => $lastState, 'state_at' => $lastAt, 'fresh' => false];
    }

    private function parseTs(string $value): ?float
    {
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        $ts = strtotime($v . (str_contains($v, 'T') || str_ends_with($v, 'Z') ? '' : ' UTC'));
        return $ts === false ? null : (float) $ts;
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
