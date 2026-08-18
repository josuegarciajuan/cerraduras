<?php
declare(strict_types=1);

namespace App\Domain\Rooms;

use App\Support\Errors\ConflictException;
use App\Support\Errors\NotFoundException;
use App\Support\Errors\UnprocessableException;

/**
 * RoomService: CRUD business logic for rooms.
 *
 * State transitions driven by operational events (RESERVED, OCCUPIED,
 * OVERSTAY, EXITED) are owned by dedicated services (StayStateMachine,
 * ExitRuleEvaluator, DebtsService). This service covers only:
 *   - Listing/filtering
 *   - Create / update metadata (code, type, simulated override)
 *   - Admin-driven state transitions (see RoomStateService).
 */
final class RoomService
{
    private RoomRepositoryInterface $rooms;
    private RoomTypeRepositoryInterface $roomTypes;

    public function __construct(RoomRepositoryInterface $rooms, RoomTypeRepositoryInterface $roomTypes)
    {
        $this->rooms = $rooms;
        $this->roomTypes = $roomTypes;
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<Room>
     */
    public function listFiltered(array $filters, int $limit, int $offset): array
    {
        return $this->rooms->listFiltered($filters, $limit, $offset);
    }

    public function getOrFail(int $id): Room
    {
        $r = $this->rooms->findById($id);
        if ($r === null) {
            throw new NotFoundException('Room not found', ['room_id' => $id]);
        }
        return $r;
    }

    public function create(string $code, int $roomTypeId, ?bool $simulatedOverride, ?string $notes = null): Room
    {
        $code = trim($code);
        $this->validateCode($code);
        if ($this->roomTypes->findById($roomTypeId) === null) {
            throw new NotFoundException('Room type not found', ['room_type_id' => $roomTypeId]);
        }
        if ($this->rooms->findByCode($code) !== null) {
            throw new ConflictException(
                'room_code_exists',
                'Room code already exists',
                ['code' => $code]
            );
        }
        $id = $this->rooms->insert($code, $roomTypeId, $simulatedOverride, $notes);
        return $this->getOrFail($id);
    }

    /**
     * Update metadata. Does NOT change `status`; use RoomStateService for that.
     *
     * @param array<string,mixed> $fields
     */
    public function update(int $id, array $fields): Room
    {
        $room = $this->getOrFail($id);
        $updates = [];

        if (array_key_exists('code', $fields)) {
            $newCode = trim((string) $fields['code']);
            $this->validateCode($newCode);
            if ($newCode !== $room->code) {
                $existing = $this->rooms->findByCode($newCode);
                if ($existing && $existing->id !== $room->id) {
                    throw new ConflictException('Code already used by another room');
                }
                $updates['code'] = $newCode;
            }
        }

        if (array_key_exists('room_type_id', $fields)) {
            $rtId = (int) $fields['room_type_id'];
            if ($this->roomTypes->findById($rtId) === null) {
                throw new NotFoundException('Room type not found');
            }
            $updates['room_type_id'] = $rtId;
        }

        if (array_key_exists('simulated_override', $fields)) {
            $v = $fields['simulated_override'];
            if ($v === null) {
                $updates['simulated_override'] = null;
            } else {
                $updates['simulated_override'] = (bool) $v ? 1 : 0;
            }
        }

        if (array_key_exists('notes', $fields)) {
            $updates['notes'] = $fields['notes'] === null ? null : (string) $fields['notes'];
        }
        if (array_key_exists('pack_id', $fields)) {
            $updates['pack_id'] = $fields['pack_id'];
        }
        if (array_key_exists('presence_check_seconds', $fields)) {
            $v = $fields['presence_check_seconds'];
            $updates['presence_check_seconds'] = $v === null || $v === '' ? null : (int) $v;
        }

        // RF-21: detect pack removal to trigger room reset
        $packRemoved = array_key_exists('pack_id', $fields)
            && $fields['pack_id'] === null
            && $room->packId !== null;

        $this->rooms->update($id, $updates);

        if ($packRemoved) {
            $this->rooms->resetAfterPackRemoval($id);
        }

        return $this->getOrFail($id);
    }

    public function delete(int $id): void
    {
        $this->getOrFail($id);
        $this->rooms->delete($id);
    }

    private function validateCode(string $code): void
    {
        if ($code === '') {
            throw new UnprocessableException('invalid_code', 'code is required');
        }
        if (mb_strlen($code) > 32) {
            throw new UnprocessableException('invalid_code', 'code must be <= 32 chars');
        }
    }
}
