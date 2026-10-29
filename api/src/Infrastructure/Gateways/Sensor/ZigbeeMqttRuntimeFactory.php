<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Sensor;

use App\Domain\Anomalies\AnomalyPipeline;
use App\Domain\Anomalies\AnomalyRepository;
use App\Domain\Anomalies\AnomalyService;
use App\Domain\Anomalies\Detectors\DoorOpenWithoutQr;
use App\Domain\Anomalies\Detectors\ExitWithoutDoorOpen;
use App\Domain\Anomalies\Detectors\PresenceAfterExit;
use App\Domain\Anomalies\Detectors\PresenceWithoutDoorOpen;
use App\Domain\Anomalies\Detectors\PresenceWithoutStay;
use App\Domain\Anomalies\Detectors\SensorFlapping;
use App\Domain\Devices\DeviceRepository;
use App\Domain\Devices\SwitchService;
use App\Domain\Locks\AccessEventRepository;
use App\Domain\Presence\IotSessionRepository;
use App\Domain\Presence\IotSessionServiceFactory;
use App\Domain\Presence\PresenceEventRepository;
use App\Domain\Presence\RoomSensorStateRepository;
use App\Domain\Rooms\RoomRepository;
use App\Domain\Rooms\RoomTypeRepository;
use App\Domain\Stays\StayRepository;
use App\Domain\Stays\StayStateMachine;
use App\Domain\Warehouse\WarehouseRecordingService;
use App\Domain\Workers\WorkerSessionRepository;
use PDO;

/** Builds the same domain event pipeline for the standalone MQTT worker. */
final class ZigbeeMqttRuntimeFactory
{
    public static function create(PDO $pdo): ZigbeeMqttEventProcessor
    {
        $devices = new DeviceRepository($pdo);
        $rooms = new RoomRepository($pdo);
        $roomTypes = new RoomTypeRepository($pdo);
        $stays = new StayRepository($pdo);
        $stayStateMachine = new StayStateMachine($stays);
        $accessEvents = new AccessEventRepository($pdo);
        $iotSessions = new IotSessionRepository($pdo);
        $presenceEvents = new PresenceEventRepository($pdo);
        $workerSessions = new WorkerSessionRepository($pdo);
        $switchService = new SwitchService($devices, $rooms);

        $anomalyEnabledTypes = ['A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8'];
        $setting = $pdo->query(
            "SELECT value FROM system_settings WHERE service='api' AND setting_key='anomaly.enabled_types'"
        )->fetch(PDO::FETCH_ASSOC);
        if ($setting && !empty($setting['value'])) {
            $decoded = json_decode((string) $setting['value'], true);
            if (is_array($decoded)) {
                $anomalyEnabledTypes = $decoded;
            }
        }
        $enabled = static fn (string $type): bool => in_array($type, $anomalyEnabledTypes, true);

        $anomalyRepository = new AnomalyRepository($pdo);
        $exitWithoutDoor = new ExitWithoutDoorOpen();
        $detectors = [];
        if ($enabled('A1')) {
            $detectors[] = new PresenceWithoutDoorOpen($pdo);
        }
        if ($enabled('A2')) {
            $detectors[] = new PresenceWithoutStay();
        }
        if ($enabled('A3')) {
            $detectors[] = new DoorOpenWithoutQr($pdo);
        }
        if ($enabled('A6')) {
            $detectors[] = new PresenceAfterExit();
        }
        if ($enabled('A8')) {
            $detectors[] = new SensorFlapping($pdo);
        }
        $anomalies = new AnomalyService(new AnomalyPipeline($detectors), $anomalyRepository, $exitWithoutDoor);

        $iotService = IotSessionServiceFactory::create(
            $presenceEvents,
            $iotSessions,
            $rooms,
            $roomTypes,
            $stays,
            $stayStateMachine,
            $accessEvents,
            $switchService,
            $anomalies,
            $workerSessions,
            new WarehouseRecordingService($pdo),
            new RoomSensorStateRepository($pdo)
        );

        return new ZigbeeMqttEventProcessor($devices, $iotService);
    }
}
