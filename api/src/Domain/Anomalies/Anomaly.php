<?php
declare(strict_types=1);

namespace App\Domain\Anomalies;

/**
 * Anomaly: a sensor-flow inconsistency detected for a room.
 *
 * Status lifecycle:
 *   OPEN → ACKNOWLEDGED → DISMISSED  (manual resolution)
 *   OPEN → DISMISSED                  (auto-resolved when condition clears)
 *
 * See design.md §1.1 (F35), requirements.md RF-35.3.
 */
final class Anomaly
{
    public const STATUS_OPEN         = 'OPEN';
    public const STATUS_ACKNOWLEDGED = 'ACKNOWLEDGED';
    public const STATUS_DISMISSED    = 'DISMISSED';

    public const TYPE_A1 = 'A1'; // presence_without_door_open
    public const TYPE_A2 = 'A2'; // presence_without_stay
    public const TYPE_A3 = 'A3'; // door_open_without_qr
    public const TYPE_A4 = 'A4'; // presence_without_door_activity
    public const TYPE_A5 = 'A5'; // exited_without_door_open
    public const TYPE_A6 = 'A6'; // presence_after_exit
    public const TYPE_A7 = 'A7'; // door_open_without_presence
    public const TYPE_A8 = 'A8'; // sensor_flapping

    public const SEVERITY_LOW      = 'LOW';
    public const SEVERITY_MEDIUM   = 'MEDIUM';
    public const SEVERITY_HIGH     = 'HIGH';
    public const SEVERITY_CRITICAL = 'CRITICAL';

    /** @var array<string,string> type → severity mapping */
    public const SEVERITY_MAP = [
        self::TYPE_A1 => self::SEVERITY_HIGH,
        self::TYPE_A2 => self::SEVERITY_HIGH,
        self::TYPE_A3 => self::SEVERITY_CRITICAL,
        self::TYPE_A4 => self::SEVERITY_HIGH,
        self::TYPE_A5 => self::SEVERITY_MEDIUM,
        self::TYPE_A6 => self::SEVERITY_HIGH,
        self::TYPE_A7 => self::SEVERITY_MEDIUM,
        self::TYPE_A8 => self::SEVERITY_LOW,
    ];

    public int $id;
    public int $roomId;
    public ?string $roomCode;
    public ?int $stayId;
    public string $anomalyType;
    public string $severity;
    public string $status;
    /** @var array<string,mixed> */
    public array $contextData;
    public string $detectedAt;
    public ?string $acknowledgedAt;
    public ?string $acknowledgedBy;
    public ?string $dismissedAt;
    public ?string $dismissedBy;
    public string $createdAt;
    public string $updatedAt;

    /**
     * @param array<string,mixed> $contextData
     */
    public function __construct(
        int     $id,
        int     $roomId,
        ?int    $stayId,
        string  $anomalyType,
        string  $severity,
        string  $status,
        array   $contextData,
        string  $detectedAt,
        ?string $acknowledgedAt,
        ?string $acknowledgedBy,
        ?string $dismissedAt,
        ?string $dismissedBy,
        string  $createdAt,
        string  $updatedAt,
        ?string $roomCode = null
    ) {
        $this->id              = $id;
        $this->roomId          = $roomId;
        $this->stayId          = $stayId;
        $this->anomalyType     = $anomalyType;
        $this->severity        = $severity;
        $this->status          = $status;
        $this->contextData     = $contextData;
        $this->detectedAt      = $detectedAt;
        $this->acknowledgedAt  = $acknowledgedAt;
        $this->acknowledgedBy  = $acknowledgedBy;
        $this->dismissedAt     = $dismissedAt;
        $this->dismissedBy     = $dismissedBy;
        $this->createdAt       = $createdAt;
        $this->updatedAt       = $updatedAt;
        $this->roomCode        = $roomCode;
    }

    /**
     * Acknowledge this anomaly (OPEN → ACKNOWLEDGED).
     *
     * @throws \RuntimeException if status is not OPEN
     */
    public function acknowledge(string $actor, string $now): void
    {
        if ($this->status !== self::STATUS_OPEN) {
            throw new \RuntimeException("Anomaly {$this->id} is not OPEN (current: {$this->status})");
        }
        $this->status         = self::STATUS_ACKNOWLEDGED;
        $this->acknowledgedAt = $now;
        $this->acknowledgedBy = $actor;
    }

    /**
     * Auto-dismiss this anomaly (OPEN/ACKNOWLEDGED → DISMISSED).
     * Called by the periodic scanner when the triggering condition is no longer present.
     */
    public function autoDismiss(string $now): void
    {
        if ($this->status === self::STATUS_DISMISSED) {
            return; // already dismissed, idempotent
        }
        $this->status      = self::STATUS_DISMISSED;
        $this->dismissedAt = $now;
        $this->dismissedBy = 'system';
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'room_id'         => $this->roomId,
            'room_code'       => $this->roomCode,
            'stay_id'         => $this->stayId,
            'anomaly_type'    => $this->anomalyType,
            'severity'        => $this->severity,
            'status'          => $this->status,
            'context_data'    => $this->contextData,
            'detected_at'     => $this->detectedAt,
            'acknowledged_at' => $this->acknowledgedAt,
            'acknowledged_by' => $this->acknowledgedBy,
            'dismissed_at'    => $this->dismissedAt,
            'dismissed_by'    => $this->dismissedBy,
            'created_at'      => $this->createdAt,
            'updated_at'      => $this->updatedAt,
        ];
    }
}
