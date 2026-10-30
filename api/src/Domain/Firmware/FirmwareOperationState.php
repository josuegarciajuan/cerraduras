<?php
declare(strict_types=1);

namespace App\Domain\Firmware;

/**
 * FirmwareOperationState — F100/RF-181.
 *
 * Pure state machine for a remote firmware update. The persisted operation is
 * the source of truth; the panel only renders this state. "COMPLETED" requires
 * a valid confirmation from the device after it rebooted with the new image.
 *
 * Allowed forward flow:
 *   WAITING -> PREPARING -> DOWNLOADING -> VERIFYING -> REBOOTING -> VALIDATING -> COMPLETED
 * Any non-terminal state can fail or expire. FAILED/ROLLED_BACK are terminal.
 * UNCONFIRMED is recoverable: a late valid confirmation can move it to
 * COMPLETED/ROLLED_BACK, but never backwards to an earlier progress state.
 */
final class FirmwareOperationState
{
    public const WAITING      = 'WAITING';
    public const PREPARING    = 'PREPARING';
    public const DOWNLOADING  = 'DOWNLOADING';
    public const VERIFYING    = 'VERIFYING';
    public const REBOOTING    = 'REBOOTING';
    public const VALIDATING   = 'VALIDATING';
    public const COMPLETED    = 'COMPLETED';
    public const FAILED       = 'FAILED';
    public const ROLLED_BACK  = 'ROLLED_BACK';
    public const UNCONFIRMED  = 'UNCONFIRMED';
    public const CANCELLED    = 'CANCELLED';
    public const EXPIRED      = 'EXPIRED';

    /** @var list<string> */
    private const ALL = [
        self::WAITING, self::PREPARING, self::DOWNLOADING, self::VERIFYING,
        self::REBOOTING, self::VALIDATING, self::COMPLETED, self::FAILED,
        self::ROLLED_BACK, self::UNCONFIRMED, self::CANCELLED, self::EXPIRED,
    ];

    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        self::WAITING     => [self::PREPARING, self::CANCELLED, self::EXPIRED, self::UNCONFIRMED],
        self::PREPARING   => [self::DOWNLOADING, self::VERIFYING, self::FAILED, self::CANCELLED, self::UNCONFIRMED],
        self::DOWNLOADING => [self::DOWNLOADING, self::VERIFYING, self::FAILED, self::UNCONFIRMED],
        self::VERIFYING   => [self::DOWNLOADING, self::REBOOTING, self::FAILED, self::UNCONFIRMED],
        self::REBOOTING   => [self::VALIDATING, self::FAILED, self::ROLLED_BACK, self::UNCONFIRMED],
        self::VALIDATING  => [self::VALIDATING, self::COMPLETED, self::FAILED, self::ROLLED_BACK, self::UNCONFIRMED],
        self::COMPLETED   => [],
        self::FAILED      => [],
        self::ROLLED_BACK => [],
        self::UNCONFIRMED => [self::COMPLETED, self::FAILED, self::ROLLED_BACK, self::UNCONFIRMED],
        self::CANCELLED   => [],
        self::EXPIRED     => [],
    ];

    /** @var array<string,string> */
    private const PUBLIC_LABEL = [
        self::WAITING     => 'Esperando al dispositivo',
        self::PREPARING   => 'Preparando actualización',
        self::DOWNLOADING => 'Descargando e instalando',
        self::VERIFYING   => 'Verificando firmware',
        self::REBOOTING   => 'Reiniciando',
        self::VALIDATING  => 'Comprobando funcionamiento',
        self::COMPLETED   => 'Completado',
        self::FAILED      => 'Fallido',
        self::ROLLED_BACK => 'Revertido',
        self::UNCONFIRMED => 'Sin confirmación',
        self::CANCELLED   => 'Cancelado',
        self::EXPIRED     => 'Caducado',
    ];

    public static function isValid(string $status): bool
    {
        return in_array(strtoupper($status), self::ALL, true);
    }

    public static function normalize(string $status): string
    {
        return strtoupper(trim($status));
    }

    public static function publicLabel(string $status): string
    {
        $s = self::normalize($status);
        return self::PUBLIC_LABEL[$s] ?? 'Estado desconocido';
    }

    public static function isTerminal(string $status): bool
    {
        return in_array(
            self::normalize($status),
            [self::COMPLETED, self::FAILED, self::ROLLED_BACK, self::CANCELLED, self::EXPIRED],
            true
        );
    }

    /**
     * A transition is legal only when explicitly listed. Repeated reports of
     * the same state are accepted (idempotent) but never move progress
     * backwards (except for a late reconciliation from UNCONFIRMED).
     */
    public static function canTransition(string $from, string $to): bool
    {
        $from = self::normalize($from);
        $to = self::normalize($to);
        if (!isset(self::TRANSITIONS[$from])) {
            return false;
        }
        if ($from === $to) {
            return true;
        }
        return in_array($to, self::TRANSITIONS[$from], true);
    }

    /**
     * Devices report coarse steps; only DOWNLOADING/VALIDATING carry a real
     * percentage. We clamp and never let progress regress.
     */
    public static function clampProgress(string $status, int $pct): int
    {
        $pct = max(0, min(100, $pct));
        if (in_array(self::normalize($status), [self::COMPLETED], true)) {
            return 100;
        }
        if (in_array(self::normalize($status), [self::WAITING, self::PREPARING], true)) {
            return 0;
        }
        return $pct;
    }
}
