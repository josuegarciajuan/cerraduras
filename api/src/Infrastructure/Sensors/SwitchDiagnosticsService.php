<?php
declare(strict_types=1);

namespace App\Infrastructure\Sensors;

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepository;
use App\Domain\Sensors\SwitchCommandEvidenceRepository;
use App\Support\Config;
use Closure;
use PDO;

/**
 * SwitchDiagnosticsService — F112/RF-263..RF-264.
 *
 * Construye el contexto de un posible fallo de la luz Zigbee. Es de solo
 * lectura: no publica ordenes, no sondea el dispositivo y no reinicia nada.
 *
 * Las fuentes que no pueden obtenerse se declaran `unavailable` con motivo.
 * No se rellena un hueco con una suposicion.
 */
final class SwitchDiagnosticsService
{
    private const UNITS = [
        'cerraduras-zigbee-tunnel',
        'cerraduras-zigbee-ingress',
        'cerraduras-zigbee-spool',
    ];

    /** @var (Closure():array{status:string,reason:?string,detail?:mixed})|null */
    private ?Closure $serviceProbe;

    /** @var (Closure():array{status:string,reason:?string,detail?:mixed})|null */
    private ?Closure $logProbe;

    public function __construct(
        private PDO $pdo,
        ?Closure $serviceProbe = null,
        ?Closure $logProbe = null
    ) {
        $this->serviceProbe = $serviceProbe;
        $this->logProbe = $logProbe;
    }

    /**
     * @return array<string,mixed>
     */
    public function report(int $roomId, int $windowMinutes, int $limit): array
    {
        $windowMinutes = max(5, min(1440, $windowMinutes));
        $limit = max(1, min(200, $limit));
        $to = $this->nowMs();
        $from = gmdate('Y-m-d H:i:s', time() - ($windowMinutes * 60)) . '.000';

        $devices = new DeviceRepository($this->pdo);
        $device = $devices->findForRoomKind($roomId, Device::KIND_SWITCH);
        $repo = new SwitchCommandEvidenceRepository($this->pdo);
        $latest = $repo->latestForRoom($roomId);
        $recent = $repo->recentForRoom($roomId, $from, $limit);

        $meta = $device !== null && is_array($device->meta) ? $device->meta : [];
        $lastReported = isset($meta['switch_state']) ? strtoupper((string) $meta['switch_state']) : null;
        $lastReportedAt = isset($meta['switch_state_at']) ? (string) $meta['switch_state_at'] : null;
        $lastSeenAt = null;
        $onlineState = null;
        if ($device !== null) {
            $row = $this->pdo->prepare('SELECT last_seen_at, online_state FROM devices WHERE id = :id LIMIT 1');
            $row->execute([':id' => $device->id]);
            $extra = $row->fetch(PDO::FETCH_ASSOC) ?: [];
            $lastSeenAt = isset($extra['last_seen_at']) ? (string) $extra['last_seen_at'] : null;
            $onlineState = $extra['online_state'] === null ? null : (bool) $extra['online_state'];
        }

        $sources = [];
        if ($device === null) {
            $sources[] = ['name' => 'device_telemetry', 'status' => 'unavailable', 'reason' => 'switch_not_found'];
        } elseif ($lastReportedAt === null) {
            $sources[] = ['name' => 'device_telemetry', 'status' => 'partial', 'reason' => 'sin_estado_reportado'];
        } else {
            $sources[] = ['name' => 'device_telemetry', 'status' => 'ok'];
        }
        $sources[] = $this->runProbe('zigbee_services', $this->serviceProbe, [$this, 'probeServices']);
        $sources[] = $this->runProbe('zigbee2mqtt_log', $this->logProbe, [$this, 'probeLog']);

        $notes = [
            'Publicado no implica actuacion fisica: la confirmacion llega por telemetria o por observacion.',
            'La proximidad temporal de un error de Zigbee2MQTT es una pista, no una causa atribuida.',
        ];
        if ($latest !== null && in_array((string) $latest['verdict'], ['unconfirmed', 'indeterminate'], true)) {
            $notes[] = 'La ultima orden no tiene confirmacion suficiente; revisa las fuentes y observa la lampara.';
        }

        return [
            'ok' => true,
            'room_id' => $roomId,
            'device' => $device === null ? null : [
                'id' => (int) $device->id,
                'kind' => 'SWITCH',
                'external_id' => (string) $device->externalId,
                'provider' => 'ZIGBEE2MQTT',
            ],
            'window' => [
                'from' => $from,
                'to' => $to,
                'minutes' => $windowMinutes,
            ],
            'last_operation' => $latest === null ? null : $this->formatOperation($latest),
            'state' => [
                'last_reported' => $lastReported,
                'reported_at' => $lastReportedAt,
                'age_seconds' => $this->ageSeconds($lastReportedAt, $to),
                'last_seen_at' => $lastSeenAt,
                'online_state' => $onlineState,
            ],
            'operations' => array_map(fn (array $row): array => $this->formatOperation($row), $recent),
            'sources' => $sources,
            'notes' => $notes,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function formatOperation(array $row): array
    {
        return [
            'operation_id' => (string) $row['operation_id'],
            'action' => (string) $row['action'],
            'origin' => (string) $row['origin'],
            'requested_at' => (string) $row['requested_at'],
            'published' => $row['publish_ok'] === null ? null : ((int) $row['publish_ok'] === 1),
            'publish_error' => $row['publish_error'] !== null ? (string) $row['publish_error'] : null,
            'published_at' => $row['published_at'] !== null ? (string) $row['published_at'] : null,
            'prior_state' => $row['prior_state'] !== null ? (string) $row['prior_state'] : null,
            'report' => [
                'state' => $row['reported_state'] !== null ? (string) $row['reported_state'] : null,
                'at' => $row['reported_at'] !== null ? (string) $row['reported_at'] : null,
                'fresh' => $row['fresh'] === null ? null : ((int) $row['fresh'] === 1),
                'source' => $row['report_source'] !== null ? (string) $row['report_source'] : null,
            ],
            'human' => [
                'state' => $row['human_state'] !== null ? (string) $row['human_state'] : null,
                'at' => $row['human_at'] !== null ? (string) $row['human_at'] : null,
            ],
            'verdict' => (string) $row['verdict'],
            'diagnosis' => $row['diagnosis'] !== null ? (string) $row['diagnosis'] : null,
        ];
    }

    /**
     * @return array{status:string,reason:?string,detail?:mixed}
     */
    public function probeServices(): array
    {
        if (!function_exists('exec')) {
            return ['status' => 'unavailable', 'reason' => 'exec_disabled'];
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('exec', $disabled, true)) {
            return ['status' => 'unavailable', 'reason' => 'exec_disabled'];
        }
        $states = [];
        $active = 0;
        foreach (self::UNITS as $unit) {
            $out = [];
            $code = 1;
            @exec('systemctl is-active ' . escapeshellarg($unit) . ' 2>/dev/null', $out, $code);
            $state = trim((string) ($out[0] ?? 'unknown'));
            $states[$unit] = $state;
            if ($state === 'active') {
                $active++;
            }
        }
        if ($active === count(self::UNITS)) {
            return ['status' => 'ok', 'reason' => null, 'detail' => $states];
        }
        return [
            'status' => $active > 0 ? 'partial' : 'unavailable',
            'reason' => 'unit_not_active',
            'detail' => $states,
        ];
    }

    /**
     * @return array{status:string,reason:?string,detail?:mixed}
     */
    public function probeLog(): array
    {
        $path = (string) (Config::get('ZIGBEE2MQTT_LOG_PATH', '') ?? '');
        if ($path === '') {
            return ['status' => 'unavailable', 'reason' => 'not_configured'];
        }
        if (!is_file($path) || !is_readable($path)) {
            return ['status' => 'unavailable', 'reason' => 'not_readable'];
        }
        return ['status' => 'ok', 'reason' => null];
    }

    /**
     * @param (Closure():array{status:string,reason:?string,detail?:mixed})|null $injected
     * @param callable():array{status:string,reason:?string,detail?:mixed} $fallback
     * @return array{name:string,status:string,reason:?string,detail?:mixed}
     */
    private function runProbe(string $name, ?Closure $injected, callable $fallback): array
    {
        try {
            $result = $injected !== null ? $injected() : $fallback();
        } catch (\Throwable $e) {
            return ['name' => $name, 'status' => 'unavailable', 'reason' => 'probe_failed'];
        }
        $status = in_array($result['status'] ?? '', ['ok', 'partial', 'unavailable'], true)
            ? (string) $result['status']
            : 'unavailable';
        $out = ['name' => $name, 'status' => $status];
        if ($status !== 'ok') {
            $out['reason'] = (string) ($result['reason'] ?? 'unknown');
        }
        if (isset($result['detail'])) {
            $out['detail'] = $result['detail'];
        }
        return $out;
    }

    private function nowMs(): string
    {
        $now = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
        if ($now === false) {
            return gmdate('Y-m-d H:i:s') . '.000';
        }
        return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private function ageSeconds(?string $at, string $now): ?int
    {
        if ($at === null || $at === '') {
            return null;
        }
        $ts = strtotime(str_replace('T', ' ', rtrim($at, 'Z')) . ' UTC');
        $nowTs = strtotime(str_replace('T', ' ', rtrim($now, 'Z')) . ' UTC');
        if ($ts === false || $nowTs === false) {
            return null;
        }
        return max(0, $nowTs - $ts);
    }
}
