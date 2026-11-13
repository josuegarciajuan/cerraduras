<?php
declare(strict_types=1);

namespace App\Domain\Presence;

use App\Domain\Devices\SwitchService;
use App\Domain\Locks\AccessEvent;
use App\Domain\Locks\AccessEventRepositoryInterface;
use App\Domain\Rooms\Room;
use App\Domain\Rooms\RoomRepositoryInterface;
use App\Domain\Rooms\RoomType;
use App\Domain\Rooms\RoomTypeRepositoryInterface;
use App\Domain\Stays\Stay;
use App\Domain\Stays\StayRepositoryInterface;
use App\Domain\Stays\StayStateMachine;
use App\Domain\Warehouse\WarehouseLightDecision;
use App\Domain\Sensors\RadarDistanceReportRepository;
use App\Domain\Sensors\RadarPresenceReportRepository;
use App\Domain\Workers\WorkerSessionRepositoryInterface;
use App\Infrastructure\Gateways\Lock\LockGatewayFactory;
use App\Domain\Anomalies\AnomalyService;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Errors\NotFoundException;
use App\Support\IsoTime;
use PDOException;

/**
 * IotSessionService: single authoritative writer of the per-room IoT state.
 *
 * Fase 41 (RF-43/44): processEvent() is now
 *   audit (outside tx) → begin tx → lock row → decide → mutate → updateState
 *   → commit → post-commit effects (switch, anomalies, exit rule).
 *
 * No external I/O happens while the row is locked; the exit side-effects run
 * through ExitActionService::executeIfPending() after the commit.
 *
 * See design.md §1.3 and §2.4.
 */
final class IotSessionService
{
    /** Deadlock / lock-wait retry budget (design.md §1.4). */
    private const MAX_TX_RETRIES = 3;

    /**
     * F71 (RF-81): tipo de sala del almacén. En estas salas la presencia del
     * sensor es la única señal de ocupación (no hay stays/QR de huésped), así
     * que F48 no debe descartarla como `no_context`.
     */
    private const WAREHOUSE_TYPE_CODE = 'ALMACEN_BEBIDAS';

    private PresenceEventRepositoryInterface $presenceEvents;

    private IotSessionRepositoryInterface    $iotSessions;
    private RoomRepositoryInterface          $rooms;
    private RoomTypeRepositoryInterface      $roomTypes;
    private StayRepositoryInterface          $stays;
    private StayStateMachine                 $stateMachine;
    private AccessEventRepositoryInterface   $accessEvents;
    private ExitRuleEvaluator                $exitEvaluator;
    private ?SwitchService                   $switchService;
    private ?ExitActionService               $exitActionService;
    private ?AnomalyService                  $anomalyService;
    private ?WorkerSessionRepositoryInterface $workerSessionRepo;
    private ?\App\Domain\Warehouse\WarehouseRecordingServiceInterface $warehouseRecorder;
    private ?RoomSensorStateRepository $sensorStates;
    private ?RadarPresenceReportRepository $presenceReports;
    private ?RadarDistanceReportRepository $distanceReports;

    public function __construct(
        PresenceEventRepositoryInterface $presenceEvents,
        IotSessionRepositoryInterface    $iotSessions,
        RoomRepositoryInterface          $rooms,
        RoomTypeRepositoryInterface      $roomTypes,
        StayRepositoryInterface          $stays,
        StayStateMachine                 $stateMachine,
        AccessEventRepositoryInterface   $accessEvents,
        ExitRuleEvaluator                $exitEvaluator,
        ?SwitchService                   $switchService = null,
        ?ExitActionService               $exitActionService = null,
        ?AnomalyService                  $anomalyService = null,
        ?WorkerSessionRepositoryInterface $workerSessionRepo = null,
        ?\App\Domain\Warehouse\WarehouseRecordingServiceInterface $warehouseRecorder = null,
        ?RoomSensorStateRepository $sensorStates = null,
        ?RadarPresenceReportRepository $presenceReports = null,
        ?RadarDistanceReportRepository $distanceReports = null
    ) {
        $this->presenceEvents = $presenceEvents;
        $this->iotSessions    = $iotSessions;
        $this->rooms          = $rooms;
        $this->roomTypes      = $roomTypes;
        $this->stays          = $stays;
        $this->stateMachine   = $stateMachine;
        $this->accessEvents   = $accessEvents;
        $this->exitEvaluator    = $exitEvaluator;
        $this->switchService    = $switchService;
        $this->exitActionService = $exitActionService;
        $this->anomalyService    = $anomalyService;
        $this->workerSessionRepo = $workerSessionRepo;
        $this->warehouseRecorder = $warehouseRecorder;
        $this->sensorStates = $sensorStates;
        $this->presenceReports = $presenceReports;
        $this->distanceReports = $distanceReports;
    }

    /**
     * Process one normalised sensor event (output of SensorIngress::normalize()).
     *
     * @param array{
     *   room_id: int,
     *   sensor: string,
     *   value: string,
     *   provider: string,
     *   occurred_at: string,
     *   source_event_id: string|null,
     *   meta: array<string,mixed>|null
     * } $event
     *
     * @return array{accepted: bool, derived_state: array<string,mixed>}
     */
    public function processEvent(array $event, string $correlationId): array
    {
        $roomId       = (int)    $event['room_id'];
        $sensor       = (string) $event['sensor'];
        $value        = (string) $event['value'];
        $provider     = (string) $event['provider'];
        $occurredAt   = (string) $event['occurred_at'];
        $sourceEvtId  = $event['source_event_id'] ?? null;
        $meta         = $event['meta'] ?? null;

        // --- 1. Verify room (cheap, before auditing) ---
        $room = $this->rooms->findById($roomId);
        if ($room === null) {
            throw new NotFoundException('Room not found', ['room_id' => $roomId]);
        }

        // --- 2. (A) Raw audit outside the state transaction (RF-44.3) ---
        // F93/RF-154.1: el modo radar se resuelve por sala. En el almacén se
        // hereda el modo del tipo; en una sala normal solo se activa con un
        // override explícito (`rooms.presence_mode_override`), para no alterar
        // el flujo hotelero por defecto.
        $roomType   = $this->roomTypes->findById($room->roomTypeId);
        $radarMode  = $this->resolveRadarMode($room, $roomType);
        $radarDecision = null;

        $raw       = $this->presenceEvents->insertOrGet(
            $roomId, $sensor, $value, $provider, $occurredAt, $sourceEvtId, $meta
        );
        $rawEvent  = $raw['event'];
        $isNewFact = $raw['is_new'];
        // F114+: una fila insertada pero no auditada (transacción abortada,
        // worker reiniciado) es un evento PENDIENTE, no un duplicado. El spool
        // la reintenta y debe procesarse.
        $isPending   = (bool) ($raw['pending'] ?? false);
        $processable = $isNewFact || $isPending;
        $effectiveValue = null;
        $now    = Clock::nowUtc();
        $frameTime = $this->frameTime($occurredAt, $now);
        $evtUtc = $this->isoToMysqlUtc($occurredAt);

        $session = null;
        $applied = false;
        // F86 (RF-115): true cuando un reporte real de puerta con el mismo valor
        // refrescó la frescura (sin cambiar `door_state`). Dispara los mismos
        // efectos post-commit que un APPLY de puerta (luz + motor del almacén).
        $doorRefreshed = false;

        // --- 3. (B/C) Atomic decide + mutate + update ---
        for ($attempt = 1; $attempt <= self::MAX_TX_RETRIES; $attempt++) {
            try {
                $this->iotSessions->beginTransaction();
                $session = $this->iotSessions->lockByRoomId($roomId);

                // F130/F133: admisibilidad de la evidencia ANTES de cualquier
                // decisión de presencia. La DP9 puede llegar en otra trama del
                // MISMO episodio DP1, pero nunca de otro dispositivo, otra
                // configuración ni un episodio anterior.
                $rangeGate = null;
                $rangeConverted = false;
                if ($processable
                    && $provider === PresenceEvent::PROVIDER_ZIGBEE2MQTT
                    && $sensor === PresenceEvent::SENSOR_PRESENCE
                ) {
                    $presenceValue = $this->framePresenceValue($event, $meta);
                    if ($presenceValue === PresenceEvent::VALUE_PRESENT) {
                        $this->syncPresenceEpisode($session, $meta, $evtUtc);
                        $rangeGate = $this->evaluateEpisodeEvidence($room, $session, $meta, $now, $evtUtc);
                    } else {
                        // F133: un ABSENT auténtico cierra el episodio. La
                        // ausencia nunca se bloquea por falta de distancia.
                        $this->clearPresenceEpisode($session);
                        $rangeGate = RadarOperationalRangeDecision::evaluate(
                            $presenceValue,
                            $meta
                        );
                    }

                    if ($rangeGate['status'] === RadarOperationalRangeDecision::STATUS_UNVERIFIED) {
                        // PRESENT sin DP9 usable en este episodio. No acredita
                        // ocupación, pero tampoco se convierte en ausencia: la
                        // señal DP1 sigue viva y el panel la muestra como
                        // "detecta, comprobando alcance".
                        if ($session->presencePendingState === PresenceEvent::VALUE_ABSENT) {
                            $this->clearPending($session);
                        }
                        $radarDecision = [
                            'mode' => $radarMode ?? 'range_gate',
                            'state' => RadarPresenceDecision::STATE_CANDIDATE_PRESENT,
                            'raw' => PresenceEvent::VALUE_PRESENT,
                            'apply' => false,
                            'discard_reason' => RadarOperationalRangeDecision::DISCARD_UNVERIFIED,
                            'stale' => false,
                            'range_gate' => $rangeGate,
                            'episode' => $this->episodePayload($session),
                            'at' => $now->format('Y-m-d\TH:i:s.v\Z'),
                        ];
                        $this->presenceEvents->updateMetaJson(
                            $rawEvent->id,
                            ['radar_decision' => $radarDecision]
                        );
                        // La señal demuestra que el sensor sigue emitiendo: se
                        // refresca la frescura sin tocar el estado operativo.
                        if (!SensorEventDecision::isStale($event, $session)) {
                            $session->lastPresenceEventAt = $evtUtc;
                        }
                        $tagged = $this->tagOutcome($radarDecision, $session, $evtUtc);
                        $session->presenceOutcome = $this->outcomeFromDecision($tagged);
                        $this->iotSessions->updateState($session);
                        $this->presenceEvents->markAudit(
                            $rawEvent->id,
                            false,
                            RadarOperationalRangeDecision::DISCARD_UNVERIFIED
                        );
                        $this->iotSessions->commit();
                        break;
                    }

                    if ($rangeGate['status'] === RadarOperationalRangeDecision::STATUS_OUT_OF_RANGE) {
                        // F130: el eco supera el alcance configurado. Se
                        // conserva el DP9 bruto en la auditoría, pero el valor
                        // efectivo pasa a ABSENT y sigue el flujo normal de
                        // ausencia (guardas de salida, visitas y luz).
                        $rangeConverted = true;
                        $effectiveValue = PresenceEvent::VALUE_ABSENT;
                        $event['value'] = PresenceEvent::VALUE_ABSENT;
                        $value = PresenceEvent::VALUE_ABSENT;
                        $radarDecision = [
                            'mode' => $radarMode ?? 'range_gate',
                            'state' => RadarPresenceDecision::STATE_ABSENT_CONFIRMED,
                            'raw' => PresenceEvent::VALUE_PRESENT,
                            'apply' => true,
                            'discard_reason' => null,
                            'stale' => false,
                            'converted_from' => PresenceEvent::VALUE_PRESENT,
                            'range_gate' => $rangeGate,
                            'episode' => $this->episodePayload($session),
                            'at' => $now->format('Y-m-d\TH:i:s.v\Z'),
                        ];
                        $this->presenceEvents->updateMetaJson(
                            $rawEvent->id,
                            ['radar_decision' => $radarDecision]
                        );
                    }
                }

                // F93/RF-154.1/154.3: la decisión radar se calcula DENTRO de la
                // transacción, con la sesión bloqueada, para que dos eventos
                // concurrentes no construyan su evidencia sobre una fotografía
                // inconsistente. El histórico está acotado por tiempo, no por un
                // número arbitrario de filas.
                $hasVerifiedRange = is_array($rangeGate)
                    && ($rangeGate['status'] ?? '') === RadarOperationalRangeDecision::STATUS_IN_RANGE;
                if ($processable
                    && ($radarMode !== null || $hasVerifiedRange)
                    && !$rangeConverted
                    && $provider === PresenceEvent::PROVIDER_ZIGBEE2MQTT
                    && $sensor === PresenceEvent::SENSOR_PRESENCE
                ) {
                    // F114+: el DP 1 es la transición autoritativa. PRESENT
                    // aplica en el primer frame con ese borde; ABSENT espera
                    // una gracia corta. No se cuentan frames cacheados.
                    $edgeDecision = $radarMode !== null
                        ? $this->computePresenceEdgeDecision($room, $session, $meta, $frameTime)
                        : null;
                    $radarDecision = $edgeDecision;
                    if ($radarDecision === null) {
                        $radarDecision = $radarMode !== null
                            ? $this->computeRadarDecision($room, $roomType, $radarMode, $session, $now)
                            : self::rangeGateDecision(
                                (string) $session->presenceState,
                                $this->episodePayload($session),
                                (array) $rangeGate,
                                $now->format('Y-m-d\TH:i:s.v\Z')
                            );
                    }
                    if ($radarDecision !== null
                        && is_array($rangeGate)
                        && ($rangeGate['status'] ?? '') === RadarOperationalRangeDecision::STATUS_IN_RANGE
                    ) {
                        $radarDecision['range_gate'] = $rangeGate;
                    }
                    if ($edgeDecision !== null) {
                        $edgeValue = (string) ($edgeDecision['value'] ?? '');
                        if (in_array($edgeValue, [PresenceEvent::VALUE_PRESENT, PresenceEvent::VALUE_ABSENT], true)) {
                            $effectiveValue = $edgeValue;
                            // La decisión y la auditoría usan el valor del DP1,
                            // no el campo `presence` cacheado de la trama.
                            $event['value'] = $edgeValue;
                            $value = $edgeValue;
                        }
                    }
                    if ($radarDecision !== null
                        && ($radarDecision['diagnostic'] ?? false) !== true
                    ) {
                        // F132: transición pendiente (entrada/ausencia) antes de
                        // publicar cualquier resultado. Solo aplica a salas con
                        // override radar explícito; almacén y TIMBRADO conservan
                        // su comportamiento.
                        if ($edgeDecision !== null) {
                            $radarDecision = $this->applyPendingPolicy(
                                $room, $session, $radarDecision, $evtUtc, $meta
                            );
                        }
                        $radarDecision = $this->tagOutcome($radarDecision, $session, $evtUtc);
                        $this->presenceEvents->updateMetaJson(
                            $rawEvent->id,
                            ['radar_decision' => $radarDecision]
                        );
                        $decidedValue = (string) ($radarDecision['raw'] ?? '');
                        $applies = ($radarDecision['apply'] ?? false) === true;
                        if (!$applies
                            && in_array($decidedValue, [PresenceEvent::VALUE_PRESENT, PresenceEvent::VALUE_ABSENT], true)
                        ) {
                            // F91/RF-148.9 (PRESENT) + F93/RF-154.2 (ABSENT): un
                            // candidato no altera la ocupación efectiva ni genera
                            // visitas, grabaciones, luces o salidas.
                            // F103/RF-207: sí demuestra que el sensor sigue
                            // emitiendo, así que refresca la frescura (RF-161)
                            // sin tocar presence_state ni disparar efectos.
                            if (($radarDecision['stale'] ?? false) !== true
                                && !SensorEventDecision::isStale($event, $session)
                                && $session->lastPresenceEventAt !== $evtUtc
                            ) {
                                $session->lastPresenceEventAt = $evtUtc;
                            }
                            // F132: el resultado final se persiste también sin
                            // aplicar, para que el panel no confunda una
                            // detección con una decisión operativa.
                            $session->presenceOutcome = $this->outcomeFromDecision($radarDecision);
                            $this->iotSessions->updateState($session);
                            $this->presenceEvents->markAudit(
                                $rawEvent->id,
                                false,
                                (string) ($radarDecision['discard_reason'] ?? 'candidate'),
                                $effectiveValue
                            );
                            $this->iotSessions->commit();
                            break;
                        }
                    }
                }

                // F48 (RF-57): contexto para la credibilidad de presencia. Solo se
                // calcula para PRESENT de presencia (evita consultas en el resto).
                $entryWindowActive = true;
                $insideNoExitCycle = true;
                // F75 (RF-89): en el almacén la señal fiable es `presence` (no `move`).
                $warehousePresence = false;
                // F88 (RF-123.3): evidencia física para corroborar el radar en el almacén.
                $physicalEvidence = true;
                if (($provider === PresenceEvent::PROVIDER_TUYA
                        || $provider === PresenceEvent::PROVIDER_ZIGBEE2MQTT)
                    && $sensor === PresenceEvent::SENSOR_PRESENCE
                    && $value === PresenceEvent::VALUE_PRESENT
                ) {
                    $timbradoPresence = $this->isTimbradoRoom($room);
                    $warehousePresence = $this->isWarehouseRoom($room) || $timbradoPresence;
                    // F132: una política radar explícita ya ha decidido la
                    // admisibilidad. El filtro hotelero de F48 no debe volver a
                    // contradecirla (antes podía confirmar PRESENT y descartarlo
                    // después como `no_context`).
                    if ($radarMode !== null) {
                        $entryWindowActive = true;
                        $insideNoExitCycle = true;
                        $physicalEvidence = true;
                    }
                    if ($timbradoPresence) {
                        // F117: en la oficina el radar es la única autoridad de
                        // presencia; no hay estancia hotelera ni fusión de cámara.
                        $entryWindowActive = true;
                        $insideNoExitCycle = true;
                        $physicalEvidence = true;
                    } elseif ($warehousePresence) {
                        // F76 (RF-91/92): contexto por ciclo de puerta / visita real.
                        [$entryWindowActive, $insideNoExitCycle] =
                            $this->warehousePresenceContext($room, $session, $now);
                        // F88 (RF-123.3): cámara (INTERIOR) o puerta recientes.
                        $physicalEvidence = $this->warehousePhysicalEvidence($room, $session, $now);
                    } elseif ($radarMode === null) {
                        // F48 hotelero: solo cuando la sala no ha activado una
                        // política radar explícita.
                        [$entryWindowActive, $insideNoExitCycle] =
                            $this->presenceContext($room, $session, $now);
                    }
                }

                $decision = SensorEventDecision::decide(
                    $event, $session, !$processable, $entryWindowActive, $insideNoExitCycle,
                    $warehousePresence, $physicalEvidence
                );

                if ($decision !== SensorEventDecision::APPLY) {
                    if ($rangeConverted) {
                        // El eco exterior ya dejó la sesión en ABSENT (o ya lo
                        // estaba). Se audita el motivo real y se refresca la
                        // frescura sin repetir efectos.
                        if ($decision !== SensorEventDecision::STALE
                            && !SensorEventDecision::isStale($event, $session)
                            && $session->lastPresenceEventAt !== $evtUtc
                        ) {
                            $session->lastPresenceEventAt = $evtUtc;
                            $this->iotSessions->updateState($session);
                        }
                        $this->presenceEvents->markAudit(
                            $rawEvent->id,
                            false,
                            RadarOperationalRangeDecision::DISCARD_OUT_OF_RANGE,
                            $effectiveValue
                        );
                        $this->iotSessions->commit();
                        break;
                    }
                    // F86 (RF-115): REFRESH de puerta: mismo valor, instante nuevo
                    // (reporte real edge). Actualiza la frescura sin tocar el estado.
                    if ($decision === SensorEventDecision::REFRESH
                        && $sensor === PresenceEvent::SENSOR_PROXIMITY
                    ) {
                        $session->lastDoorEventAt = $evtUtc;
                        if ($value === PresenceEvent::VALUE_OPEN) {
                            $session->lastOpenAt = $evtUtc;
                        } elseif ($value === PresenceEvent::VALUE_CLOSED) {
                            $session->lastCloseAt = $evtUtc;
                        }
                        $this->iotSessions->updateState($session);
                        $this->recordPhysicalSensorState($rawEvent, $sensor, $value);
                        $doorRefreshed = true;
                    }
                    // F94/RF-161: un reporte de presencia (aunque sea noop, duplicate,
                    // no_contest o uncorroborated) demuestra que el sensor sigue
                    // emitiendo. La frescura se mide desde el último REPORTE, no desde
                    // la última transición aplicada: si no, un radar que repite
                    // `PRESENT` aparecía como "sin datos" a los 3 minutos.
                    // Un evento stale no refresca (llegó tarde).
                    if ($sensor === PresenceEvent::SENSOR_PRESENCE
                        && $decision !== SensorEventDecision::STALE
                        && $session->lastPresenceEventAt !== $evtUtc
                    ) {
                        $session->lastPresenceEventAt = $evtUtc;
                        $this->iotSessions->updateState($session);
                    }
                    // F132: un rechazo posterior (política de la sala,
                    // deduplicación, atraso) también es un resultado final. Se
                    // registra para que el panel no publique la decisión
                    // intermedia como si se hubiera aplicado.
                    if ($radarDecision !== null
                        && $sensor === PresenceEvent::SENSOR_PRESENCE
                    ) {
                        $radarDecision['discard_reason'] = $decision;
                        $radarDecision['apply'] = false;
                        $radarDecision['confirmed'] = false;
                        // Recalcular el resultado con el motivo FINAL: el
                        // intermedio pudo haber sido `applied`.
                        unset($radarDecision['outcome']);
                        $radarDecision = $this->tagOutcome($radarDecision, $session, $evtUtc);
                        $this->presenceEvents->updateMetaJson(
                            $rawEvent->id,
                            ['radar_decision' => $radarDecision]
                        );
                        $session->presenceOutcome = $this->outcomeFromDecision($radarDecision);
                        $this->iotSessions->updateState($session);
                    }
                    $this->presenceEvents->markAudit($rawEvent->id, false, $decision);
                    $this->iotSessions->commit();
                    break;
                }

                $activeStay = $this->stays->lockActiveForRoom($roomId);
                $session->stayId = $activeStay !== null ? $activeStay->id : null;

                $this->mutate($room, $session, $event, $evtUtc, $activeStay, $correlationId, $now);

                // F132: la presencia aplicada cancela cualquier transición
                // pendiente y publica el resultado operativo final.
                if ($radarDecision !== null
                    && $sensor === PresenceEvent::SENSOR_PRESENCE
                    && in_array($value, [PresenceEvent::VALUE_PRESENT, PresenceEvent::VALUE_ABSENT], true)
                ) {
                    $this->clearPending($session);
                    $radarDecision['apply'] = true;
                    $radarDecision['confirmed'] = true;
                    $radarDecision['discard_reason'] = null;
                    if (($radarDecision['raw'] ?? '') === '') {
                        $radarDecision['raw'] = $value;
                    }
                    if ($rangeConverted) {
                        // El eco exterior ya produjo una ausencia efectiva: el
                        // episodio de presencia termina aquí.
                        $this->clearPresenceEpisode($session);
                        $radarDecision['value'] = PresenceEvent::VALUE_ABSENT;
                    } elseif ($value === PresenceEvent::VALUE_PRESENT) {
                        // F134: la evidencia DP9 se marca como usada cuando la
                        // presencia se aplica, no antes. Así un descarte por
                        // política no invalida las siguientes tramas del mismo
                        // episodio.
                        $gate = is_array($radarDecision['range_gate'] ?? null)
                            ? $radarDecision['range_gate'] : [];
                        $gateMarker = $gate['distance_marker'] ?? null;
                        if (is_string($gateMarker) && $gateMarker !== '') {
                            $session->presenceEpisodeDistanceMarker = $gateMarker;
                        }
                    }
                    $radarDecision['episode'] = $this->episodePayload($session);
                    $radarDecision = $this->tagOutcome($radarDecision, $session, $evtUtc);
                    $this->presenceEvents->updateMetaJson(
                        $rawEvent->id,
                        ['radar_decision' => $radarDecision]
                    );
                    $session->presenceOutcome = $this->outcomeFromDecision($radarDecision);
                }

                $this->iotSessions->updateState($session);
                $this->recordPhysicalSensorState($rawEvent, $sensor, $value);
                $this->presenceEvents->markAudit($rawEvent->id, true, null, $effectiveValue);
                $this->iotSessions->commit();
                $applied = true;
                break;
            } catch (PDOException $e) {
                $this->safeRollback();
                if (!$this->isRetryableDeadlock($e) || $attempt >= self::MAX_TX_RETRIES) {
                    throw $e;
                }
                // Backoff + jitter (20 ms * attempt, capped).
                usleep(20_000 * $attempt + random_int(0, 10_000));
            } catch (\Throwable $e) {
                // Never leave the transaction open on an unexpected failure.
                $this->safeRollback();
                throw $e;
            }
        }

        // --- 4. (D) Post-commit effects (never under the row lock) ---
        if (($applied || $doorRefreshed) && $session !== null) {
            if ($sensor === PresenceEvent::SENSOR_PROXIMITY
                && $value === PresenceEvent::VALUE_OPEN
                && $this->switchService !== null
                && !$this->isWarehouseRoom($room)
                && !$this->isTimbradoRoom($room)
            ) {
                try {
                    $this->switchService->turnOn($roomId);
                } catch (\Throwable $e) {
                    error_log('[IotSessionService] Switch turnOn on door open failed (best-effort): ' . $e->getMessage());
                }
            }

            // F114+: el almacén no tiene stays, así que ExitActionService nunca
            // apaga su luz. La luz sigue a la sesión: ON con PRESENT/OPEN y OFF
            // con ABSENT/CLOSED sin presencia.
            if ($this->isWarehouseRoom($room)
                && $this->switchService !== null
                && Config::getBool('WAREHOUSE_LIGHT_AUTOMATION', true)
            ) {
                $this->applyWarehouseLight($roomId, $sensor, $value, $applied, $session);
            }

            // F71 (RF-81.4): el almacén no tiene estancia de huésped; detectar
            // anomalías como A2 (presencia sin stay) generaría ruido constante.
            if ($this->anomalyService !== null
                && !$this->isWarehouseRoom($room)
                && !$this->isTimbradoRoom($room)
            ) {
                try {
                    $triggerEvent = new PresenceEvent(
                        0, $roomId, $sensor, $value, $provider,
                        $occurredAt, $now->format('Y-m-d H:i:s.v'),
                        $sourceEvtId, $meta
                    );
                    $this->anomalyService->detectAndPersist(
                        $session, $triggerEvent, $this->stays->findActiveForRoom($roomId)
                    );
                } catch (\Throwable $e) {
                    error_log('[IotSessionService] Anomaly detection failed (best-effort): ' . $e->getMessage());
                }
            }

            if ($this->exitActionService !== null) {
                try {
                    $this->exitActionService->executeIfPending($roomId, $correlationId);
                } catch (\Throwable $e) {
                    error_log('[IotSessionService] Exit evaluation failed (best-effort): ' . $e->getMessage());
                }
            }

            // F63/RF-71: warehouse recording engine (best-effort, post-commit).
            if ($this->warehouseRecorder !== null) {
                $warehouseEvent = self::warehouseEventFor($sensor, $value);
                // F81 (RF-104.3/104.4): al cerrar la puerta, si la sesión IoT ya
                // mutada NO tiene presencia interior (ABSENT), la visita del
                // almacén debe terminar. Con PRESENT o UNKNOWN se mantiene
                // EV_DOOR_CLOSE (conservador: no cerrar sin certeza de interior).
                if ($warehouseEvent === \App\Domain\Warehouse\WarehouseRecordingDecision::EV_DOOR_CLOSE
                    && $session !== null
                    && $session->presenceState === IotSession::PRESENCE_ABSENT
                ) {
                    $warehouseEvent = \App\Domain\Warehouse\WarehouseRecordingDecision::EV_DOOR_CLOSE_ABSENT;
                }
                if ($warehouseEvent !== null) {
                    try {
                        // F79 (RF-102.1/102.2): se entrega el provider y el origen
                        // de la señal para que el motor del almacén no cree visitas
                        // desde eventos simulados ni desde el resync REST.
                        $this->warehouseRecorder->onSignal($roomId, $warehouseEvent, [
                            'provider' => $provider,
                            'source'   => is_array($meta) ? (string) ($meta['source'] ?? 'real') : 'real',
                            'occurred_at' => $occurredAt,
                        ]);
                    } catch (\Throwable $e) {
                        error_log('[IotSessionService] Warehouse recording failed (best-effort): ' . $e->getMessage());
                    }
                }
            }
        }

        if ($session === null) {
            $session = $this->iotSessions->findByRoomId($roomId);
        }
        if ($session === null) {
            $session = new IotSession(
                0, $roomId, null,
                IotSession::DOOR_UNKNOWN,
                IotSession::PRESENCE_UNKNOWN,
                null, null, null, null, ''
            );
        }

        return ['accepted' => true, 'derived_state' => $session->toArray()];
    }

    /**
     * Return the current derived state (latest iot_session) and recent events.
     * Throws NotFoundException if the room does not exist.
     *
     * @return array<string,mixed>
     */
    public function getPresenceState(int $roomId, int $eventLimit = 20): array
    {
        if ($this->rooms->findById($roomId) === null) {
            throw new NotFoundException('Room not found', ['room_id' => $roomId]);
        }
        $session = $this->iotSessions->findByRoomId($roomId);
        $events  = $this->presenceEvents->listForRoom($roomId, $eventLimit);

        return [
            'room_id'       => $roomId,
            'derived_state' => $session !== null ? $session->toArray() : [
                'door_state'        => IotSession::DOOR_UNKNOWN,
                'presence_state'    => IotSession::PRESENCE_UNKNOWN,
                'last_open_at'      => null,
                'last_absent_since' => null,
            ],
            'recent_events' => array_map(static function (PresenceEvent $e): array {
                return [
                    'id'              => $e->id,
                    'sensor'          => $e->sensor,
                    'value'           => $e->value,
                    'provider'        => $e->provider,
                    'occurred_at'     => $e->occurredAt,
                    'source_event_id' => $e->sourceEventId,
                ];
            }, $events),
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers

    /**
     * F97.3: conserva la última posición física aplicada del contacto fuera de
     * `iot_sessions`. Un reset de ciclo no debe apagar el imán ni borrar la
     * evidencia del sensor.
     */
    private function recordPhysicalSensorState(PresenceEvent $rawEvent, string $sensor, string $value): void
    {
        if ($this->sensorStates === null || $sensor !== PresenceEvent::SENSOR_PROXIMITY) {
            return;
        }
        $meta = is_array($rawEvent->meta) ? $rawEvent->meta : [];
        $externalId = isset($meta['device_external_id']) && is_string($meta['device_external_id'])
            ? $meta['device_external_id']
            : null;
        $this->sensorStates->record(
            $rawEvent->roomId,
            $sensor,
            $rawEvent->provider,
            $value,
            $rawEvent->id,
            $rawEvent->occurredAt,
            $rawEvent->receivedAt,
            $externalId
        );
    }

    /**
     * F114+: luz del almacén ligada a la sesión, no a una estancia hotelera.
     * Best-effort y con guarda de estado para no repetir comandos.
     */
    private function applyWarehouseLight(
        int $roomId,
        string $sensor,
        string $value,
        bool $applied,
        IotSession $session
    ): void {
        if ($this->switchService === null) {
            return;
        }
        try {
            $current = null;
            $device = $this->switchService->getSwitchForRoom($roomId);
            if ($device !== null && is_array($device->meta)) {
                $state = strtoupper(trim((string) ($device->meta['switch_state'] ?? '')));
                if (in_array($state, ['ON', 'OFF'], true)) {
                    $current = $state;
                }
            }
            $target = WarehouseLightDecision::decide(
                $sensor,
                $value,
                $applied,
                $session->presenceState,
                $current
            );
            if ($target === WarehouseLightDecision::ON) {
                $this->switchService->turnOn($roomId);
            }
            // OFF no se ejecuta aquí: WarehouseRecordingService programa
            // `warehouse_state.light_off_at` y exit-scan apaga tras el retardo
            // cancelable. Así el estado/croquis es inmediato y la luz no parpadea.
        } catch (\Throwable $e) {
            error_log('[IotSessionService] Warehouse light automation failed (best-effort): ' . $e->getMessage());
        }
    }

    /**
     * Map a sensor event to a warehouse recording signal (F63/RF-71).
     */
    private static function warehouseEventFor(string $sensor, string $value): ?string
    {
        if ($sensor === PresenceEvent::SENSOR_PROXIMITY) {
            if ($value === PresenceEvent::VALUE_OPEN) {
                return \App\Domain\Warehouse\WarehouseRecordingDecision::EV_DOOR_OPEN;
            }
            if ($value === PresenceEvent::VALUE_CLOSED) {
                return \App\Domain\Warehouse\WarehouseRecordingDecision::EV_DOOR_CLOSE;
            }
        }
        if ($sensor === PresenceEvent::SENSOR_PRESENCE) {
            if ($value === PresenceEvent::VALUE_PRESENT) {
                return \App\Domain\Warehouse\WarehouseRecordingDecision::EV_PRESENT;
            }
            if ($value === PresenceEvent::VALUE_ABSENT) {
                return \App\Domain\Warehouse\WarehouseRecordingDecision::EV_ABSENT;
            }
        }
        return null;
    }

    /**
     * Pure mutation of the locked session snapshot for an APPLY decision.
     *
     * @param array<string,mixed> $event
     */
    /**
     * F91/RF-145.7: apply a retained/bootstrap door state without creating a
     * transition, visit, light command or anomaly. It only seeds the last known
     * door state so mixed mode can decide while the door is already open.
     *
     * @param array<string,mixed> $event Normalized event (room_id, value, occurred_at...).
     * @return array{accepted:bool,derived_state:array<string,mixed>,reason:string}
     */
    public function applyDoorBootstrap(array $event, string $correlationId): array
    {
        $roomId = (int) ($event['room_id'] ?? 0);
        $value = (string) ($event['value'] ?? '');
        $occurredAt = (string) ($event['occurred_at'] ?? '');
        $sourceEvtId = isset($event['source_event_id']) ? (string) $event['source_event_id'] : null;
        $meta = is_array($event['meta'] ?? null) ? $event['meta'] : [];
        $meta['bootstrap'] = true;

        if ($roomId <= 0 || !in_array($value, [PresenceEvent::VALUE_OPEN, PresenceEvent::VALUE_CLOSED], true)) {
            return ['accepted' => false, 'derived_state' => [], 'reason' => 'invalid_bootstrap'];
        }
        $room = $this->rooms->findById($roomId);
        if ($room === null) {
            return ['accepted' => false, 'derived_state' => [], 'reason' => 'room_not_found'];
        }

        $raw = $this->presenceEvents->insertOrGet(
            $roomId,
            PresenceEvent::SENSOR_PROXIMITY,
            $value,
            PresenceEvent::PROVIDER_ZIGBEE2MQTT,
            $occurredAt,
            $sourceEvtId,
            $meta
        );
        $this->presenceEvents->markAudit($raw['event']->id, false, 'bootstrap');

        try {
            $this->iotSessions->beginTransaction();
            $session = $this->iotSessions->lockByRoomId($roomId);
            $session->doorState = $value;
            $this->iotSessions->updateState($session);
            $this->iotSessions->commit();
        } catch (\Throwable $e) {
            $this->safeRollback();
            throw $e;
        }

        return ['accepted' => false, 'derived_state' => ['door_state' => $value], 'reason' => 'bootstrap'];
    }

    private function mutate(
        Room     $room,
        IotSession $session,
        array    $event,
        string   $evtUtc,
        ?Stay    $activeStay,
        string   $correlationId,
        \DateTimeImmutable $now
    ): void {
        $sensor = (string) $event['sensor'];
        $value  = (string) $event['value'];

        if ($sensor === PresenceEvent::SENSOR_PROXIMITY) {
            $session->lastDoorEventAt = $evtUtc;
            $session->lastDoorValue   = $value;

            if ($value === PresenceEvent::VALUE_OPEN) {
                $session->doorState  = IotSession::DOOR_OPEN;
                $session->lastOpenAt = $evtUtc;
            } elseif ($value === PresenceEvent::VALUE_CLOSED) {
                $session->doorState   = IotSession::DOOR_CLOSED;
                $session->lastCloseAt = $evtUtc;

                // RF-30: start the absence timer when the door closes with no presence.
                if ($session->presenceState === IotSession::PRESENCE_ABSENT
                    && $session->lastAbsentSince === null
                ) {
                    $session->lastAbsentSince = $evtUtc;
                }

                // F41 (RF-46.2): consolidate entry when the door closes with
                // presence confirmed or seen during the opening.
                $this->consolidateEntry($session, $activeStay, $evtUtc);

                // F38 W14 Escenario B: worker exit by door event.
                $this->handleWorkerExitOnDoorClose($room, $session, $evtUtc, $correlationId, $now);
            }
        } elseif ($sensor === PresenceEvent::SENSOR_PRESENCE) {
            $session->lastPresenceEventAt = $evtUtc;
            $session->lastPresenceValue   = $value;

            if ($value === PresenceEvent::VALUE_PRESENT) {
                $session->presenceState   = IotSession::PRESENCE_PRESENT;
                $session->lastAbsentSince = null; // cancels the exit countdown (RF-47.3)

                // F42 (RF-46.4): el radar puede confirmar presencia DESPUÉS del
                // cierre. Con la puerta cerrada y dentro de la ventana de entrada
                // (`entry_window_seconds`), consolidamos la entrada. Nunca con la
                // puerta abierta: así el avatar espera en el umbral hasta que
                // cierre (RF-46.1.3).
                if ($session->doorState === IotSession::DOOR_CLOSED) {
                    $this->consolidateEntry($session, $activeStay, $evtUtc, $room, true);
                }
            } elseif ($value === PresenceEvent::VALUE_ABSENT) {
                if ($session->presenceState !== IotSession::PRESENCE_ABSENT) {
                    $session->lastAbsentSince = $evtUtc;
                }
                $session->presenceState = IotSession::PRESENCE_ABSENT;
            }
        }
    }

    /**
     * F41/F42: set stays.entry_confirmed_at when a CLOSE follows an opening during
     * which presence was seen (contracts.md §1.2 / design.md §2.4).
     *
     * @param bool $requireRecentClose F42 (RF-46.4): cuando la consolidación la
     *        dispara una presencia TARDÍA (puerta ya cerrada), exige que el cierre
     *        sea reciente (<= `entry_window_seconds` de la habitación). Fuera de la
     *        ventana, la entrada no se consolida: se requiere una nueva apertura
     *        acreditada.
     */
    private function consolidateEntry(
        IotSession $session,
        ?Stay      $activeStay,
        string     $evtUtc,
        ?Room      $room = null,
        bool       $requireRecentClose = false
    ): void {
        if ($activeStay === null || $activeStay->entryConfirmedAt !== null) {
            return;
        }

        $seenDuringOpen = $session->presenceState === IotSession::PRESENCE_PRESENT;
        if (!$seenDuringOpen
            && $session->lastPresenceValue === PresenceEvent::VALUE_PRESENT
            && $session->lastPresenceEventAt !== null
            && $session->lastOpenAt !== null
        ) {
            $presenceTs = strtotime($session->lastPresenceEventAt . ' UTC');
            $openTs     = strtotime($session->lastOpenAt . ' UTC');
            $seenDuringOpen = $presenceTs !== false && $openTs !== false && $presenceTs >= $openTs;
        }

        if (!$seenDuringOpen) {
            return;
        }

        if ($requireRecentClose) {
            if ($session->doorState !== IotSession::DOOR_CLOSED || $session->lastCloseAt === null) {
                return;
            }
            $closeTs = strtotime($session->lastCloseAt . ' UTC');
            $evtTs   = strtotime($evtUtc . ' UTC');
            // Bug 1: la ventana de ENTRADA es `entry_window_seconds`
            // (room_types.presence_entry_window_seconds, default 90 s), NO la
            // guarda de salida. Así bajar la guarda a 3 s no regresa F42.
            $entryWindow = $room !== null ? $this->resolveEntryWindowSeconds($room) : 90;
            if ($closeTs === false || $evtTs === false || ($evtTs - $closeTs) > $entryWindow) {
                return;
            }
        }

        $activeStay->entryConfirmedAt = $evtUtc;
        $this->stays->update($activeStay->id, ['entry_confirmed_at' => $evtUtc]);
    }

    /**
     * F38 W14 Escenario B: close the most recent worker session when the door
     * closes while presence is still detected (guest stayed inside, worker left).
     */
    private function handleWorkerExitOnDoorClose(
        Room     $room,
        IotSession $session,
        string   $evtUtc,
        string   $correlationId,
        \DateTimeImmutable $now
    ): void {
        if ($this->workerSessionRepo === null
            || $session->presenceState !== IotSession::PRESENCE_PRESENT
        ) {
            return;
        }

        $activeWorkerSessions = $this->workerSessionRepo->findActiveForRoom($room->id);
        if (empty($activeWorkerSessions)) {
            return;
        }

        $latest = $activeWorkerSessions[count($activeWorkerSessions) - 1];
        $this->workerSessionRepo->close($latest->id, 'DOOR_EVENT', $evtUtc);
        try {
            $gw = LockGatewayFactory::make($room);
            $this->accessEvents->insert(
                $room->id, null,
                AccessEvent::KIND_WORKER_EXIT,
                AccessEvent::RESULT_OK,
                null,
                $gw->getProvider(),
                $correlationId,
                ['exit_kind' => 'DOOR_EVENT', 'worker_id' => $latest->workerId],
                $latest->id
            );
        } catch (\Throwable $e) {
            error_log('[IotSessionService] Failed to write WORKER_EXIT (door event): ' . $e->getMessage());
        }
    }

    /**
     * F93/RF-154.1: modo radar efectivo de la sala.
     *
     * - Almacén: hereda `room_types.presence_mode` (F91).
     * - Sala normal: solo con `rooms.presence_mode_override` explícito; sin él se
     *   mantiene el flujo de estancia/F48 y no se toca el comportamiento hotelero.
     */
    private function resolveRadarMode(Room $room, ?RoomType $roomType): ?string
    {
        $mode = $room->presenceModeOverride;
        if ($mode === null || $mode === '') {
            if (!$this->isWarehouseRoom($room) && !$this->isTimbradoRoom($room)) {
                return null;
            }
            $mode = $roomType?->presenceMode;
        }
        if ($roomType === null || $mode === null
            || !in_array($mode, RadarPresenceDecision::modes(), true)
        ) {
            return null;
        }
        return (string) $mode;
    }

    /**
     * F114+: borde DP1 del radar (PRESENT inmediato, ABSENT con gracia).
     *
     * Solo se usa para el radar Zigbee de una sala de almacén. El marcador del
     * frame debe coincidir con el último reporte persistido: evita aplicar una
     * decisión de un frame anterior o de otro dispositivo.
     *
     * @param array<string,mixed>|null $meta
     * @return array<string,mixed>|null
     */
    private function computePresenceEdgeDecision(
        Room $room,
        IotSession $session,
        ?array $meta,
        \DateTimeImmutable $frameTime
    ): ?array {
        $explicitRadar = $room->presenceModeOverride !== null && $room->presenceModeOverride !== '';
        if ($this->presenceReports === null
            || (!$this->isWarehouseRoom($room) && !$this->isTimbradoRoom($room) && !$explicitRadar)
        ) {
            return null;
        }
        $report = is_array($meta) && is_array($meta['presence_report'] ?? null)
            ? $meta['presence_report']
            : null;
        if ($report === null) {
            return null;
        }
        $frameMarker = (string) ($report['marker'] ?? '');
        if ($frameMarker === '') {
            return null;
        }
        $latest = $this->presenceReports->latestForRoom($room->id);
        if ($latest === null || (string) ($latest['marker'] ?? '') !== $frameMarker) {
            return null;
        }

        // F132: la gracia de ausencia depende de la política de la sala. Las
        // salas normales con override usan una espera sostenida; almacén y
        // TIMBRADO conservan la gracia configurada (por defecto 0).
        $graceMs = $this->transitionHolds($room)['absent_ms'];
        $presentMaxAge = (int) (Config::getInt('RADAR_PRESENT_EDGE_MAX_AGE_SECONDS', 120) ?? 120);
        $presentMaxAgeMs = max(1000, min(600000, $presentMaxAge * 1000));

        return RadarPresenceEdgeDecision::evaluate(
            $latest,
            $frameTime,
            $graceMs,
            $presentMaxAgeMs,
            $session->presenceState
        );
    }

    /**
     * F130: valor DP1 de la propia trama cuando viene marcado. Sin marcador se
     * usa el valor canónico mapeado, pero la distancia solo se acepta si el
     * frame trae su propio DP9 correlacionado.
     *
     * @param array<string,mixed> $event
     * @param array<string,mixed>|null $meta
     */
    private function framePresenceValue(array $event, ?array $meta): string
    {
        $report = is_array($meta) && is_array($meta['presence_report'] ?? null)
            ? $meta['presence_report'] : null;
        $value = $report !== null
            ? strtoupper(trim((string) ($report['value'] ?? '')))
            : '';
        if (in_array($value, [PresenceEvent::VALUE_PRESENT, PresenceEvent::VALUE_ABSENT], true)) {
            return $value;
        }
        return strtoupper(trim((string) ($event['value'] ?? '')));
    }

    /**
     * Instante del frame en UTC. El mapper entrega ISO con ms; si llega algo
     * inesperado se usa el reloj del proceso como fallback.
     */
    private function frameTime(string $occurredAt, \DateTimeImmutable $fallback): \DateTimeImmutable
    {
        try {
            $dt = new \DateTimeImmutable($occurredAt, new \DateTimeZone('UTC'));
            return $dt->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /**
     * F133: identifica el episodio DP1 abierto. Un marcador nuevo abre un
     * episodio nuevo y descarta la DP9 usada por el anterior.
     *
     * @param array<string,mixed>|null $meta
     */
    private function syncPresenceEpisode(IotSession $session, ?array $meta, string $evtUtc): void
    {
        $report = is_array($meta['presence_report'] ?? null) ? $meta['presence_report'] : null;
        $marker = $report !== null ? (string) ($report['marker'] ?? '') : '';
        $sessionId = $report !== null ? (string) ($report['session_id'] ?? '') : '';

        // Un episodio es una racha continua de PRESENT. El conversor puede
        // emitir un marcador nuevo en cada publicación, así que el marcador NO
        // identifica por sí solo un episodio nuevo. Solo un ABSENT lo cierra.
        if ($session->presenceEpisodeStartedAt !== null) {
            if ($marker !== '') {
                $session->presenceEpisodeMarker = $marker;
            }
            if ($sessionId !== '') {
                $session->presenceEpisodeSession = $sessionId;
            }
            return;
        }

        $session->presenceEpisodeMarker = $marker !== '' ? $marker : null;
        $session->presenceEpisodeSession = $sessionId !== '' ? $sessionId : null;
        $session->presenceEpisodeStartedAt = $evtUtc;
        $session->presenceEpisodeDistanceMarker = null;
    }

    private function clearPresenceEpisode(IotSession $session): void
    {
        $session->presenceEpisodeMarker = null;
        $session->presenceEpisodeSession = null;
        $session->presenceEpisodeStartedAt = null;
        $session->presenceEpisodeDistanceMarker = null;
    }

    /** @return array{marker:?string,session:?string,started_at:?string,distance_marker:?string}|null */
    private function episodePayload(IotSession $session): ?array
    {
        if ($session->presenceEpisodeMarker === null && $session->presenceEpisodeStartedAt === null) {
            return null;
        }
        return [
            'marker' => $session->presenceEpisodeMarker,
            'session' => $session->presenceEpisodeSession,
            'started_at' => $session->presenceEpisodeStartedAt,
            'distance_marker' => $session->presenceEpisodeDistanceMarker,
        ];
    }

    /**
     * F133: evalúa si una DP9 (de esta trama o de otra del mismo episodio)
     * acredita el PRESENT vigente.
     *
     * @param array<string,mixed>|null $meta
     * @return array<string,mixed> gate compatible con RadarOperationalRangeDecision
     */
    private function evaluateEpisodeEvidence(
        Room $room,
        IotSession $session,
        ?array $meta,
        \DateTimeImmutable $now,
        string $evtUtc
    ): array {
        $settings = is_array($meta['settings'] ?? null) ? $meta['settings'] : [];
        $frameReport = is_array($meta['distance_report'] ?? null) ? $meta['distance_report'] : null;
        if ($frameReport !== null && (!isset($frameReport['received_at']) || $frameReport['received_at'] === '')) {
            $frameReport['received_at'] = $evtUtc;
        }
        $frameMarker = (string) ($frameReport['marker'] ?? '');

        // Una presencia YA efectiva solo puede cambiar con una DP9 nueva y
        // fuera de alcance. Una trama sin DP9, una DP9 repetida o una evidencia
        // no utilizable no la degradan: el episodio es continuo.
        if ($session->presenceState === IotSession::PRESENCE_PRESENT) {
            if ($settings === [] || $frameReport === null
                || $frameMarker === '' || $frameMarker === $session->presenceEpisodeDistanceMarker
            ) {
                return $this->rangeGate(
                    RadarOperationalRangeDecision::STATUS_IN_RANGE,
                    'presence_already_effective',
                    true,
                    false,
                    null,
                    null,
                    null,
                    $session->presenceEpisodeDistanceMarker,
                    'already_effective'
                );
            }
            $persisted = $this->distanceReports?->findByMarker($session->roomId, $frameMarker);
            $nowMs = (int) $now->format('Uv');
            $correlation = RadarEvidenceCorrelation::evaluate(
                PresenceEvent::VALUE_PRESENT,
                $session->presenceEpisodeMarker,
                $session->presenceEpisodeSession,
                $this->mysqlToMs($session->presenceEpisodeStartedAt),
                $session->presenceEpisodeDistanceMarker,
                $frameReport,
                $persisted,
                $settings,
                $nowMs,
                max(1, (int) (Config::getInt('RADAR_EPISODE_EVIDENCE_WINDOW_SECONDS', 120) ?? 120)) * 1000,
                max(1, (int) (Config::getInt('RADAR_EPISODE_EVIDENCE_MAX_AGE_SECONDS', 120) ?? 120)) * 1000
            );
            if ($correlation['status'] === RadarEvidenceCorrelation::STATUS_OUT_OF_RANGE) {
                return $this->rangeGate(
                    RadarOperationalRangeDecision::STATUS_OUT_OF_RANGE,
                    RadarOperationalRangeDecision::REASON_OUT_OF_RANGE,
                    false,
                    true,
                    RadarOperationalRangeDecision::DISCARD_OUT_OF_RANGE,
                    $correlation['distance_m'],
                    $correlation['maximum_range_m'],
                    $correlation['marker'],
                    'episode'
                );
            }
            if ($correlation['status'] === RadarEvidenceCorrelation::STATUS_USABLE) {
                $session->presenceEpisodeDistanceMarker = $correlation['marker'];
            }
            return $this->rangeGate(
                RadarOperationalRangeDecision::STATUS_IN_RANGE,
                'presence_already_effective',
                true,
                false,
                null,
                $correlation['distance_m'],
                $correlation['maximum_range_m'],
                $correlation['marker'],
                'already_effective'
            );
        }

        // Compatibilidad: un dispositivo Zigbee de presencia sin configuración
        // de radar conserva su comportamiento anterior (no hay alcance que
        // aplicar).
        if ($settings === [] && $frameReport === null) {
            return $this->rangeGate(
                RadarOperationalRangeDecision::STATUS_NOT_APPLICABLE,
                RadarOperationalRangeDecision::REASON_NOT_RANGE_CAPABLE,
                true,
                false,
                null,
                null,
                null,
                null,
                'not_range_capable'
            );
        }

        $persisted = null;
        $latest = null;
        if ($this->distanceReports !== null) {
            if ($frameMarker !== '') {
                $persisted = $this->distanceReports->findByMarker($session->roomId, $frameMarker);
            }
            // F133/F134: una DP9 con distancia 0 no debe ser la única
            // evidencia del episodio. Si la trama actual o la fila persistida
            // no aportan una distancia positiva, se busca la última DP9
            // positiva del mismo episodio antes de declarar el alcance como
            // no verificado.
            $frameDistance = self::reportDistance($frameReport);
            $persistedDistance = self::reportDistance($persisted);
            if ($frameDistance === null || $frameDistance <= 0.0
                || ($persistedDistance !== null && $persistedDistance <= 0.0)
            ) {
                $sinceMs = $this->mysqlToMs($session->presenceEpisodeStartedAt);
                if ($sinceMs === null) {
                    $sinceMs = (int) $now->format('Uv') - 120000;
                }
                $since = gmdate('Y-m-d H:i:s', intdiv($sinceMs, 1000) - 2);
                $latest = $this->distanceReports->latestPositiveForRoom(
                    $session->roomId,
                    $since,
                    \App\Domain\Sensors\RadarDistanceReport::configKey($settings)
                );
            }
        }

        $window = (int) (Config::getInt('RADAR_EPISODE_EVIDENCE_WINDOW_SECONDS', 120) ?? 120);
        $maxAge = (int) (Config::getInt('RADAR_EPISODE_EVIDENCE_MAX_AGE_SECONDS', 120) ?? 120);
        $evidenceReport = self::selectEpisodeReport($frameReport, $persisted, $latest);
        $correlation = RadarEvidenceCorrelation::evaluate(
            PresenceEvent::VALUE_PRESENT,
            $session->presenceEpisodeMarker,
            $session->presenceEpisodeSession,
            $this->mysqlToMs($session->presenceEpisodeStartedAt),
            $session->presenceEpisodeDistanceMarker,
            $frameReport,
            $evidenceReport,
            $settings,
            (int) $now->format('Uv'),
            max(1, $window) * 1000,
            max(1, $maxAge) * 1000
        );

        if ($correlation['status'] === RadarEvidenceCorrelation::STATUS_USABLE) {
            // El marcador se consume SOLO cuando la presencia se aplica (ver
            // bloque de mutate). Si la política de la sala descarta el
            // PRESENT después, la siguiente DP9 positiva sigue siendo válida
            // y el panel no queda atrapado en "comprobando alcance".
            return $this->rangeGate(
                RadarOperationalRangeDecision::STATUS_IN_RANGE,
                RadarOperationalRangeDecision::REASON_WITHIN_RANGE,
                true,
                false,
                null,
                $correlation['distance_m'],
                $correlation['maximum_range_m'],
                $correlation['marker'],
                $persisted !== null ? 'persisted_episode' : 'episode'
            );
        }
        if ($correlation['status'] === RadarEvidenceCorrelation::STATUS_OUT_OF_RANGE) {
            return $this->rangeGate(
                RadarOperationalRangeDecision::STATUS_OUT_OF_RANGE,
                RadarOperationalRangeDecision::REASON_OUT_OF_RANGE,
                false,
                true,
                RadarOperationalRangeDecision::DISCARD_OUT_OF_RANGE,
                $correlation['distance_m'],
                $correlation['maximum_range_m'],
                $correlation['marker'],
                'episode'
            );
        }

        // F133.1: el firmware puede informar PRESENT sin distancia (DP9=0). En
        // una sala radar explícita y con alcance corto, tras mantener el
        // episodio durante la gracia, se acredita por DP1. Una DP9 positiva
        // fuera de alcance sigue teniendo prioridad y convierte a ausencia.
        if (in_array($correlation['status'], [
                RadarEvidenceCorrelation::STATUS_NO_DISTANCE,
                RadarEvidenceCorrelation::STATUS_ZERO_DISTANCE,
            ], true)
            && $this->dp1OnlyFallbackEligible($room, $session, $settings, (int) $now->format('Uv'))
        ) {
            return $this->rangeGate(
                RadarOperationalRangeDecision::STATUS_IN_RANGE,
                RadarEvidenceCorrelation::REASON_DP1_WITHOUT_DISTANCE,
                true,
                false,
                null,
                null,
                $correlation['maximum_range_m'],
                $correlation['marker'],
                'dp1_only'
            );
        }

        return $this->rangeGate(
            RadarOperationalRangeDecision::STATUS_UNVERIFIED,
            (string) $correlation['reason'],
            false,
            false,
            RadarOperationalRangeDecision::DISCARD_UNVERIFIED,
            $correlation['distance_m'],
            $correlation['maximum_range_m'],
            $correlation['marker'],
            'episode'
        );
    }

    /** @param array<string,mixed>|null $report */
    private static function reportDistance(?array $report): ?float
    {
        if (!is_array($report) || !isset($report['distance_m']) || !is_numeric($report['distance_m'])) {
            return null;
        }
        $distance = (float) $report['distance_m'];
        return is_finite($distance) ? $distance : null;
    }

    /**
     * Elige la evidencia DP9 del episodio: prioriza una fila persistida con
     * distancia positiva, luego la DP9 de la trama y por último la última DP9
     * positiva del episodio. Una distancia 0 nunca gana a una positiva.
     *
     * @param array<string,mixed>|null $frame
     * @param array<string,mixed>|null $persisted
     * @param array<string,mixed>|null $latest
     * @return array<string,mixed>|null
     */
    private static function selectEpisodeReport(?array $frame, ?array $persisted, ?array $latest): ?array
    {
        foreach ([$persisted, $frame, $latest] as $candidate) {
            $distance = self::reportDistance($candidate);
            if ($distance !== null && $distance > 0.0) {
                return $candidate;
            }
        }
        return $persisted ?? $frame ?? $latest;
    }

    /**
     * F134: decisión diagnóstica para una sala sin política radar explícita
     * cuando la evidencia DP9 ya está dentro del alcance. No aplica presencia
     * por sí sola: el flujo normal decide y actualiza el resultado final. Sirve
     * para que `presence_outcome_json` no se quede clavado en una decisión
     * antigua (`range_unverified`/`marker_already_used`) mientras la sesión ya
     * está en PRESENT.
     *
     * @param array<string,mixed> $rangeGate
     * @param array<string,mixed>|null $episode
     * @return array<string,mixed>
     */
    private static function rangeGateDecision(
        string $presenceState,
        ?array $episode,
        array $rangeGate,
        string $at
    ): array {
        $confirmed = strtoupper($presenceState) === IotSession::PRESENCE_PRESENT;
        return [
            'mode' => 'range_gate',
            'state' => $confirmed
                ? RadarPresenceDecision::STATE_PRESENT_CONFIRMED
                : RadarPresenceDecision::STATE_CANDIDATE_PRESENT,
            'raw' => PresenceEvent::VALUE_PRESENT,
            'apply' => false,
            'discard_reason' => null,
            'stale' => false,
            'range_gate' => $rangeGate,
            'episode' => $episode,
            'at' => $at,
            'diagnostic' => true,
        ];
    }

    /** F133.1: respaldo DP1 acotado a salas radar normales con alcance corto. */
    private function dp1OnlyFallbackEligible(
        Room $room,
        IotSession $session,
        array $settings,
        int $nowMs
    ): bool {
        $maxRangeRaw = Config::get('RADAR_DP1_ONLY_MAX_RANGE_M', '3.0');
        $graceRaw = Config::get('RADAR_DP1_ONLY_GRACE_SECONDS', '2.0');
        return RadarEvidenceCorrelation::dp1OnlyEligible(
            $room->presenceModeOverride !== null && $room->presenceModeOverride !== '',
            $this->isWarehouseRoom($room) || $this->isTimbradoRoom($room),
            $this->mysqlToMs($session->presenceEpisodeStartedAt),
            $nowMs,
            $settings,
            Config::getBool('RADAR_DP1_ONLY_ENABLED', true),
            is_numeric($maxRangeRaw) ? (float) $maxRangeRaw : 3.0,
            (int) round((is_numeric($graceRaw) ? (float) $graceRaw : 2.0) * 1000)
        );
    }

    /** @return array<string,mixed> */
    private function rangeGate(
        string $status,
        string $reason,
        bool $allowPresent,
        bool $convertToAbsent,
        ?string $discardReason,
        ?float $distance,
        ?float $maximum,
        ?string $marker,
        string $evidenceSource
    ): array {
        return [
            'status' => $status,
            'reason' => $reason,
            'allow_present' => $allowPresent,
            'convert_to_absent' => $convertToAbsent,
            'discard_reason' => $discardReason,
            'distance_m' => $distance !== null ? round($distance, 3) : null,
            'maximum_range_m' => $maximum !== null ? round($maximum, 3) : null,
            'distance_marker' => $marker,
            'config_key' => null,
            'precision_m' => RadarOperationalRangeDecision::PRECISION_M,
            'evidence_source' => $evidenceSource,
        ];
    }

    /**
     * F132: resuelve los tiempos de transición de la sala. Solo las salas
     * normales con override radar explícito usan entrada diferida/ausencia
     * sostenida; almacén y TIMBRADO conservan su comportamiento.
     *
     * @return array{entry_ms:int,absent_ms:int}
     */
    private function transitionHolds(Room $room): array
    {
        return PresenceTransitionPolicy::holds(
            $room->presenceModeOverride !== null && $room->presenceModeOverride !== '',
            $this->isWarehouseRoom($room) || $this->isTimbradoRoom($room),
            Config::get('RADAR_PRESENT_ENTRY_HOLD_SECONDS'),
            Config::get('RADAR_ABSENT_EDGE_GRACE_SECONDS')
        );
    }

    /**
     * F132: una transición pendiente no publica estado operativo. PRESENT abre
     * una espera corta de entrada; ABSENT sostenido abre la espera de salida.
     * Una evidencia contraria cancela la espera y, si su plazo ya venció, se
     * aplica en esta misma trama.
     *
     * @param array<string,mixed> $decision
     * @param array<string,mixed>|null $meta
     * @return array<string,mixed>
     */
    private function applyPendingPolicy(
        Room $room,
        IotSession $session,
        array $decision,
        string $evtUtc,
        ?array $meta
    ): array {
        $holds = $this->transitionHolds($room);
        $value = strtoupper(trim((string) ($decision['raw'] ?? '')));
        $nowMs = $this->mysqlToMs($evtUtc);
        if ($nowMs === null) {
            return $decision;
        }
        $transition = PresenceTransitionPolicy::transition(
            $value,
            $session->presenceState,
            $session->presencePendingState,
            $this->mysqlToMs($session->presencePendingDueAt),
            $nowMs,
            $holds
        );
        switch ($transition['action']) {
            case 'ignore':
                return $decision;

            case 'apply':
                $this->clearPending($session);
                $decision['apply'] = true;
                $decision['confirmed'] = true;
                $decision['state'] = $transition['state'];
                $decision['discard_reason'] = null;
                $decision['outcome'] = $transition['outcome'];
                return $decision;

            case 'maintain':
                $this->clearPending($session);
                $decision['apply'] = false;
                $decision['confirmed'] = false;
                $decision['state'] = $transition['state'];
                $decision['discard_reason'] = $transition['reason'];
                $decision['outcome'] = $transition['outcome'];
                return $decision;

            case 'pending':
            default:
                $report = is_array($meta['presence_report'] ?? null) ? $meta['presence_report'] : [];
                $samePending = $session->presencePendingState === $value
                    && $session->presencePendingDueAt !== null;
                if (!$samePending) {
                    $session->presencePendingSince = $evtUtc;
                    $session->presencePendingMarker = (string) ($report['marker'] ?? '') ?: null;
                    $session->presencePendingConfigKey = (string) ($report['config_key'] ?? '') ?: null;
                }
                $session->presencePendingState = $value;
                if ($transition['due_at_ms'] !== null) {
                    $session->presencePendingDueAt = $this->mysqlUtcFromMs($transition['due_at_ms']);
                }
                $decision['apply'] = false;
                $decision['confirmed'] = false;
                $decision['state'] = $transition['state'];
                $decision['discard_reason'] = $transition['reason'];
                $decision['outcome'] = $transition['outcome'];
                $decision['pending'] = $this->pendingPayload($session);
                return $decision;
        }
    }

    private function clearPending(IotSession $session): void
    {
        $session->presencePendingState = null;
        $session->presencePendingSince = null;
        $session->presencePendingDueAt = null;
        $session->presencePendingMarker = null;
        $session->presencePendingConfigKey = null;
    }

    /** @return array{state:string,since:?string,due_at:?string,marker:?string}|null */
    private function pendingPayload(IotSession $session): ?array
    {
        if ($session->presencePendingState === null) {
            return null;
        }
        return [
            'state' => $session->presencePendingState,
            'since' => $session->presencePendingSince,
            'due_at' => $session->presencePendingDueAt,
            'marker' => $session->presencePendingMarker,
        ];
    }

    /**
     * F132: añade el resultado final y el estado efectivo a una decisión radar.
     *
     * @param array<string,mixed> $decision
     * @return array<string,mixed>
     */
    private function tagOutcome(array $decision, IotSession $session, string $evtUtc): array
    {
        $applied = ($decision['apply'] ?? false) === true;
        $reason = isset($decision['discard_reason']) ? (string) $decision['discard_reason'] : null;
        $value = strtoupper(trim((string) ($decision['raw'] ?? '')));
        $inForce = $value !== '' && $session->presenceState === $value;
        $decision['outcome'] = $decision['outcome']
            ?? PresenceTransitionPolicy::outcomeFor($reason, $applied, $inForce);
        $decision['outcome_reason'] = $reason;
        $decision['value'] = $decision['value'] ?? ($value !== '' ? $value : null);
        $decision['effective_presence'] = $session->presenceState;
        $decision['event_at'] = $evtUtc;
        $decision['pending'] = $this->pendingPayload($session);
        $decision['episode'] = $decision['episode'] ?? $this->episodePayload($session);
        return $decision;
    }

    /**
     * @param array<string,mixed> $decision
     * @return array<string,mixed>
     */
    private function outcomeFromDecision(array $decision): array
    {
        return [
            'state' => $decision['state'] ?? null,
            'raw' => $decision['raw'] ?? null,
            'value' => $decision['value'] ?? ($decision['raw'] ?? null),
            'apply' => ($decision['apply'] ?? false) === true,
            'outcome' => $decision['outcome'] ?? null,
            'outcome_reason' => $decision['outcome_reason'] ?? null,
            'effective_presence' => $decision['effective_presence'] ?? null,
            'mode' => $decision['mode'] ?? null,
            'operational_mode' => $decision['operational_mode'] ?? null,
            'discard_reason' => $decision['discard_reason'] ?? null,
            'decided_at' => $decision['decided_at'] ?? null,
            'event_at' => $decision['event_at'] ?? null,
            'marker' => $decision['marker'] ?? null,
            'source' => $decision['source'] ?? null,
            'range_gate' => $decision['range_gate'] ?? null,
            'pending' => $decision['pending'] ?? null,
            'episode' => $decision['episode'] ?? null,
            'converted_from' => $decision['converted_from'] ?? null,
            // F133: trazabilidad de la evidencia utilizada.
            'evidence_source' => is_array($decision['range_gate'] ?? null)
                ? ($decision['range_gate']['evidence_source'] ?? null) : null,
            'evidence_reason' => is_array($decision['range_gate'] ?? null)
                ? ($decision['range_gate']['reason'] ?? null) : null,
            'evidence_distance_m' => is_array($decision['range_gate'] ?? null)
                ? ($decision['range_gate']['distance_m'] ?? null) : null,
            'evidence_marker' => is_array($decision['range_gate'] ?? null)
                ? ($decision['range_gate']['distance_marker'] ?? null) : null,
            'evidence_age_s' => is_array($decision['range_gate'] ?? null)
                ? ($decision['range_gate']['age_seconds'] ?? null) : null,
        ];
    }

    /**
     * F132: confirma una transición pendiente sin esperar a un nuevo reporte.
     * La llama el worker local `exit-scan` (sin consultar el dispositivo).
     *
     * @return array{result:string,room_id:int}
     */
    public function resolvePendingPresence(int $roomId, string $correlationId): array
    {
        $room = $this->rooms->findById($roomId);
        if ($room === null || $this->resolveRadarMode($room, $this->roomTypes->findById($room->roomTypeId)) === null) {
            return ['result' => 'skipped', 'room_id' => $roomId];
        }
        $result = 'skipped';
        for ($attempt = 1; $attempt <= self::MAX_TX_RETRIES; $attempt++) {
            try {
                $this->iotSessions->beginTransaction();
                $session = $this->iotSessions->lockByRoomId($roomId);
                $pending = $session->presencePendingState;
                if ($pending === null || $session->presencePendingDueAt === null) {
                    $this->iotSessions->commit();
                    return ['result' => 'skipped', 'room_id' => $roomId];
                }
                $clock = Clock::nowUtc();
                $nowMs = (int) $clock->format('Uv');
                $dueMs = $this->mysqlToMs($session->presencePendingDueAt);
                if ($dueMs === null || $nowMs < $dueMs) {
                    $this->iotSessions->commit();
                    return ['result' => 'pending', 'room_id' => $roomId];
                }

                // F133: revalidar la evidencia completa antes de aplicar.
                [$evidenceOk, $evidenceReason] = $this->revalidatePendingEvidence(
                    $session,
                    (string) $pending,
                    $roomId
                );
                if (!$evidenceOk) {
                    $this->clearPending($session);
                    $session->presenceOutcome = [
                        'outcome' => 'maintained',
                        'outcome_reason' => $evidenceReason,
                        'effective_presence' => $session->presenceState,
                        'episode' => $this->episodePayload($session),
                        'decided_at' => $clock->format('Y-m-d\TH:i:s.v\Z'),
                    ];
                    $this->iotSessions->updateState($session);
                    $this->iotSessions->commit();
                    return ['result' => 'superseded', 'room_id' => $roomId];
                }
                $nowUtc = $this->mysqlUtcFromMs($nowMs);
                if ($pending === PresenceEvent::VALUE_PRESENT) {
                    $session->presenceState = IotSession::PRESENCE_PRESENT;
                    $session->lastPresenceValue = PresenceEvent::VALUE_PRESENT;
                    $session->lastAbsentSince = null;
                } else {
                    if ($session->presenceState !== IotSession::PRESENCE_ABSENT) {
                        $session->lastAbsentSince = $session->presencePendingSince ?? $nowUtc;
                    }
                    $session->presenceState = IotSession::PRESENCE_ABSENT;
                    $session->lastPresenceValue = PresenceEvent::VALUE_ABSENT;
                }
                // Frescura monotónica: resolver un pendiente no puede hacer
                // retroceder la marca del último reporte recibido.
                $pendingSince = $session->presencePendingSince;
                if ($pendingSince !== null
                    && ($session->lastPresenceEventAt === null || $session->lastPresenceEventAt < $pendingSince)
                ) {
                    $session->lastPresenceEventAt = $pendingSince;
                }
                $resolvedEpisode = $this->episodePayload($session);
                $this->clearPending($session);
                $session->presenceOutcome = [
                    'state' => $pending === PresenceEvent::VALUE_PRESENT
                        ? RadarPresenceDecision::STATE_PRESENT_CONFIRMED
                        : RadarPresenceDecision::STATE_ABSENT_CONFIRMED,
                    'raw' => $pending,
                    'value' => $pending,
                    'apply' => true,
                    'outcome' => PresenceTransitionPolicy::OUTCOME_APPLIED,
                    'outcome_reason' => null,
                    'effective_presence' => $session->presenceState,
                    'decided_at' => Clock::nowUtc()->format('Y-m-d\TH:i:s.v\Z'),
                    'event_at' => $session->lastPresenceEventAt,
                    'source' => 'pending_resolver',
                    'episode' => $resolvedEpisode,
                    'pending' => null,
                ];
                $this->iotSessions->updateState($session);
                $this->iotSessions->commit();
                $result = 'applied';
                break;
            } catch (PDOException $e) {
                $this->safeRollback();
                if (!$this->isRetryableDeadlock($e) || $attempt >= self::MAX_TX_RETRIES) {
                    throw $e;
                }
                usleep(20_000 * $attempt + random_int(0, 10_000));
            } catch (\Throwable $e) {
                $this->safeRollback();
                throw $e;
            }
        }

        // Efectos post-commit, nunca bajo el lock.
        if ($result === 'applied' && $session !== null) {
            if ($this->exitActionService !== null) {
                try {
                    $this->exitActionService->executeIfPending($roomId, $correlationId);
                } catch (\Throwable $e) {
                    error_log('[IotSessionService] pending exit evaluation failed: ' . $e->getMessage());
                }
            }
        }
        return ['result' => $result, 'room_id' => $roomId];
    }

    /**
     * F133: una transición pendiente solo se aplica si la evidencia sigue
     * siendo la misma: signo del último DP1, configuración y, para una entrada,
     * una DP9 positiva del episodio. Sin datos no se inventa una transición.
     *
     * @return array{0:bool,1:?string}
     */
    private function revalidatePendingEvidence(IotSession $session, string $pending, int $roomId): array
    {
        $latest = $this->presenceReports?->latestForRoom($roomId);
        if ($latest !== null) {
            $latestValue = strtoupper((string) ($latest['value'] ?? ''));
            if ($latestValue !== strtoupper($pending)) {
                return [false, PresenceTransitionPolicy::REASON_SUPERSEDED];
            }
            $latestKey = (string) ($latest['config_key'] ?? '');
            $pendingKey = (string) ($session->presencePendingConfigKey ?? '');
            if ($pendingKey !== '' && $latestKey !== '' && !hash_equals($pendingKey, $latestKey)) {
                return [false, 'config_changed'];
            }
        }

        if (strtoupper($pending) === PresenceEvent::VALUE_PRESENT && $this->distanceReports !== null) {
            $sinceMs = $this->mysqlToMs($session->presenceEpisodeStartedAt)
                ?? $this->mysqlToMs($session->presencePendingSince);
            $since = $sinceMs !== null
                ? gmdate('Y-m-d H:i:s', intdiv($sinceMs, 1000) - 2)
                : gmdate('Y-m-d H:i:s', time() - 120);
            $configKey = $latest !== null && isset($latest['config_key'])
                ? (string) $latest['config_key'] : null;
            $distance = $this->distanceReports->latestPositiveForRoom($roomId, $since, $configKey ?: null);
            if ($distance === null) {
                return [false, RadarEvidenceCorrelation::REASON_NO_DP9];
            }
        }

        return [true, null];
    }

    private function mysqlToMs(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new \DateTimeZone('UTC'));
        if ($date === false) {
            $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        }
        if ($date === false) {
            return null;
        }
        return $date->getTimestamp() * 1000 + intdiv((int) $date->format('u'), 1000);
    }

    private function mysqlUtcFromMs(int $ms): string
    {
        $seconds = intdiv($ms, 1000);
        $millis = $ms % 1000;
        return gmdate('Y-m-d H:i:s', $seconds) . '.' . str_pad((string) $millis, 3, '0', STR_PAD_LEFT);
    }

    /**
     * F93/RF-154.3: decisión radar sobre un histórico acotado por tiempo.
     *
     * Se ejecuta con la sesión bloqueada. El horizonte cubre todas las ventanas
     * configuradas; nunca depende de "las últimas N filas", porque la cadencia
     * del radar cambia con el estado (más mensajes en reposo que con presencia).
     *
     * @return array<string,mixed>|null
     */
    private function computeRadarDecision(
        Room $room,
        ?RoomType $roomType,
        string $mode,
        IotSession $session,
        \DateTimeImmutable $now
    ): ?array {
        if ($roomType === null) {
            return null;
        }
        $config = [
            'mode' => $mode,
            'confirm_reports' => $roomType->presenceConfirmReports,
            'confirm_window_seconds' => $roomType->presenceConfirmWindowSeconds,
            'min_span_seconds' => $roomType->presenceMinSpanSeconds,
            'hold_seconds' => $roomType->presenceHoldSeconds,
            'absent_reports' => $roomType->presenceAbsentReports,
            'absent_window_seconds' => $roomType->presenceAbsentWindowSeconds,
            'door_window_seconds' => $roomType->presenceDoorWindowSeconds,
            'door_recent_open_seconds' => (int) (Config::getInt('RADAR_MIXED_RECENT_OPEN_SECONDS', 10) ?? 10),
            'stale_seconds' => $roomType->presenceStaleSeconds,
        ];

        $horizon = max(
            (int) $config['confirm_window_seconds'],
            (int) $config['absent_window_seconds'],
            (int) $config['min_span_seconds'],
            (int) $config['hold_seconds'],
            (int) $config['door_window_seconds']
        );
        // Margen x4 + frescura, acotado a [10 min, 6 h]: suficiente para una
        // ocupación continua sin escanear toda la tabla.
        $horizon = min(21600, max(600, $horizon * 4 + (int) $config['stale_seconds']));

        $history = [];
        foreach ($this->presenceEvents->listForRoomSince($room->id, $now->modify('-' . $horizon . ' seconds'), 5000) as $h) {
            // F130: un PRESENT sin DP9 verificable no acredita presencia y no
            // debe reutilizarse después como evidencia temporal.
            if ($h->discardReason === RadarOperationalRangeDecision::DISCARD_UNVERIFIED) {
                continue;
            }
            $history[] = [
                'sensor' => $h->sensor,
                'value' => $h->value,
                'provider' => $h->provider,
                'occurred_at' => $h->occurredAt,
                'source_event_id' => $h->sourceEventId,
            ];
        }

        $physical = false;
        if ($mode === RadarPresenceDecision::MODE_CAMERA_FUSION) {
            $physical = $this->warehousePhysicalEvidence($room, $session, $now);
        }

        return RadarPresenceDecision::decide(
            $history,
            $now,
            $config,
            $physical,
            $session->doorState,
            $session->lastOpenAt
        );
    }

    /**
     * F48 (RF-57): contexto de credibilidad de presencia para la habitación.
     *
     * @return array{0:bool,1:bool} [entryWindowActive, insideNoExitCycle]
     */
    private function presenceContext(Room $room, IotSession $session, \DateTimeImmutable $now): array
    {
        $activeStay       = $this->stays->findActiveForRoom($room->id);
        $entryConfirmedAt = $activeStay !== null ? $activeStay->entryConfirmedAt : null;
        $lastOpenTs       = $session->lastOpenAt !== null
            ? strtotime($session->lastOpenAt . ' UTC')
            : null;
        $nowTs            = $now->getTimestamp();

        // Entrada en curso: hay apertura reciente y la entrada AÚN no está confirmada.
        $entryWindowActive = ($activeStay === null || $entryConfirmedAt === null)
            && $lastOpenTs !== null
            && ($nowTs - $lastOpenTs) <= $this->resolveEntryWindowSeconds($room);

        // Huésped dentro: estancia confirmada y sin apertura posterior a la confirmación.
        $insideNoExitCycle = false;
        if ($entryConfirmedAt !== null) {
            $confTs = strtotime($entryConfirmedAt . ' UTC');
            if ($confTs !== false) {
                $insideNoExitCycle = ($lastOpenTs === null || $lastOpenTs < $confTs);
            }
        }

        return [$entryWindowActive, $insideNoExitCycle];
    }

    /**
     * F80 (RF-103.1, restaura F71/RF-81): contexto de presencia para el almacén.
     *
     * El requisito del panel es que una visita pueda empezar al **detectar presencia**,
     * aunque la puerta se quede abierta a propósito (entrar/salir/volver). Por eso la
     * presencia del 24G es creíble **al instante**: no se veta por `door_state=OPEN` ni
     * se exige un ciclo de puerta previo. Deroga el veto de F76 (RF-91.2/RF-92.1).
     *
     * `activeEnteredVisitAt()` (que ya incluye las visitas por `PRESENCE`) se conserva
     * como ancla informativa de "dentro"; ya no decide credibilidad.
     *
     * @return array{0:bool,1:bool} [entryWindowActive, insideNoExitCycle]
     */
    private function warehousePresenceContext(Room $room, IotSession $session, \DateTimeImmutable $now): array
    {
        $insideNoExitCycle = false;
        if ($this->warehouseRecorder !== null) {
            try {
                $insideNoExitCycle = $this->warehouseRecorder->activeEnteredVisitAt($room->id) !== null;
            } catch (\Throwable $e) {
                $insideNoExitCycle = false;
            }
        }
        // La ventana de entrada está siempre activa en el almacén: cualquier `PRESENT`
        // (`presence` o `move`) se aplica de inmediato.
        return [true, $insideNoExitCycle];
    }

    /**
     * F88 (RF-123.3): evidencia física independiente del radar para corroborar un
     * `PRESENT` en el almacén. Es cierta si hubo un evento de puerta reciente
     * (`FUSION_DOOR_WINDOW_SECONDS`, def. 90) o movimiento reciente de la cámara
     * INTERIOR (`FUSION_MOTION_WINDOW_SECONDS`, def. 20). Con
     * `FUSION_INCLUDE_EXTERIOR=true` también vale cualquier cámara. Solo BD local.
     */
    private function warehousePhysicalEvidence(
        Room $room,
        IotSession $session,
        \DateTimeImmutable $now
    ): bool {
        $nowTs = $now->getTimestamp();

        $doorWindow = (int) (Config::getInt('FUSION_DOOR_WINDOW_SECONDS', 90) ?? 90);
        if ($doorWindow <= 0) {
            $doorWindow = 90;
        }
        if ($session->lastDoorEventAt !== null && $session->lastDoorEventAt !== '') {
            $t = strtotime($session->lastDoorEventAt . ' UTC');
            if ($t !== false && ($nowTs - $t) <= $doorWindow) {
                return true;
            }
        }

        if ($this->warehouseRecorder === null) {
            return false;
        }
        $motionWindow = (int) (Config::getInt('FUSION_MOTION_WINDOW_SECONDS', 20) ?? 20);
        if ($motionWindow <= 0) {
            $motionWindow = 20;
        }
        $includeExterior = filter_var(
            (string) (Config::get('FUSION_INCLUDE_EXTERIOR', 'false') ?? 'false'),
            FILTER_VALIDATE_BOOLEAN
        );
        foreach ($includeExterior ? [null] : ['INTERIOR'] as $position) {
            try {
                $at = $this->warehouseRecorder->latestMotionAt($room->id, $position);
            } catch (\Throwable $e) {
                $at = null;
            }
            if ($at === null) {
                continue;
            }
            $t = strtotime($at . ' UTC');
            if ($t !== false && ($nowTs - $t) <= $motionWindow) {
                return true;
            }
        }
        return false;
    }

    /**
     * F71 (RF-81.1/81.4): ¿es la sala un almacén de bebidas? Se resuelve por
     * el código del tipo (`ALMACEN_BEBIDAS`), sin consultas extra más allá del
     * repositorio de tipos ya inyectado.
     */
    private function isWarehouseRoom(Room $room): bool
    {
        $roomType = $this->roomTypes->findById($room->roomTypeId);
        return $roomType instanceof RoomType
            && $roomType->code === self::WAREHOUSE_TYPE_CODE;
    }

    /**
     * F117: la sala es de tipo TIMBRADO (oficina).
     */
    private function isTimbradoRoom(Room $room): bool
    {
        $roomType = $this->roomTypes->findById($room->roomTypeId);
        return $roomType instanceof RoomType
            && $roomType->code === 'TIMBRADO';
    }

    private function resolveEntryWindowSeconds(Room $room): int
    {
        $roomType = $this->roomTypes->findById($room->roomTypeId);
        if ($roomType instanceof RoomType && $roomType->presenceEntryWindowSeconds > 0) {
            return (int) $roomType->presenceEntryWindowSeconds;
        }
        return 90;
    }

    private function safeRollback(): void
    {
        try {
            $this->iotSessions->rollBack();
        } catch (\Throwable $e) {
            error_log('[IotSessionService] rollback failed: ' . $e->getMessage());
        }
    }

    private function isRetryableDeadlock(PDOException $e): bool
    {
        $code = (string) $e->getCode();
        $msg  = $e->getMessage();
        return $code === '40001'
            || str_contains($msg, '1213')
            || str_contains($msg, '1205')
            || str_contains($msg, 'Deadlock')
            || str_contains($msg, 'Lock wait timeout');
    }

    /**
     * Convert an ISO-8601 timestamp (any offset) to a MySQL UTC DATETIME(3)
     * string. Tolerates various formats including bare UTC.
     *
     * F58 (RF-65): delega en `IsoTime` (RFC3339_EXTENDED, con fracción de ms);
     * un `\Z` literal se interpretaría en la TZ por defecto y desplazaría el
     * sello. Sin fecha válida se usa el ahora UTC con ms.
     */
    private function isoToMysqlUtc(string $iso): string
    {
        return IsoTime::toMysqlUtc($iso) ?? Clock::nowUtc()->format('Y-m-d H:i:s.v');
    }
}
