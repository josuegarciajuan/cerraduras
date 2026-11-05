<?php
declare(strict_types=1);

namespace App\Domain\Warehouse;

use App\Domain\Devices\CommandQueueRepository;
use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepositoryInterface;
use App\Domain\Locks\AccessEvent;
use App\Domain\Locks\AccessEventRepositoryInterface;
use App\Domain\Presence\IotSession;
use App\Domain\Presence\IotSessionRepositoryInterface;
use App\Domain\Rooms\RoomRepositoryInterface;
use App\Support\Errors\ConflictException;
use App\Support\Errors\NotFoundException;

/**
 * WarehouseDoorOpenService — F116/RF-293..RF-296.
 *
 * Manual open from the warehouse panel/timbre. In the local-relay hardware the
 * API cannot actuate the solenoid directly: it enqueues an `open` command for
 * the pack's RPI and the ESP32 executes it on its next command poll. The door
 * sensor is the only authority that confirms the physical opening; therefore
 * this service does NOT feed the warehouse recording engine. The sensor push
 * does that through IotSessionService, which avoids phantom visits when the
 * command never reaches the device or the relay fails.
 */
final class WarehouseDoorOpenService
{
    public function __construct(
        private RoomRepositoryInterface $rooms,
        private DeviceRepositoryInterface $devices,
        private CommandQueueRepository $queue,
        private AccessEventRepositoryInterface $accessEvents,
        private IotSessionRepositoryInterface $iotSessions,
        private WarehouseRecordingServiceInterface $warehouse
    ) {
    }

    /**
     * @return array{result:string,provider:string,queued:bool,command_id?:int,already_open?:bool,deduplicated?:bool}
     *
     * @throws NotFoundException
     * @throws ConflictException
     */
    public function request(int $roomId, string $correlationId, string $actor = 'panel'): array
    {
        $room = $this->rooms->findById($roomId);
        if ($room === null) {
            throw new NotFoundException('Room not found', ['room_id' => $roomId]);
        }
        if (!$this->warehouse->isWarehouseRoom($roomId)) {
            throw new NotFoundException('Warehouse room not found', ['room_id' => $roomId]);
        }

        $lock = $this->devices->findForRoomKind($roomId, Device::KIND_LOCK);
        if ($lock === null) {
            throw new ConflictException(
                'lock_not_configured',
                'No hay cerradura configurada para este almacén'
            );
        }

        if ($room->packId === null) {
            throw new ConflictException(
                'rpi_not_configured',
                'El almacén no tiene un ESP32 asociado'
            );
        }
        $rpis = $this->devices->findByPackAndKind($room->packId, Device::KIND_RPI);
        $rpi = $rpis[0] ?? null;
        if ($rpi === null || $rpi->externalId === '') {
            throw new ConflictException(
                'rpi_not_configured',
                'El almacén no tiene un ESP32 asociado'
            );
        }

        $meta = is_array($rpi->meta) ? $rpi->meta : [];
        if (!RemoteDoorOpenPolicy::isCapable($meta, $rpi->firmwareVersion)) {
            throw new ConflictException(
                'remote_open_unavailable',
                'La cerradura no admite apertura remota hasta actualizar el firmware del ESP32',
                [
                    'device_id' => $rpi->id,
                    'firmware_version' => $rpi->firmwareVersion,
                ]
            );
        }

        $session = $this->iotSessions->findByRoomId($roomId);
        if ($session !== null && $session->doorState === IotSession::DOOR_OPEN) {
            return [
                'result' => 'ok',
                'provider' => 'ESP32',
                'queued' => false,
                'already_open' => true,
            ];
        }

        $active = $this->queue->findRecent($rpi->externalId, 'open', 5);
        if ($active !== null) {
            return [
                'result' => 'ok',
                'provider' => 'ESP32',
                'queued' => true,
                'command_id' => (int) $active['id'],
                'deduplicated' => true,
            ];
        }

        $requestedAt = gmdate('Y-m-d\\TH:i:s\\Z');
        $commandId = $this->queue->enqueue(
            $rpi->externalId,
            'open',
            RemoteDoorOpenPolicy::payload($roomId, $actor, $requestedAt)
        );
        if ($commandId === null) {
            throw new ConflictException(
                'command_queue_unavailable',
                'No se pudo encolar la apertura remota'
            );
        }

        $this->accessEvents->insert(
            $roomId,
            null,
            AccessEvent::KIND_OPEN,
            AccessEvent::RESULT_OK,
            null,
            AccessEvent::PROVIDER_ESP32,
            $correlationId,
            [
                'reason' => 'manual_almacen',
                'queued' => true,
                'command_id' => $commandId,
                'requested_by' => $actor,
                'source' => 'timbre',
                'protocol' => 'remote-open-v1',
            ]
        );

        return [
            'result' => 'ok',
            'provider' => 'ESP32',
            'queued' => true,
            'command_id' => $commandId,
        ];
    }
}
