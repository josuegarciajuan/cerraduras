<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

/** Pure evaluator for the F111 exterior and re-entry gates. */
final class RadarExteriorPresenceEvaluator
{
    /** @param list<array{value:string,received_at:string,config_key?:string|null,marker?:string}> $reports */
    public static function evaluate(array $reports, string $startedAt, ?string $configKey, ?callable $clock = null): array
    {
        $start = self::time($startedAt);
        $now = $clock === null ? microtime(true) : (float) $clock();
        $valid = [];
        foreach ($reports as $report) {
            $at = self::time((string) ($report['received_at'] ?? ''));
            if ($at === null || ($start !== null && $at < $start)) continue;
            if ($configKey !== null && ($report['config_key'] ?? null) !== $configKey) continue;
            $valid[] = $report + ['_at' => $at];
        }
        usort($valid, static fn(array $a, array $b): int => $a['_at'] <=> $b['_at']);
        $presents = count(array_filter($valid, static fn(array $r): bool => strtoupper((string) $r['value']) === 'PRESENT'));
        $absents = count(array_filter($valid, static fn(array $r): bool => strtoupper((string) $r['value']) === 'ABSENT'));
        $last = $valid === [] ? null : strtoupper((string) $valid[array_key_last($valid)]['value']);
        return [
            'status' => $presents > 0 ? 'detected' : ($absents >= 2 && $last === 'ABSENT' ? 'clear' : 'inconclusive'),
            'detected' => $presents > 0,
            'clear' => $presents === 0 && $absents >= 2 && $last === 'ABSENT',
            'reports' => count($valid), 'presents' => $presents, 'absents' => $absents,
            'last' => $last, 'age_seconds' => $valid === [] ? null : max(0, $now - (float) $valid[array_key_last($valid)]['_at']),
        ];
    }

    /** A re-entry is a new DP1 marker, not a cached state or a timestamp alone. */
    public static function reentry(array $reports, string $startedAt, ?string $configKey, ?callable $clock = null): array
    {
        $result = self::evaluate($reports, $startedAt, $configKey, $clock);
        $newPresent = $result['presents'] > 0 && $result['last'] === 'PRESENT';
        $result['confirmed'] = $newPresent;
        $result['status'] = $newPresent ? 'confirmed' : 'inconclusive';
        return $result;
    }

    private static function time(string $value): ?float
    {
        if ($value === '') return null;
        $time = strtotime($value . (str_contains($value, 'UTC') ? '' : ' UTC'));
        return $time === false ? null : (float) $time;
    }
}
