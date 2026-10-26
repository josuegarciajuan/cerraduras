<?php
declare(strict_types=1);

namespace App\Domain\Presence;

use App\Domain\Anomalies\AnomalyService;
use App\Domain\Devices\SwitchService;
use App\Domain\Locks\AccessEventRepositoryInterface;
use App\Domain\Rooms\RoomRepositoryInterface;
use App\Domain\Rooms\RoomTypeRepositoryInterface;
use App\Domain\Stays\StayRepositoryInterface;
use App\Domain\Stays\StayStateMachine;
use App\Domain\Warehouse\WarehouseRecordingServiceInterface;
use App\Domain\Workers\WorkerSessionRepositoryInterface;

/** Shared composition of the authoritative per-room IoT domain pipeline. */
final class IotSessionServiceFactory
{
    public static function create(
        PresenceEventRepositoryInterface $presenceEvents,
        IotSessionRepositoryInterface $iotSessions,
        RoomRepositoryInterface $rooms,
        RoomTypeRepositoryInterface $roomTypes,
        StayRepositoryInterface $stays,
        StayStateMachine $stayStateMachine,
        AccessEventRepositoryInterface $accessEvents,
        SwitchService $switchService,
        AnomalyService $anomalies,
        WorkerSessionRepositoryInterface $workerSessions,
        WarehouseRecordingServiceInterface $warehouseRecorder
    ): IotSessionService {
        $exitEvaluator = new ExitRuleEvaluator($iotSessions, $rooms, $roomTypes, $stays);
        $exitActions = new ExitActionService(
            $rooms,
            $iotSessions,
            $stayStateMachine,
            $accessEvents,
            $switchService,
            $anomalies,
            $workerSessions,
            $stays,
            $exitEvaluator,
            $roomTypes
        );

        return new IotSessionService(
            $presenceEvents,
            $iotSessions,
            $rooms,
            $roomTypes,
            $stays,
            $stayStateMachine,
            $accessEvents,
            $exitEvaluator,
            $switchService,
            $exitActions,
            $anomalies,
            $workerSessions,
            $warehouseRecorder
        );
    }
}
