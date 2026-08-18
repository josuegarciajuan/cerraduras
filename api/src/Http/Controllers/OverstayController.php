<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Debts\OverstayCalculator;
use App\Domain\Rooms\RoomRepositoryInterface;
use App\Domain\Rooms\RoomTypeRepositoryInterface;
use App\Domain\Stays\StayRepositoryInterface;
use App\Http\Request;
use App\Http\Response;
use App\Support\Errors\NotFoundException;

/**
 * OverstayController: GET /api/v1/stays/{id}/overstay (TSK-112).
 *
 * Resolves grace_minutes from room → room_type chain, then delegates to
 * OverstayCalculator.
 *
 * Scope: stays:read
 */
final class OverstayController
{
    private StayRepositoryInterface     $stays;
    private RoomRepositoryInterface     $rooms;
    private RoomTypeRepositoryInterface $roomTypes;
    private OverstayCalculator          $calculator;

    public function __construct(
        StayRepositoryInterface     $stays,
        RoomRepositoryInterface     $rooms,
        RoomTypeRepositoryInterface $roomTypes,
        OverstayCalculator          $calculator
    ) {
        $this->stays      = $stays;
        $this->rooms      = $rooms;
        $this->roomTypes  = $roomTypes;
        $this->calculator = $calculator;
    }

    public function show(Request $request): Response
    {
        $stayId = (int) $request->routeParam('id');

        $stay = $this->stays->findById($stayId);
        if ($stay === null) {
            throw new NotFoundException('Stay not found', ['stay_id' => $stayId]);
        }

        // Resolve grace_minutes via room → room_type chain.
        $graceMinutes = 5; // safe default
        $room = $this->rooms->findById($stay->roomId);
        if ($room !== null) {
            $roomType = $this->roomTypes->findById($room->roomTypeId);
            if ($roomType !== null) {
                $graceMinutes = $roomType->graceMinutes;
            }
        }

        $result = $this->calculator->calculate($stay, $graceMinutes);
        return Response::json(200, $result);
    }
}
