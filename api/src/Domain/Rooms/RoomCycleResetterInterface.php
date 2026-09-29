<?php
declare(strict_types=1);

namespace App\Domain\Rooms;

/**
 * RoomCycleResetterInterface: higiene del ciclo anterior al crear un QR nuevo.
 *
 * F59 (RF-66): una salida deja `rooms.cooldown_until` (anti-reentrada) y el
 * estado IoT del ciclo anterior. Antes de crear/emitir un QR nuevo hay que
 * limpiarlos para que la coreografía no muestre `ANTI_REENTRADA` ni el escaneo
 * sea rechazado con `room_cooldown`.
 */
interface RoomCycleResetterInterface
{
    /** Deja la habitación sin cooldown heredado y con el estado IoT neutro. */
    public function reset(int $roomId): void;
}
