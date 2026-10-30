<?php
declare(strict_types=1);

namespace App\Domain\Firmware;

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepository;
use App\Support\Errors\ConflictException;
use App\Support\Errors\ForbiddenException;
use App\Support\Errors\NotFoundException;
use App\Support\Errors\UnprocessableException;
use PDO;

/**
 * FirmwareOtaService — F100/RF-180..RF-186.
 *
 * Orchestrates the panel request, device manifest, progress reports and the
 * post-reboot confirmation. It deliberately keeps `devices.subtype` as the
 * EFFECTIVE variant: the requested variant lives in the operation until the
 * device proves it booted the new image.
 */
final class FirmwareOtaService
{
    private PDO $pdo;
    private DeviceRepository $devices;
    private FirmwareReleaseRepository $releases;
    private FirmwareOperationRepository $operations;
    private string $storageDir;

    public function __construct(
        PDO $pdo,
        DeviceRepository $devices,
        FirmwareReleaseRepository $releases,
        FirmwareOperationRepository $operations,
        ?string $storageDir = null
    ) {
        $this->pdo = $pdo;
        $this->devices = $devices;
        $this->releases = $releases;
        $this->operations = $operations;
        $this->storageDir = $storageDir ?? dirname(__DIR__, 3) . '/storage/firmware';
    }

    /**
     * Create a switch or upgrade operation. `toVariant === current` means an
     * explicit upgrade of the same variant (the panel uses the dedicated
     * "Actualizar firmware" action).
     *
     * @return array<string,mixed>|null null when nothing would change
     */
    public function request(int $deviceId, ?string $toVariant, string $actor, bool $upgrade = false): ?array
    {
        $device = $this->devices->findById($deviceId);
        if ($device === null) {
            throw new NotFoundException('Device not found', ['device_id' => $deviceId]);
        }
        if ($device->kind !== Device::KIND_RPI) {
            throw new UnprocessableException('device_not_rpi', 'Only ESP (RPI) devices can be updated');
        }

        $fromVariant = Device::normalizeEspVariant($device->subtype);
        $targetVariant = $toVariant !== null && $toVariant !== ''
            ? Device::normalizeEspVariant($toVariant)
            : $fromVariant;

        if (!$upgrade && $targetVariant === $fromVariant) {
            return null;
        }

        $meta = is_array($device->meta) ? $device->meta : [];
        $capable = !empty($meta['ota_capable'])
            || (isset($meta['ota_protocol']) && (string) $meta['ota_protocol'] !== '');
        if (!$capable) {
            throw new UnprocessableException(
                'ota_not_capable',
                'This device still needs a one-time cable flash with an OTA-capable firmware',
                ['device_id' => $device->id]
            );
        }

        $release = $this->releases->findLatestPublished($targetVariant);
        if ($release === null) {
            throw new UnprocessableException(
                'firmware_release_not_found',
                'There is no published firmware for the requested variant',
                ['variant' => $targetVariant]
            );
        }

        if ($this->operations->findActiveForDevice($device->id) !== null) {
            throw new ConflictException(
                'firmware_operation_active',
                'This device already has an active firmware operation'
            );
        }

        $id = $this->operations->create([
            'device_id'    => $device->id,
            'external_id'  => $device->externalId,
            'release_id'   => $release['id'],
            'requested_by' => $actor,
            'from_variant' => $fromVariant,
            'from_version' => $device->firmwareVersion,
            'to_variant'   => $targetVariant,
            'to_version'   => (string) $release['version'],
            'ttl_minutes'  => 1440,
        ]);

        return $this->decorate($this->operations->findById($id));
    }

    /** @return list<array<string,mixed>> */
    public function listForDevice(int $deviceId): array
    {
        $this->operations->expireStale();
        return array_map([$this, 'decorate'], $this->operations->listForDevice($deviceId));
    }

    /** @return array<string,mixed>|null */
    public function findOperation(int $id): ?array
    {
        $op = $this->operations->findById($id);
        return $op === null ? null : $this->decorate($op);
    }

    /** @return array<string,mixed>|null */
    public function cancel(int $id): ?array
    {
        if ($this->operations->findById($id) === null) {
            throw new NotFoundException('Firmware operation not found', ['operation_id' => $id]);
        }
        if (!$this->operations->cancel($id)) {
            throw new ConflictException(
                'firmware_operation_not_cancellable',
                'Only a waiting operation can be cancelled'
            );
        }
        return $this->findOperation($id);
    }

    /**
     * Device-facing manifest. The device is authenticated and bound to
     * `$externalId` by the route middleware before reaching this method.
     *
     * @return array<string,mixed>
     */
    public function manifestFor(string $externalId): array
    {
        $this->operations->expireStale();
        $device = $this->devices->findByKindAndExternalId(Device::KIND_RPI, $externalId);
        if ($device === null) {
            throw new NotFoundException('Device not found');
        }
        $op = $this->operations->findNextForExternal($externalId);
        if ($op === null) {
            return ['ok' => true, 'operation' => null];
        }
        $release = $this->releases->findById($op['release_id']);
        if ($release === null) {
            throw new NotFoundException('Firmware release not found');
        }
        $this->operations->markAccepted($op['id']);

        return [
            'ok' => true,
            'operation' => [
                'id'            => $op['id'],
                'status'        => FirmwareOperationState::PREPARING,
                'variant'       => $release['variant'],
                'version'       => $release['version'],
                'protocol'      => $release['protocol'],
                'board'         => $release['board'],
                'partition_scheme' => $release['partition_scheme'],
                'sha256'        => $release['sha256'],
                'size_bytes'    => $release['size_bytes'],
                'signature'     => $release['signature'],
                'download_path' => '/dashboard-api/firmware-download?operation_id=' . $op['id'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function binaryFor(string $externalId, int $operationId): array
    {
        $op = $this->operations->findById($operationId);
        if ($op === null || !hash_equals((string) $op['external_id'], $externalId)) {
            throw new ForbiddenException('firmware_operation_mismatch', 'Operation does not belong to this device');
        }
        $release = $this->releases->findById($op['release_id']);
        if ($release === null) {
            throw new NotFoundException('Firmware release not found');
        }
        $path = $this->storageDir . '/' . basename((string) $release['binary_path']);
        if (!is_file($path)) {
            throw new NotFoundException('Firmware binary not available');
        }
        $data = file_get_contents($path);
        if ($data === false) {
            throw new NotFoundException('Firmware binary not readable');
        }
        if (hash('sha256', $data) !== (string) $release['sha256']) {
            throw new ConflictException('firmware_binary_corrupt', 'Stored firmware does not match its published hash');
        }
        return ['filename' => basename($path), 'body' => $data, 'sha256' => $release['sha256']];
    }

    /**
     * Process a device progress/failure report.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function report(string $externalId, array $body): array
    {
        $opId = isset($body['operation_id']) ? (int) $body['operation_id'] : 0;
        $status = strtoupper(trim((string) ($body['status'] ?? '')));
        $progress = isset($body['progress_pct']) ? (int) $body['progress_pct'] : 0;
        $errorCode = isset($body['error_code']) ? substr(trim((string) $body['error_code']), 0, 48) : null;
        $errorMessage = isset($body['error_message']) ? substr(trim((string) $body['error_message']), 0, 255) : null;

        $op = $this->operations->findById($opId);
        if ($op === null || !hash_equals((string) $op['external_id'], $externalId)) {
            throw new ForbiddenException('firmware_operation_mismatch', 'Operation does not belong to this device');
        }
        if (!FirmwareOperationState::isValid($status)) {
            throw new UnprocessableException('invalid_firmware_status', 'Unknown firmware operation status');
        }
        if ($status === FirmwareOperationState::COMPLETED) {
            // Completion must use the confirmation endpoint, which verifies the
            // actually running variant/version after reboot.
            throw new UnprocessableException(
                'firmware_completion_requires_confirmation',
                'COMPLETED is only accepted through /dashboard-api/firmware-confirm'
            );
        }
        if (!FirmwareOperationState::canTransition((string) $op['status'], $status)
            && $status !== FirmwareOperationState::UNCONFIRMED
        ) {
            throw new ConflictException(
                'firmware_invalid_transition',
                'Invalid firmware operation transition',
                ['from' => $op['status'], 'to' => $status]
            );
        }

        $terminal = in_array($status, [
            FirmwareOperationState::FAILED,
            FirmwareOperationState::ROLLED_BACK,
            FirmwareOperationState::CANCELLED,
            FirmwareOperationState::EXPIRED,
        ], true);
        $this->operations->updateStatus(
            $opId,
            $status,
            FirmwareOperationState::clampProgress($status, $progress),
            $errorCode,
            $errorMessage,
            $terminal
        );
        return ['ok' => true, 'operation' => $this->findOperation($opId)];
    }

    /**
     * Post-reboot confirmation. Only this method promotes the requested
     * variant to the effective `devices.subtype`.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function confirm(string $externalId, array $body): array
    {
        $opId = isset($body['operation_id']) ? (int) $body['operation_id'] : 0;
        $ok = !empty($body['ok']);
        $variant = isset($body['variant']) ? Device::normalizeEspVariant((string) $body['variant']) : null;
        $version = isset($body['version']) ? trim((string) $body['version']) : null;
        $errorCode = isset($body['error_code']) ? substr(trim((string) $body['error_code']), 0, 48) : null;

        $op = $this->operations->findById($opId);
        if ($op === null || !hash_equals((string) $op['external_id'], $externalId)) {
            throw new ForbiddenException('firmware_operation_mismatch', 'Operation does not belong to this device');
        }
        $device = $this->devices->findByKindAndExternalId(Device::KIND_RPI, $externalId);
        if ($device === null) {
            throw new NotFoundException('Device not found');
        }
        // Idempotent: a repeated confirmation of an already completed
        // operation never changes the effective variant.
        if ($op['status'] === FirmwareOperationState::COMPLETED) {
            return ['ok' => true, 'operation' => $this->findOperation($opId)];
        }
        if (!$ok && !in_array($op['status'], [
            FirmwareOperationState::VERIFYING,
            FirmwareOperationState::REBOOTING,
            FirmwareOperationState::VALIDATING,
            FirmwareOperationState::UNCONFIRMED,
        ], true)) {
            throw new ConflictException(
                'firmware_invalid_transition',
                'Rollback can only confirm an operation that was rebooting or validating',
                ['from' => $op['status']]
            );
        }

        $this->pdo->beginTransaction();
        try {
            if ($ok) {
                $matches = $variant === $op['to_variant'] && $version === $op['to_version'];
                if (!$matches) {
                    $this->operations->updateStatus(
                        $opId,
                        FirmwareOperationState::FAILED,
                        0,
                        'firmware_confirmation_mismatch',
                        'Device reported a different variant/version than requested',
                        true
                    );
                    $this->pdo->commit();
                    return ['ok' => false, 'operation' => $this->findOperation($opId)];
                }
                // Effective variant changes only now, with proof from the device.
                $this->devices->update($device->id, ['subtype' => $variant]);
                $this->devices->updateFirmwareInfo($device->id, $variant, $version);
                $this->operations->updateStatus($opId, FirmwareOperationState::COMPLETED, 100, null, null, true);
                $result = ['ok' => true, 'operation' => $this->findOperation($opId)];
            } else {
                // A failed boot keeps the previous effective variant untouched.
                $this->operations->updateStatus(
                    $opId,
                    FirmwareOperationState::ROLLED_BACK,
                    0,
                    $errorCode ?? 'firmware_rollback',
                    'Device confirmed rollback to the previous firmware',
                    true
                );
                $result = ['ok' => true, 'operation' => $this->findOperation($opId)];
            }
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string,mixed>|null $op
     * @return array<string,mixed>|null
     */
    private function decorate(?array $op): ?array
    {
        if ($op === null) {
            return null;
        }
        $release = $this->releases->findById((int) $op['release_id']);
        $op['release'] = $release === null ? null : [
            'id'        => $release['id'],
            'variant'   => $release['variant'],
            'version'   => $release['version'],
            'sha256'    => $release['sha256'],
            'size_bytes'=> $release['size_bytes'],
        ];
        $op['status_label'] = FirmwareOperationState::publicLabel((string) $op['status']);
        return $op;
    }
}
