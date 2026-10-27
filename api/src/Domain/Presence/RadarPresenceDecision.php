<?php
declare(strict_types=1);

namespace App\Domain\Presence;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * RadarPresenceDecision — F91/RF-148.
 *
 * Pure presence decision for Zigbee radar events. It derives the candidate/
 * confirmed state from the persisted event history (no extra reads, no polling)
 * and supports:
 *   camera_fusion      radar + camera/door evidence
 *   radar_door         radar + recent door OPEN
 *   radar_only_shadow  radar-only computed, radar_door used operationally
 *   radar_only         radar-only evidence
 *
 * ABSENT is always applicable. A PRESENT that does not reach the configured
 * evidence is a candidate and must not create visits/recordings/lights.
 */
final class RadarPresenceDecision
{
    public const MODE_CAMERA_FUSION     = 'camera_fusion';
    public const MODE_RADAR_DOOR        = 'radar_door';
    public const MODE_RADAR_ONLY_SHADOW = 'radar_only_shadow';
    public const MODE_RADAR_ONLY        = 'radar_only';
    public const MODE_MIXED             = 'mixed';

    public const STATE_UNKNOWN            = 'UNKNOWN';
    public const STATE_CANDIDATE_PRESENT  = 'CANDIDATE_PRESENT';
    public const STATE_PRESENT_CONFIRMED  = 'PRESENT_CONFIRMED';
    public const STATE_CANDIDATE_ABSENT   = 'CANDIDATE_ABSENT';
    public const STATE_ABSENT_CONFIRMED   = 'ABSENT_CONFIRMED';

    /** @return list<string> */
    public static function modes(): array
    {
        return [
            self::MODE_CAMERA_FUSION,
            self::MODE_RADAR_DOOR,
            self::MODE_RADAR_ONLY_SHADOW,
            self::MODE_RADAR_ONLY,
            self::MODE_MIXED,
        ];
    }

    /**
     * @param list<array{sensor?:string,value?:string,provider?:string,occurred_at?:string,source_event_id?:?string}> $events
     *        Event history in any order; Zigbee presence/door events are used.
     * @param DateTimeImmutable $now
     * @param array{mode?:string,confirm_reports?:int,confirm_window_seconds?:int,min_span_seconds?:int,hold_seconds?:int,absent_reports?:int,absent_window_seconds?:int,door_window_seconds?:int,stale_seconds?:int} $config
     * @param bool $physicalEvidence Camera/door evidence for camera_fusion.
     * @return array<string,mixed>
     */
    public static function decide(
        array $events,
        DateTimeImmutable $now,
        array $config = [],
        bool $physicalEvidence = false,
        ?string $currentDoorState = null
    ): array {
        $mode = (string) ($config['mode'] ?? self::MODE_RADAR_DOOR);
        if (!in_array($mode, self::modes(), true)) {
            $mode = self::MODE_RADAR_DOOR;
        }
        $cfg = self::normalizeConfig($config);
        $nowUtc = $now->setTimezone(new DateTimeZone('UTC'));

        $presence = [];
        $latestDoorOpen = null;
        $latestDoorValue = null;
        $latestDoorTs = null;
        foreach ($events as $event) {
            $provider = (string) ($event['provider'] ?? '');
            if ($provider !== PresenceEvent::PROVIDER_ZIGBEE2MQTT) {
                continue;
            }
            $sensor = (string) ($event['sensor'] ?? '');
            $value = (string) ($event['value'] ?? '');
            $ts = self::toMs($event['occurred_at'] ?? null);
            if ($ts === null) {
                continue;
            }
            if ($sensor === PresenceEvent::SENSOR_PRESENCE
                && in_array($value, [PresenceEvent::VALUE_PRESENT, PresenceEvent::VALUE_ABSENT], true)
            ) {
                $sourceId = (string) ($event['source_event_id'] ?? '');
                $key = $sourceId !== '' ? 'src:' . $sourceId : 'ts:' . $ts . '|' . $value;
                $presence[$key] = [
                    'ts' => $ts,
                    'value' => $value,
                ];
            }
            if ($sensor === PresenceEvent::SENSOR_PROXIMITY
                && in_array($value, [PresenceEvent::VALUE_OPEN, PresenceEvent::VALUE_CLOSED], true)
                && ($latestDoorTs === null || $ts >= $latestDoorTs)
            ) {
                $latestDoorTs = $ts;
                $latestDoorValue = $value;
            }
            if ($sensor === PresenceEvent::SENSOR_PROXIMITY && $value === PresenceEvent::VALUE_OPEN) {
                if ($latestDoorOpen === null || $ts > $latestDoorOpen) {
                    $latestDoorOpen = $ts;
                }
            }
        }

        if ($presence === []) {
            return self::result($mode, self::STATE_UNKNOWN, null, false, 0, 0.0, false, false, 'no_events', $nowUtc, null);
        }

        $sorted = array_values($presence);
        usort($sorted, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);
        $latest = $sorted[count($sorted) - 1];
        $raw = $latest['value'];
        $stale = ($nowUtc->getTimestamp() * 1000 - $latest['ts']) > ($cfg['stale_seconds'] * 1000);

        if ($raw === PresenceEvent::VALUE_ABSENT) {
            $run = [];
            for ($i = count($sorted) - 1; $i >= 0; $i--) {
                if ($sorted[$i]['value'] !== PresenceEvent::VALUE_ABSENT) {
                    break;
                }
                $run[] = $sorted[$i];
            }
            $run = array_reverse($run);
            $reports = count($run);
            $span = $reports > 1 ? ($run[$reports - 1]['ts'] - $run[0]['ts']) / 1000 : 0.0;
            $confirmed = $reports >= $cfg['absent_reports'] && $span >= $cfg['absent_window_seconds'];
            $state = $confirmed ? self::STATE_ABSENT_CONFIRMED : self::STATE_CANDIDATE_ABSENT;
            $decision = self::result($mode, $state, $raw, true, $reports, $span, false, $stale, null, $nowUtc, null);
            $decision['apply'] = true;
            $doorOpenForAbsent = $currentDoorState !== null
                ? $currentDoorState === PresenceEvent::VALUE_OPEN
                : $latestDoorValue === PresenceEvent::VALUE_OPEN;
            $decision['operational_mode'] = $mode === self::MODE_MIXED
                ? ($doorOpenForAbsent ? self::MODE_RADAR_ONLY : self::MODE_RADAR_DOOR)
                : ($mode === self::MODE_RADAR_ONLY_SHADOW ? self::MODE_RADAR_DOOR : $mode);
            if ($mode === self::MODE_RADAR_ONLY_SHADOW) {
                $decision['shadow'] = self::shadowDecision($sorted, $nowUtc, $cfg, $physicalEvidence);
            }
            return $decision;
        }

        // Current PRESENT episode: all PRESENT reports since the last ABSENT.
        $run = [];
        for ($i = count($sorted) - 1; $i >= 0; $i--) {
            if ($sorted[$i]['value'] !== PresenceEvent::VALUE_PRESENT) {
                break;
            }
            $run[] = $sorted[$i];
        }
        $run = array_reverse($run);
        $reports = count($run);
        $episodeStart = $run[0]['ts'];
        $span = $reports > 1 ? ($latest['ts'] - $episodeStart) / 1000 : 0.0;
        $windowStart = $latest['ts'] - ($cfg['confirm_window_seconds'] * 1000);
        $recent = array_values(array_filter($run, static fn (array $e): bool => $e['ts'] >= $windowStart));
        $windowReports = count($recent);
        $windowSpan = $windowReports > 1 ? ($recent[$windowReports - 1]['ts'] - $recent[0]['ts']) / 1000 : 0.0;
        $maxGap = 0.0;
        for ($i = 1; $i < $windowReports; $i++) {
            $maxGap = max($maxGap, ($recent[$i]['ts'] - $recent[$i - 1]['ts']) / 1000);
        }
        $doorWindow = $cfg['door_window_seconds'] * 1000;
        $doorCorroborated = $latestDoorOpen !== null
            && $latestDoorOpen >= ($episodeStart - $doorWindow)
            && $latestDoorOpen <= ($latest['ts'] + 1000);
        $temporalOk = $windowReports >= $cfg['confirm_reports']
            && $windowSpan >= $cfg['min_span_seconds']
            && $maxGap <= $cfg['hold_seconds'];

        $doorOpenForMode = $currentDoorState !== null
            ? $currentDoorState === PresenceEvent::VALUE_OPEN
            : $latestDoorValue === PresenceEvent::VALUE_OPEN;
        $operationalMode = match ($mode) {
            self::MODE_RADAR_ONLY_SHADOW => self::MODE_RADAR_DOOR,
            self::MODE_MIXED => $doorOpenForMode
                ? self::MODE_RADAR_ONLY
                : self::MODE_RADAR_DOOR,
            default => $mode,
        };
        $confirmed = match ($operationalMode) {
            self::MODE_RADAR_ONLY => $temporalOk,
            self::MODE_RADAR_DOOR => $doorCorroborated && $maxGap <= $cfg['hold_seconds'],
            default => $physicalEvidence && $maxGap <= $cfg['hold_seconds'], // camera_fusion
        };
        $state = $confirmed ? self::STATE_PRESENT_CONFIRMED : self::STATE_CANDIDATE_PRESENT;
        $reason = null;
        if (!$confirmed) {
            $reason = match ($operationalMode) {
                self::MODE_RADAR_DOOR => $doorCorroborated ? 'candidate' : 'uncorroborated',
                self::MODE_CAMERA_FUSION => $physicalEvidence ? 'candidate' : 'uncorroborated',
                default => 'candidate',
            };
            if ($maxGap > $cfg['hold_seconds']) {
                $reason = 'candidate';
            }
        }

        $score = $reports
            + ($doorCorroborated ? 3 : 0)
            + (int) floor($span / 10);
        $decision = self::result(
            $mode,
            $state,
            $raw,
            $confirmed,
            $reports,
            $span,
            $doorCorroborated,
            $stale,
            $reason,
            $nowUtc,
            $maxGap
        );
        $decision['apply'] = $confirmed;
        $decision['operational_mode'] = $operationalMode;
        $decision['window_reports'] = $windowReports;
        $decision['evidence_score'] = $score;
        if ($mode === self::MODE_RADAR_ONLY_SHADOW) {
            $decision['shadow'] = self::shadowDecision($sorted, $nowUtc, $cfg, $physicalEvidence);
            $decision['shadow']['disagrees'] = $decision['shadow']['state'] !== $state;
        }
        return $decision;
    }

    /** @param array<string,mixed> $cfg */
    private static function shadowDecision(array $sorted, DateTimeImmutable $now, array $cfg, bool $physicalEvidence): array
    {
        $cfg['mode'] = self::MODE_RADAR_ONLY;
        $events = [];
        foreach ($sorted as $entry) {
            $dt = (new DateTimeImmutable('@' . intdiv((int) $entry['ts'], 1000)))
                ->setTimezone(new DateTimeZone('UTC'));
            $events[] = [
                'sensor' => PresenceEvent::SENSOR_PRESENCE,
                'value' => $entry['value'],
                'provider' => PresenceEvent::PROVIDER_ZIGBEE2MQTT,
                'occurred_at' => $dt->format('Y-m-d\TH:i:s.v\Z'),
                'source_event_id' => 'shadow-' . $entry['ts'] . '-' . $entry['value'],
            ];
        }
        $shadow = self::decide($events, $now, $cfg, $physicalEvidence);
        return [
            'state' => $shadow['state'],
            'confirmed' => $shadow['confirmed'],
            'apply' => $shadow['apply'],
            'discard_reason' => $shadow['discard_reason'],
        ];
    }

    /** @return array<string,mixed> */
    private static function result(
        string $mode,
        string $state,
        ?string $raw,
        bool $confirmed,
        int $reports,
        float $span,
        bool $door,
        bool $stale,
        ?string $reason,
        DateTimeImmutable $now,
        ?float $maxGap
    ): array {
        return [
            'mode' => $mode,
            'operational_mode' => $mode,
            'state' => $state,
            'raw' => $raw,
            'confirmed' => $confirmed,
            'apply' => false,
            'evidence_score' => $reports,
            'reports' => $reports,
            'span_seconds' => round($span, 3),
            'max_gap_seconds' => $maxGap === null ? null : round($maxGap, 3),
            'door_corroborated' => $door,
            'stale' => $stale,
            'discard_reason' => $reason,
            'decided_at' => $now->format('Y-m-d\TH:i:s.v\Z'),
            'shadow' => null,
        ];
    }

    /** @return array{confirm_reports:int,confirm_window_seconds:int,min_span_seconds:int,hold_seconds:int,absent_reports:int,absent_window_seconds:int,door_window_seconds:int,stale_seconds:int} */
    private static function normalizeConfig(array $config): array
    {
        $int = static function (array $c, string $k, int $default, int $min, int $max): int {
            $v = isset($c[$k]) ? (int) $c[$k] : $default;
            return max($min, min($max, $v > 0 ? $v : $default));
        };
        return [
            'confirm_reports' => $int($config, 'confirm_reports', 3, 1, 20),
            'confirm_window_seconds' => $int($config, 'confirm_window_seconds', 12, 1, 300),
            'min_span_seconds' => $int($config, 'min_span_seconds', 6, 0, 300),
            'hold_seconds' => $int($config, 'hold_seconds', 20, 0, 600),
            'absent_reports' => $int($config, 'absent_reports', 3, 1, 20),
            'absent_window_seconds' => $int($config, 'absent_window_seconds', 30, 1, 600),
            'door_window_seconds' => $int($config, 'door_window_seconds', 90, 0, 600),
            'stale_seconds' => $int($config, 'stale_seconds', 180, 30, 86400),
        ];
    }

    private static function toMs(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            return $dt->getTimestamp() * 1000 + intdiv((int) $dt->format('u'), 1000);
        } catch (Throwable) {
            return null;
        }
    }
}
