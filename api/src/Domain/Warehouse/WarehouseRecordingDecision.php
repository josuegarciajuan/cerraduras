<?php
declare(strict_types=1);

namespace App\Domain\Warehouse;

/**
 * WarehouseRecordingDecision: pure state machine for the drinks-warehouse
 * recording rules (F63/RF-71).
 *
 * It receives the current state + an event and returns the next state plus the
 * list of actions. No DB, no time, no side effects → fully unit-testable.
 *
 * States:
 *   IDLE              nothing recording
 *   QR_PENDING        a trigger (QR or door) started both cameras, waiting for
 *                     presence within X seconds
 *   RECORDING_INSIDE  presence confirmed, both cameras recording
 *   EXTERIOR_ONLY     QR no-show: INTERIOR discarded, EXTERIOR kept until
 *                     door close + M (or M after last close)
 *   EXIT_PENDING      presence lost after being present: INTERIOR stopped,
 *                     EXTERIOR keeps recording for M more seconds
 *
 * Events:
 *   QR_OK, DOOR_OPEN, DOOR_CLOSE, PRESENT, ABSENT, X_EXPIRED, M_EXPIRED
 *
 * See design.md §27.2.
 */
final class WarehouseRecordingDecision
{
    public const STATE_IDLE             = 'IDLE';
    public const STATE_QR_PENDING       = 'QR_PENDING';
    public const STATE_RECORDING_INSIDE = 'RECORDING_INSIDE';
    public const STATE_EXTERIOR_ONLY    = 'EXTERIOR_ONLY';
    public const STATE_EXIT_PENDING     = 'EXIT_PENDING';

    public const EV_QR_OK      = 'QR_OK';
    public const EV_DOOR_OPEN  = 'DOOR_OPEN';
    public const EV_DOOR_CLOSE = 'DOOR_CLOSE';
    public const EV_PRESENT    = 'PRESENT';
    public const EV_ABSENT     = 'ABSENT';
    public const EV_X_EXPIRED  = 'X_EXPIRED';
    public const EV_M_EXPIRED  = 'M_EXPIRED';

    public const A_START_EXT      = 'START_EXT';
    public const A_START_INT      = 'START_INT';
    public const A_STOP_EXT       = 'STOP_EXT';
    public const A_STOP_INT       = 'STOP_INT';
    public const A_DISCARD_EXT    = 'DISCARD_EXT';
    public const A_DISCARD_INT    = 'DISCARD_INT';
    public const A_CREATE_VISIT   = 'CREATE_VISIT';
    public const A_CONFIRM_ENTRY  = 'CONFIRM_ENTRY';
    public const A_MARK_EXIT      = 'MARK_EXIT';
    public const A_CLOSE_VISIT    = 'CLOSE_VISIT';
    public const A_VISIT_NO_SHOW  = 'VISIT_NO_SHOW';
    public const A_SET_DEADLINE_X = 'SET_DEADLINE_X';
    public const A_SET_DEADLINE_M = 'SET_DEADLINE_M';
    public const A_CLEAR_DEADLINES = 'CLEAR_DEADLINES';

    /**
     * @param array{state:string,entry_trigger:?string,presence_confirmed:bool} $state
     * @return array{state:string,entry_trigger:?string,presence_confirmed:bool,actions:list<string>}
     */
    public static function decide(array $state, string $event): array
    {
        $s = $state['state'] ?? self::STATE_IDLE;
        $trigger = $state['entry_trigger'] ?? null;
        $confirmed = (bool) ($state['presence_confirmed'] ?? false);

        switch ($s) {
            case self::STATE_IDLE:
                switch ($event) {
                    case self::EV_QR_OK:
                        return self::out(self::STATE_QR_PENDING, 'QR', false, [
                            self::A_CREATE_VISIT, self::A_START_EXT, self::A_START_INT, self::A_SET_DEADLINE_X,
                        ]);
                    case self::EV_DOOR_OPEN:
                        return self::out(self::STATE_QR_PENDING, 'DOOR', false, [
                            self::A_CREATE_VISIT, self::A_START_EXT, self::A_START_INT, self::A_SET_DEADLINE_X,
                        ]);
                    case self::EV_PRESENT:
                        // F71 (RF-81.3): una entrada detectada solo por presencia
                        // también confirma la visita (outcome ENTERED), no solo
                        // la crea. Antes quedaba NO_SHOW contradiciendo
                        // presence_confirmed=true.
                        return self::out(self::STATE_RECORDING_INSIDE, 'PRESENCE', true, [
                            self::A_CREATE_VISIT, self::A_CONFIRM_ENTRY, self::A_START_EXT, self::A_START_INT,
                        ]);
                }
                break;

            case self::STATE_QR_PENDING:
                if ($event === self::EV_PRESENT) {
                    return self::out(self::STATE_RECORDING_INSIDE, $trigger, true, [
                        self::A_CONFIRM_ENTRY, self::A_CLEAR_DEADLINES,
                    ]);
                }
                if ($event === self::EV_X_EXPIRED) {
                    if ($trigger === 'QR') {
                        // Keep the EXTERIOR evidence; discard the INTERIOR clip.
                        return self::out(self::STATE_EXTERIOR_ONLY, $trigger, false, [
                            self::A_STOP_INT, self::A_DISCARD_INT, self::A_SET_DEADLINE_M, self::A_VISIT_NO_SHOW,
                        ]);
                    }
                    // Door without QR: cut and discard both.
                    return self::out(self::STATE_IDLE, null, false, [
                        self::A_STOP_EXT, self::A_STOP_INT, self::A_DISCARD_EXT, self::A_DISCARD_INT,
                        self::A_CLOSE_VISIT, self::A_VISIT_NO_SHOW,
                    ]);
                }
                break; // DOOR_CLOSE / others: keep waiting for X

            case self::STATE_RECORDING_INSIDE:
                if ($event === self::EV_ABSENT) {
                    return self::out(self::STATE_EXIT_PENDING, $trigger, true, [
                        self::A_STOP_INT, self::A_MARK_EXIT, self::A_SET_DEADLINE_M,
                    ]);
                }
                break; // DOOR_CLOSE / other: nothing

            case self::STATE_EXTERIOR_ONLY:
                if ($event === self::EV_M_EXPIRED) {
                    return self::out(self::STATE_IDLE, null, false, [self::A_STOP_EXT, self::A_CLOSE_VISIT]);
                }
                if ($event === self::EV_DOOR_CLOSE) {
                    return self::out(self::STATE_EXTERIOR_ONLY, $trigger, false, [self::A_SET_DEADLINE_M]);
                }
                if ($event === self::EV_PRESENT) {
                    // The person did enter after all: resume INTERIOR.
                    return self::out(self::STATE_RECORDING_INSIDE, $trigger, true, [
                        self::A_CONFIRM_ENTRY, self::A_START_INT, self::A_CLEAR_DEADLINES,
                    ]);
                }
                break;

            case self::STATE_EXIT_PENDING:
                if ($event === self::EV_M_EXPIRED) {
                    return self::out(self::STATE_IDLE, null, false, [self::A_STOP_EXT, self::A_CLOSE_VISIT]);
                }
                if ($event === self::EV_PRESENT) {
                    return self::out(self::STATE_RECORDING_INSIDE, $trigger, true, [
                        self::A_START_INT, self::A_CLEAR_DEADLINES,
                    ]);
                }
                break;
        }

        // Unknown/no-op event: keep state, no actions.
        return self::out($s, $trigger, $confirmed, []);
    }

    /**
     * @param list<string> $actions
     * @return array{state:string,entry_trigger:?string,presence_confirmed:bool,actions:list<string>}
     */
    private static function out(string $state, ?string $trigger, bool $confirmed, array $actions): array
    {
        return [
            'state' => $state,
            'entry_trigger' => $trigger,
            'presence_confirmed' => $confirmed,
            'actions' => $actions,
        ];
    }
}
