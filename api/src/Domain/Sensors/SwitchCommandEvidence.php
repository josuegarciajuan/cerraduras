<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

/**
 * SwitchCommandEvidence — F112/RF-261..RF-262.
 *
 * Logica pura del veredicto de una orden enviada a un interruptor Zigbee.
 * No toca BD ni publica nada: separa lo que sabemos de lo que suponemos.
 *
 * Publicado (PUBACK) NO es confirmado. Un estado anterior a la orden no la
 * confirma. La observacion humana es una fuente distinta de la telemetria.
 */
final class SwitchCommandEvidence
{
    public const VERDICTS = [
        'confirmed',
        'unconfirmed',
        'discrepant',
        'publish_failed',
        'indeterminate',
    ];

    public const SOURCES = ['command', 'probe', 'none'];

    public static function normalizeAction(mixed $value): ?string
    {
        $v = strtoupper(trim((string) $value));
        return in_array($v, ['ON', 'OFF'], true) ? $v : null;
    }

    public static function normalizeState(mixed $value): ?string
    {
        return self::normalizeAction($value);
    }

    public static function isOperationId(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    public static function newOperationId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /**
     * Calcula el veredicto de una operacion.
     *
     * @param array{action?:mixed,publish_ok?:?bool,reported_state?:mixed,fresh?:?bool,human_state?:mixed} $evidence
     * @return array{verdict:string,diagnosis:?string}
     */
    public static function evaluate(array $evidence): array
    {
        $action = self::normalizeAction($evidence['action'] ?? null);
        if ($action === null) {
            return ['verdict' => 'indeterminate', 'diagnosis' => 'invalid_action'];
        }

        $publishOk = $evidence['publish_ok'] ?? null;
        if ($publishOk === false) {
            return ['verdict' => 'publish_failed', 'diagnosis' => 'publish_failed'];
        }
        if ($publishOk === null) {
            return ['verdict' => 'indeterminate', 'diagnosis' => 'publish_unknown'];
        }

        $fresh = ($evidence['fresh'] ?? null) === true;
        $reported = self::normalizeState($evidence['reported_state'] ?? null);
        $human = self::normalizeState($evidence['human_state'] ?? null);

        if ($fresh && $reported !== null) {
            if ($reported === $action) {
                if ($human !== null && $human !== $action) {
                    return ['verdict' => 'discrepant', 'diagnosis' => 'human_disagrees'];
                }
                return ['verdict' => 'confirmed', 'diagnosis' => 'telemetry_match'];
            }
            if ($human === $action) {
                return ['verdict' => 'discrepant', 'diagnosis' => 'telemetry_disagrees'];
            }
            return ['verdict' => 'discrepant', 'diagnosis' => 'not_applied'];
        }

        if ($human === $action) {
            return ['verdict' => 'confirmed', 'diagnosis' => 'human_confirmed'];
        }
        if ($human !== null) {
            return ['verdict' => 'unconfirmed', 'diagnosis' => 'human_reports_no_change'];
        }
        return ['verdict' => 'unconfirmed', 'diagnosis' => 'no_confirmation'];
    }

    /**
     * F112: una operacion publicada pero sin cerrar necesita reconciliarse.
     * No se republica; se completa con el estado actual.
     *
     * @param array<string,mixed> $row
     */
    public static function needsReconcile(array $row): bool
    {
        return $row !== []
            && (int) ($row['publish_ok'] ?? 0) === 1
            && ($row['reported_at'] ?? null) === null
            && (string) ($row['verdict'] ?? 'indeterminate') === 'indeterminate';
    }

    /**
     * Recorta una etiqueta de origen/actor. Nunca guarda secretos.
     */
    public static function label(mixed $value, int $max = 32): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $clean = preg_replace('/[^A-Za-z0-9._:-]/', '_', trim($value)) ?? '';
        $clean = trim($clean, '._:-');
        if ($clean === '') {
            return null;
        }
        return substr($clean, 0, max(1, $max));
    }
}
