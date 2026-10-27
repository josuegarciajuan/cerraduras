<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Switch;

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepositoryInterface;
use App\Support\Config;
use Closure;

/**
 * ZigbeeSwitchGateway (F91/RF-147).
 *
 * Publishes ON/OFF to Zigbee2MQTT and treats the broker PUBACK as delivery,
 * never as physical confirmation. The confirmed state arrives later by the
 * MQTT push path and is persisted in devices.meta_json by
 * ZigbeeSwitchStateService.
 *
 * The actual publish is delegated to a small Node helper that uses the same
 * MQTT credentials as the ingress. No credential is passed as an argument.
 */
final class ZigbeeSwitchGateway implements SwitchGatewayInterface
{
    private const PROVIDER = 'ZIGBEE2MQTT';

    /** @var Closure(string,string):array{ok:bool,error:?string}|null */
    private ?Closure $publisher;

    public function __construct(
        private DeviceRepositoryInterface $devices,
        ?Closure $publisher = null
    ) {
        $this->publisher = $publisher;
    }

    public function turnOn(int $roomId, array $ctx = []): array
    {
        return $this->executeForRoom($roomId, true);
    }

    public function turnOff(int $roomId, array $ctx = []): array
    {
        return $this->executeForRoom($roomId, false);
    }

    public function turnOnForDevice(Device $device, array $ctx = []): array
    {
        return $this->publish($device, 'ON');
    }

    public function turnOffForDevice(Device $device, array $ctx = []): array
    {
        return $this->publish($device, 'OFF');
    }

    public function getProvider(): string
    {
        return self::PROVIDER;
    }

    /**
     * Build the Zigbee2MQTT set topic for a device. Exposed for tests/audit.
     */
    public function setTopic(Device $device): string
    {
        $meta = is_array($device->meta) ? $device->meta : [];
        $base = isset($meta['topic']) && is_string($meta['topic']) && $meta['topic'] !== ''
            ? $meta['topic']
            : 'zigbee2mqtt/' . (isset($meta['friendly_name']) && is_string($meta['friendly_name']) && $meta['friendly_name'] !== ''
                ? $meta['friendly_name']
                : $device->externalId);
        $base = rtrim($base, '/');
        if (str_ends_with($base, '/set')) {
            $base = substr($base, 0, -4);
        }
        return $base . '/set';
    }

    public static function payloadForState(string $state): string
    {
        return json_encode(['state' => $state], JSON_UNESCAPED_SLASHES);
    }

    /** @return array{ok:bool,provider:string,error:string|null} */
    private function executeForRoom(int $roomId, bool $turnOn): array
    {
        $device = $this->devices->findForRoomKind($roomId, Device::KIND_SWITCH);
        if ($device === null) {
            return ['ok' => false, 'provider' => self::PROVIDER, 'error' => 'switch_not_found'];
        }
        return $this->publish($device, $turnOn ? 'ON' : 'OFF');
    }

    /** @return array{ok:bool,provider:string,error:string|null} */
    private function publish(Device $device, string $state): array
    {
        $topic = $this->setTopic($device);
        if (!$this->isValidSetTopic($topic)) {
            return ['ok' => false, 'provider' => self::PROVIDER, 'error' => 'invalid_topic'];
        }
        $payload = self::payloadForState($state);

        try {
            $result = $this->publisher !== null
                ? ($this->publisher)($topic, $payload)
                : $this->runPublisher($topic, $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'provider' => self::PROVIDER, 'error' => $e->getMessage()];
        }

        if (($result['ok'] ?? false) !== true) {
            return ['ok' => false, 'provider' => self::PROVIDER, 'error' => (string) ($result['error'] ?? 'publish_failed')];
        }

        return [
            'ok' => true,
            'provider' => self::PROVIDER,
            'error' => null,
            // The broker accepted the publish; physical state is confirmed only
            // when the matching state message arrives by push.
            'pending_confirmation' => true,
        ];
    }

    /** @return array{ok:bool,error:?string} */
    private function runPublisher(string $topic, string $payload): array
    {
        $apiDir = dirname(__DIR__, 4);
        $helper = $apiDir . '/bin/zigbee-ingress/publish.js';
        if (!is_file($helper)) {
            return ['ok' => false, 'error' => 'publish_helper_missing'];
        }
        $node = (string) (Config::get('ZIGBEE_NODE_BIN', 'node') ?? 'node');
        $timeout = (int) (Config::get('ZIGBEE_SWITCH_TIMEOUT_SECONDS', '5') ?? 5);
        if ($timeout <= 0) {
            $timeout = 5;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open([$node, $helper, $topic, $payload, (string) $timeout], $descriptors, $pipes, $apiDir);
        if (!is_resource($process)) {
            return ['ok' => false, 'error' => 'publish_process_failed'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            $error = trim($stderr) !== '' ? trim($stderr) : 'publish_failed';
            return ['ok' => false, 'error' => substr($error, 0, 300)];
        }
        if (!str_contains($stdout, 'OK')) {
            return ['ok' => false, 'error' => 'publish_no_ack'];
        }
        return ['ok' => true, 'error' => null];
    }

    private function isValidSetTopic(string $topic): bool
    {
        if ($topic === '' || strlen($topic) > 220 || str_contains(strtolower($topic), '-oficina')) {
            return false;
        }
        return preg_match('/^[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*\/set$/', $topic) === 1;
    }
}
