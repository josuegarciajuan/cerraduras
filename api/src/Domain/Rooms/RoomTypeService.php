<?php
declare(strict_types=1);

namespace App\Domain\Rooms;

use App\Support\Errors\ConflictException;
use App\Support\Errors\NotFoundException;
use App\Support\Errors\UnprocessableException;

/**
 * RoomTypeService: business rules around RoomType mutations.
 *
 * The service is kept thin; the repository does the SQL and this layer:
 *   - Validates field ranges (grace/gap/cooldown/qr window).
 *   - Maps unique-constraint violations to 409 conflicts.
 *   - Maps not-found to 404.
 *
 * All ranges are conservative defaults and can be tightened later.
 */
final class RoomTypeService
{
    private RoomTypeRepositoryInterface $repo;

    // Ranges (contracts.md §1 does not pin these numbers; chosen to avoid
    // nonsensical values while leaving room for tuning).
    private const MIN_GRACE   = 0;   // minutes
    private const MAX_GRACE   = 120;
    private const MIN_GAP     = 1;   // seconds
    private const MAX_GAP     = 600;
    private const MIN_COOL    = 0;   // seconds
    private const MAX_COOL    = 600;
    private const MIN_QR_WIN  = 5;   // minutes
    private const MAX_QR_WIN  = 240;

    public function __construct(RoomTypeRepositoryInterface $repo)
    {
        $this->repo = $repo;
    }

    /** @return list<RoomType> */
    public function listAll(): array
    {
        return $this->repo->listAll();
    }

    public function getOrFail(int $id): RoomType
    {
        $rt = $this->repo->findById($id);
        if ($rt === null) {
            throw new NotFoundException('Room type not found', ['room_type_id' => $id]);
        }
        return $rt;
    }

    /**
     * Create a room type. Field values are validated before hitting the DB.
     */
    public function create(
        string $code,
        string $name,
        int $graceMinutes,
        int $exitPresenceGapSeconds,
        int $reentryCooldownSeconds,
        int $qrUsageWindowMinutes
    ): RoomType {
        $code = trim($code);
        $name = trim($name);
        $this->validateCode($code);
        $this->validateName($name);
        $this->validateRanges(
            $graceMinutes,
            $exitPresenceGapSeconds,
            $reentryCooldownSeconds,
            $qrUsageWindowMinutes
        );

        if ($this->repo->findByCode($code) !== null) {
            throw new ConflictException(
                'room_type_code_exists',
                'Room type code already exists',
                ['code' => $code]
            );
        }

        $id = $this->repo->insert(
            $code,
            $name,
            $graceMinutes,
            $exitPresenceGapSeconds,
            $reentryCooldownSeconds,
            $qrUsageWindowMinutes
        );

        return $this->getOrFail($id);
    }

    /**
     * Update a subset of fields. Keys use snake_case, matching the repository.
     *
     * @param array<string,int|string|null> $fields
     */
    public function update(int $id, array $fields): RoomType
    {
        $current = $this->getOrFail($id);

        $sanitized = [];
        if (array_key_exists('code', $fields) && $fields['code'] !== null) {
            $code = trim((string) $fields['code']);
            $this->validateCode($code);
            if ($code !== $current->code && $this->repo->findByCode($code) !== null) {
                throw new ConflictException(
                    'room_type_code_exists',
                    'Room type code already exists',
                    ['code' => $code]
                );
            }
            $sanitized['code'] = $code;
        }
        if (array_key_exists('name', $fields) && $fields['name'] !== null) {
            $name = trim((string) $fields['name']);
            $this->validateName($name);
            $sanitized['name'] = $name;
        }

        $grace = $fields['grace_minutes'] ?? $current->graceMinutes;
        $gap = $fields['exit_presence_gap_seconds'] ?? $current->exitPresenceGapSeconds;
        $cool = $fields['reentry_cooldown_seconds'] ?? $current->reentryCooldownSeconds;
        $qr = $fields['qr_usage_window_minutes'] ?? $current->qrUsageWindowMinutes;
        $this->validateRanges((int) $grace, (int) $gap, (int) $cool, (int) $qr);

        foreach (['grace_minutes','exit_presence_gap_seconds','reentry_cooldown_seconds','qr_usage_window_minutes'] as $k) {
            if (array_key_exists($k, $fields) && $fields[$k] !== null) {
                $sanitized[$k] = (int) $fields[$k];
            }
        }

        if (!empty($sanitized)) {
            $this->repo->update($id, $sanitized);
        }
        return $this->getOrFail($id);
    }

    public function delete(int $id): void
    {
        $this->getOrFail($id);
        $this->repo->delete($id);
    }

    private function validateCode(string $code): void
    {
        if ($code === '') {
            throw new UnprocessableException('invalid_code', 'code is required');
        }
        if (mb_strlen($code) > 32) {
            throw new UnprocessableException('invalid_code', 'code must be <= 32 chars');
        }
        if (!preg_match('/^[A-Z0-9_\-]+$/', $code)) {
            throw new UnprocessableException(
                'invalid_code',
                'code must be uppercase alphanumeric with _ or -',
                ['allowed' => 'A-Z0-9_-']
            );
        }
    }

    private function validateName(string $name): void
    {
        if ($name === '') {
            throw new UnprocessableException('invalid_name', 'name is required');
        }
        if (mb_strlen($name) > 128) {
            throw new UnprocessableException('invalid_name', 'name must be <= 128 chars');
        }
    }

    private function validateRanges(int $grace, int $gap, int $cool, int $qr): void
    {
        if ($grace < self::MIN_GRACE || $grace > self::MAX_GRACE) {
            throw new UnprocessableException(
                'invalid_range',
                'grace_minutes out of range',
                ['min' => self::MIN_GRACE, 'max' => self::MAX_GRACE]
            );
        }
        if ($gap < self::MIN_GAP || $gap > self::MAX_GAP) {
            throw new UnprocessableException(
                'invalid_range',
                'exit_presence_gap_seconds out of range',
                ['min' => self::MIN_GAP, 'max' => self::MAX_GAP]
            );
        }
        if ($cool < self::MIN_COOL || $cool > self::MAX_COOL) {
            throw new UnprocessableException(
                'invalid_range',
                'reentry_cooldown_seconds out of range',
                ['min' => self::MIN_COOL, 'max' => self::MAX_COOL]
            );
        }
        if ($qr < self::MIN_QR_WIN || $qr > self::MAX_QR_WIN) {
            throw new UnprocessableException(
                'invalid_range',
                'qr_usage_window_minutes out of range',
                ['min' => self::MIN_QR_WIN, 'max' => self::MAX_QR_WIN]
            );
        }
    }
}
