<?php
declare(strict_types=1);

namespace App\Domain\AccessKeys;

use App\Domain\Devices\DeviceRepositoryInterface;
use App\Domain\Locks\AccessEvent;
use App\Domain\Locks\AccessEventRepositoryInterface;
use App\Domain\Rooms\RoomRepositoryInterface;
use App\Domain\Rooms\RoomTypeRepositoryInterface;
use App\Domain\Warehouse\WarehouseRecordingDecision;
use App\Domain\Warehouse\WarehouseRecordingServiceInterface;
use App\Domain\Workers\WorkerRepositoryInterface;
use App\Domain\Workers\WorkerRoleRepositoryInterface;
use App\Domain\Workers\WorkerSession;
use App\Domain\Workers\WorkerSessionRepositoryInterface;
use App\Infrastructure\Gateways\Lock\LockGatewayFactory;
use App\Support\Clock;
use App\Support\Errors\ConflictException;
use App\Support\Errors\ForbiddenException;
use App\Support\Errors\NotFoundException;
use App\Support\Errors\UnprocessableException;
use App\Support\Qr\QrTokenizer;
use PDO;

/**
 * AccessKeyService: key lifecycle and access decisions (F96).
 *
 * Design rules:
 *   - A WORKER key identifies the worker in the audit trail.
 *   - A ROLE key is shared/anonymous: the audit records the key and role, never
 *     a worker, and no worker session is opened.
 *   - Permissions are a snapshot at creation time (copied from the owner's role
 *     unless explicitly provided) and can be customised afterwards.
 *   - The DB row is authoritative over the signed token: revocation and expiry
 *     are checked on every scan.
 */
final class AccessKeyService
{
    public function __construct(
        private AccessKeyRepository $keys,
        private NfcCardRepository $cards,
        private WorkerRepositoryInterface $workers,
        private WorkerRoleRepositoryInterface $roles,
        private WorkerSessionRepositoryInterface $sessions,
        private RoomRepositoryInterface $rooms,
        private RoomTypeRepositoryInterface $roomTypes,
        private DeviceRepositoryInterface $devices,
        private AccessEventRepositoryInterface $accessEvents,
        private QrTokenizer $tokenizer,
        private PDO $pdo,
        private ?WarehouseRecordingServiceInterface $warehouseRecorder = null
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function listKeys(array $filters = []): array
    {
        return array_map(fn(array $k): array => $this->publicKey($k), $this->keys->findAll($filters));
    }

    public function getKey(int $id): array
    {
        $key = $this->keys->findById($id);
        if ($key === null) {
            throw new NotFoundException('Access key not found', ['key_id' => $id]);
        }
        return $this->publicKey($key);
    }

    /**
     * Never expose token material or the JTI through the API.
     *
     * @param array<string,mixed> $key
     * @return array<string,mixed>
     */
    private function publicKey(array $key): array
    {
        unset($key['token_hash'], $key['token_jti']);
        return $key;
    }

    /**
     * Initial permissions for the form: the owner's role room types.
     *
     * @return array{room_type_ids:list<int>, source:string}
     */
    public function defaults(?int $workerId, ?int $roleId): array
    {
        if ($workerId !== null && $workerId > 0) {
            $worker = $this->workers->findById($workerId);
            if ($worker === null) {
                throw new NotFoundException('Worker not found', ['worker_id' => $workerId]);
            }
            return [
                'room_type_ids' => $this->keys->getRoleRoomTypes($worker->roleId),
                'source'        => 'worker_role',
                'role_id'       => $worker->roleId,
            ];
        }
        if ($roleId !== null && $roleId > 0) {
            if ($this->roles->findById($roleId) === null) {
                throw new NotFoundException('Worker role not found', ['role_id' => $roleId]);
            }
            return [
                'room_type_ids' => $this->keys->getRoleRoomTypes($roleId),
                'source'        => 'role',
                'role_id'       => $roleId,
            ];
        }
        throw new UnprocessableException('validation_error', 'worker_id or role_id is required');
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed> key row plus qr_token when key_type=QR
     */
    public function create(array $data): array
    {
        $label     = trim((string) ($data['label'] ?? ''));
        $ownerType = strtoupper((string) ($data['owner_type'] ?? ''));
        $keyType   = strtoupper((string) ($data['key_type'] ?? ''));
        $allRt     = !empty($data['all_room_types']);
        $notes     = isset($data['notes']) && trim((string) $data['notes']) !== ''
            ? trim((string) $data['notes']) : null;

        if ($label === '') {
            throw new UnprocessableException('validation_error', 'label is required');
        }
        if (mb_strlen($label) > 120) {
            throw new UnprocessableException('validation_error', 'label must be <= 120 chars');
        }
        if (!in_array($ownerType, ['WORKER', 'ROLE'], true)) {
            throw new UnprocessableException('validation_error', 'owner_type must be WORKER or ROLE');
        }
        if (!in_array($keyType, ['QR', 'NFC'], true)) {
            throw new UnprocessableException('validation_error', 'key_type must be QR or NFC');
        }

        $workerId = null;
        $roleId   = null;
        if ($ownerType === 'WORKER') {
            $workerId = (int) ($data['worker_id'] ?? 0);
            $worker = $this->workers->findById($workerId);
            if ($worker === null) {
                throw new NotFoundException('Worker not found', ['worker_id' => $workerId]);
            }
            if (!$worker->isActive()) {
                throw new UnprocessableException('validation_error', 'Worker is inactive');
            }
        } else {
            $roleId = (int) ($data['role_id'] ?? 0);
            if ($this->roles->findById($roleId) === null) {
                throw new NotFoundException('Worker role not found', ['role_id' => $roleId]);
            }
        }

        $validUntil = $this->normaliseValidUntil((string) ($data['valid_until'] ?? ''));
        if (strtotime($validUntil . ' UTC') === false) {
            throw new UnprocessableException('validation_error', 'valid_until is invalid');
        }
        if (strtotime($validUntil . ' UTC') < time()) {
            throw new UnprocessableException('validation_error', 'valid_until must be in the future');
        }

        // Default permissions: snapshot of the owner's role unless provided.
        $roomTypeIds = $this->normaliseRoomTypeIds($data['room_type_ids'] ?? null);
        if (!$allRt && $roomTypeIds === []) {
            $defaults = $this->defaults($ownerType === 'WORKER' ? $workerId : null, $roleId);
            $roomTypeIds = $defaults['room_type_ids'];
        }
        $this->assertRoomTypesExist($roomTypeIds);
        if (!$allRt && $roomTypeIds === []) {
            throw new UnprocessableException(
                'validation_error',
                'Select at least one room type or enable all_room_types'
            );
        }

        $token = null;
        $tokenHash = null;
        $jti = null;
        $iat = time();
        $exp = (int) strtotime($validUntil . ' UTC');
        if ($keyType === 'QR') {
            $jti = self::uuidV4();
            // The token needs the key id, which does not exist yet. Insert first
            // inside the transaction with a temporary unique jti, then sign and
            // persist the definitive values.
            $token = null;
        } else {
            $cardId = (int) ($data['nfc_card_id'] ?? 0);
            $card = $this->cards->findById($cardId);
            if ($card === null) {
                throw new NotFoundException('NFC card not found', ['nfc_card_id' => $cardId]);
            }
            if ($card['status'] !== 'AVAILABLE') {
                throw new ConflictException(
                    'nfc_card_unavailable',
                    'NFC card is not available',
                    ['nfc_card_id' => $cardId, 'status' => $card['status']]
                );
            }
        }

        $this->pdo->beginTransaction();
        try {
            $keyId = $this->keys->insert([
                'label'         => $label,
                'owner_type'    => $ownerType,
                'worker_id'     => $workerId,
                'role_id'       => $roleId,
                'key_type'      => $keyType,
                'token_hash'    => $keyType === 'QR' ? 'pending-' . self::uuidV4() : null,
                'token_jti'     => $keyType === 'QR' ? $jti : null,
                'all_room_types'=> $allRt,
                'nfc_card_id'   => $keyType === 'NFC' ? (int) $data['nfc_card_id'] : null,
                'valid_until'   => $validUntil,
                'notes'         => $notes,
            ]);

            if ($keyType === 'QR') {
                $token = $this->tokenizer->issueKey($keyId, (string) $jti, $iat, $exp);
                $tokenHash = QrTokenizer::hashForStorage($token);
                // Dedicated statement: token columns are intentionally not
                // part of the generic update() allow-list.
                $this->pdo->prepare(
                    'UPDATE access_keys SET token_hash = :h, token_jti = :j WHERE id = :id'
                )->execute([':h' => $tokenHash, ':j' => $jti, ':id' => $keyId]);
            } else {
                if (!$this->cards->assignToKey((int) $data['nfc_card_id'], $keyId)) {
                    throw new ConflictException('nfc_card_unavailable', 'NFC card was assigned concurrently');
                }
            }

            if (!$allRt) {
                $this->keys->setRoomTypes($keyId, $roomTypeIds);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $created = $this->getKey($keyId);
        if ($token !== null) {
            $created['qr_token'] = $token;
        }
        return $created;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): array
    {
        $key = $this->getKey($id);
        if ($key['status'] !== 'ACTIVE') {
            throw new UnprocessableException('validation_error', 'Revoked keys cannot be edited');
        }

        $fields = [];
        if (array_key_exists('label', $data)) {
            $label = trim((string) $data['label']);
            if ($label === '') {
                throw new UnprocessableException('validation_error', 'label is required');
            }
            $fields['label'] = $label;
        }
        if (array_key_exists('valid_until', $data)) {
            $validUntil = $this->normaliseValidUntil((string) $data['valid_until']);
            if (strtotime($validUntil . ' UTC') < time()) {
                throw new UnprocessableException('validation_error', 'valid_until must be in the future');
            }
            $fields['valid_until'] = $validUntil;
        }
        if (array_key_exists('notes', $data)) {
            $fields['notes'] = trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null;
        }

        $allRt = array_key_exists('all_room_types', $data)
            ? !empty($data['all_room_types'])
            : (bool) $key['all_room_types'];

        $roomTypeIds = null;
        if (array_key_exists('room_type_ids', $data)) {
            $roomTypeIds = $this->normaliseRoomTypeIds($data['room_type_ids']);
            $this->assertRoomTypesExist($roomTypeIds);
        }
        if (!$allRt && $roomTypeIds === null && empty($key['room_type_ids'])) {
            throw new UnprocessableException(
                'validation_error',
                'Select at least one room type or enable all_room_types'
            );
        }

        $newCardId = null;
        if ($key['key_type'] === 'NFC' && array_key_exists('nfc_card_id', $data)) {
            $newCardId = (int) $data['nfc_card_id'];
            if ($newCardId !== (int) ($key['nfc_card_id'] ?? 0)) {
                $card = $this->cards->findById($newCardId);
                if ($card === null || $card['status'] !== 'AVAILABLE') {
                    throw new ConflictException('nfc_card_unavailable', 'NFC card is not available');
                }
                $fields['nfc_card_id'] = $newCardId;
            }
        }

        $this->pdo->beginTransaction();
        try {
            if (array_key_exists('all_room_types', $data)) {
                $fields['all_room_types'] = $allRt;
            }
            if ($fields !== []) {
                $this->keys->update($id, $fields);
            }
            if ($allRt) {
                $this->keys->setRoomTypes($id, []);
            } elseif ($roomTypeIds !== null) {
                $this->keys->setRoomTypes($id, $roomTypeIds);
            }
            if ($newCardId !== null) {
                $this->cards->releaseFromKey($id);
                if (!$this->cards->assignToKey($newCardId, $id)) {
                    throw new ConflictException('nfc_card_unavailable', 'NFC card was assigned concurrently');
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->getKey($id);
    }

    public function revoke(int $id): array
    {
        $this->getKey($id);
        $this->pdo->beginTransaction();
        try {
            $this->keys->revoke($id);
            $this->cards->releaseFromKey($id);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $this->getKey($id);
    }

    /**
     * Reactivate a deactivated key (F99).
     *
     * The credential is preserved: a QR key keeps its token and an NFC key
     * recovers its physical card. Only keys that are still valid for their
     * owner can be reactivated.
     */
    public function reactivate(int $id): array
    {
        $key = $this->getKey($id);

        $workerActive = null;
        if ($key['owner_type'] === 'WORKER') {
            $worker = $this->workers->findById((int) $key['worker_id']);
            $workerActive = $worker !== null && $worker->isActive();
        }

        $card = null;
        if ($key['key_type'] === 'NFC' && $key['nfc_card_id'] !== null) {
            $card = $this->cards->findById((int) $key['nfc_card_id']);
        }

        $verdict = AccessKeyReactivationPolicy::evaluate($key, $workerActive, $card, time());
        if (!$verdict['ok']) {
            if ($verdict['code'] === 'nfc_card_unavailable') {
                throw new ConflictException($verdict['code'], (string) $verdict['message'], ['key_id' => $id]);
            }
            throw new UnprocessableException($verdict['code'] ?? 'validation_error', (string) $verdict['message']);
        }

        $this->pdo->beginTransaction();
        try {
            if ($this->keys->reactivate($id) !== 1) {
                throw new UnprocessableException('not_revoked', 'Only deactivated keys can be reactivated');
            }
            if ($key['key_type'] === 'NFC') {
                // Release is idempotent; assign fails if another key took the card.
                $this->cards->releaseFromKey($id);
                if (!$this->cards->assignToKey((int) $key['nfc_card_id'], $id)) {
                    throw new ConflictException(
                        'nfc_card_unavailable',
                        'NFC card was assigned to another key',
                        ['key_id' => $id]
                    );
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->getKey($id);
    }

    /**
     * Rotate the QR credential for an existing QR key.
     *
     * @return array<string,mixed> key plus qr_token
     */
    public function regenerateQr(int $id): array
    {
        $key = $this->getKey($id);
        if ($key['key_type'] !== 'QR') {
            throw new UnprocessableException('validation_error', 'Only QR keys have a QR credential');
        }
        if ($key['status'] !== 'ACTIVE' || $key['effective_status'] === 'EXPIRED') {
            throw new UnprocessableException('validation_error', 'Only active, unexpired keys can be regenerated');
        }

        $jti = self::uuidV4();
        $iat = time();
        $exp = (int) strtotime((string) $key['valid_until'] . ' UTC');
        $token = $this->tokenizer->issueKey($id, $jti, $iat, $exp);
        $hash = QrTokenizer::hashForStorage($token);

        $this->pdo->prepare(
            'UPDATE access_keys SET token_hash = :h, token_jti = :j WHERE id = :id'
        )->execute([':h' => $hash, ':j' => $jti, ':id' => $id]);

        $fresh = $this->getKey($id);
        $fresh['qr_token'] = $token;
        return $fresh;
    }

    /** @return list<array<string,mixed>> */
    public function accessEvents(array $filters = []): array
    {
        return $this->keys->findAccessEvents($filters);
    }

    /**
     * Validate a key QR token and open the door.
     *
     * @return array<string,mixed>
     */
    public function validateQrToken(
        string $token,
        ?int $roomId,
        string $deviceId,
        string $provider,
        string $correlationId
    ): array {
        try {
            $claims = $this->tokenizer->parse($token);
        } catch (\Throwable $e) {
            $this->recordAccess([
                'room_id' => $roomId,
                'device_id' => $deviceId,
                'credential_type' => 'QR',
                'result' => 'FAIL',
                'reason' => 'invalid_token',
                'provider' => $this->normaliseProvider($provider),
                'correlation_id' => $correlationId,
            ]);
            throw $e;
        }

        if (($claims['sub'] ?? '') !== 'key') {
            throw new ForbiddenException('qr_invalid_signature', 'Token is not an access key QR');
        }

        $key = $this->keys->findByJti((string) $claims['jti']);
        if ($key === null || (int) $key['id'] !== (int) $claims['kid']) {
            $this->recordAccess([
                'key_id' => $key['id'] ?? null,
                'room_id' => $roomId,
                'device_id' => $deviceId,
                'credential_type' => 'QR',
                'result' => 'FAIL',
                'reason' => 'unknown_key',
                'provider' => $this->normaliseProvider($provider),
                'correlation_id' => $correlationId,
            ]);
            throw new ForbiddenException('qr_invalid_signature', 'Access key not found');
        }

        if (!hash_equals((string) ($key['token_hash'] ?? ''), QrTokenizer::hashForStorage($token))) {
            return $this->deny($key, $roomId, $deviceId, 'QR', 'key_revoked', $provider, $correlationId, 'Key credential was rotated');
        }

        return $this->authorise($key, $roomId, $deviceId, 'QR', $provider, $correlationId);
    }

    /**
     * Validate a physical NFC card code. Kept for the reader-integration work;
     * the current readers do not send NFC credentials.
     *
     * @return array<string,mixed>
     */
    public function validateFicha(
        string $code,
        ?int $roomId,
        string $deviceId,
        string $provider,
        string $correlationId
    ): array {
        $card = $this->cards->findByCodeHash(hash('sha256', trim($code)));
        if ($card === null) {
            $this->recordAccess([
                'room_id' => $roomId,
                'device_id' => $deviceId,
                'credential_type' => 'NFC',
                'result' => 'FAIL',
                'reason' => 'unknown_ficha',
                'provider' => $this->normaliseProvider($provider),
                'correlation_id' => $correlationId,
            ]);
            throw new ForbiddenException('ficha_invalid', 'NFC card not found');
        }
        if ($card['status'] !== 'ASSIGNED' || $card['current_key_id'] === null) {
            $this->recordAccess([
                'room_id' => $roomId,
                'device_id' => $deviceId,
                'credential_type' => 'NFC',
                'result' => 'FAIL',
                'reason' => 'ficha_unassigned',
                'provider' => $this->normaliseProvider($provider),
                'correlation_id' => $correlationId,
            ]);
            throw new ForbiddenException('ficha_unassigned', 'NFC card has no active key');
        }

        $key = $this->keys->findById((int) $card['current_key_id']);
        if ($key === null || $key['key_type'] !== 'NFC') {
            throw new ForbiddenException('ficha_unassigned', 'NFC card has no active key');
        }

        return $this->authorise($key, $roomId, $deviceId, 'NFC', $provider, $correlationId);
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function authorise(
        array $key,
        ?int $roomId,
        string $deviceId,
        string $credentialType,
        string $provider,
        string $correlationId
    ): array {
        $now = time();

        if ($key['status'] !== 'ACTIVE') {
            return $this->deny($key, $roomId, $deviceId, $credentialType, 'key_revoked', $provider, $correlationId, 'Key has been revoked');
        }
        $validTs = strtotime((string) $key['valid_until'] . ' UTC');
        if ($validTs === false || $validTs < $now) {
            return $this->deny($key, $roomId, $deviceId, $credentialType, 'key_expired', $provider, $correlationId, 'Key has expired');
        }
        if ($key['owner_type'] === 'WORKER') {
            $worker = $this->workers->findById((int) $key['worker_id']);
            if ($worker === null || !$worker->isActive()) {
                return $this->deny($key, $roomId, $deviceId, $credentialType, 'worker_inactive', $provider, $correlationId, 'Worker is inactive');
            }
        }

        $room = $roomId !== null && $roomId > 0 ? $this->rooms->findById($roomId) : null;
        if ($room === null) {
            $device = $this->devices->findByKindAndExternalId('RPI', $deviceId);
            if ($device !== null) {
                $resolved = $this->devices->resolveRoomId($device->id);
                if ($resolved !== null) {
                    $room = $this->rooms->findById($resolved);
                }
            }
        }
        if ($room === null) {
            return $this->deny($key, null, $deviceId, $credentialType, 'room_unresolved', $provider, $correlationId, 'Could not resolve the room for this reader');
        }

        $roomType = $this->roomTypes->findById($room->roomTypeId);
        if (!$this->keys->canAccessRoomType($key, $room->roomTypeId)) {
            return $this->deny($key, $room->id, $deviceId, $credentialType, 'key_room_denied', $provider, $correlationId, 'Key has no permission for this room type');
        }

        $workerSessionId = null;
        if ($key['owner_type'] === 'WORKER') {
            $workerId = (int) $key['worker_id'];
            foreach ($this->sessions->findActiveForWorker($workerId) as $session) {
                if ($session->roomId === $room->id) {
                    throw new ConflictException(
                        'already_inside',
                        'Worker already has an active session in this room',
                        ['worker_id' => $workerId, 'room_id' => $room->id]
                    );
                }
            }
        }

        $gateway = LockGatewayFactory::make($room);
        $openResult = $gateway->open($room->id);
        $gwProvider = $this->normaliseProvider((string) ($openResult['provider'] ?? $provider));

        if ($key['owner_type'] === 'WORKER') {
            $nowStr = Clock::nowUtc()->format('Y-m-d H:i:s.v');
            $workerSessionId = $this->sessions->insert(new WorkerSession(
                0,
                (int) $key['worker_id'],
                $room->id,
                $nowStr,
                null,
                null,
                $correlationId,
                '',
                ''
            ));
        }

        $this->recordAccess([
            'key_id'            => (int) $key['id'],
            'owner_type'        => $key['owner_type'],
            'worker_id'         => $key['worker_id'],
            'role_id'           => $key['role_id'],
            'room_id'           => $room->id,
            'room_type_id'      => $room->roomTypeId,
            'device_id'         => $deviceId,
            'credential_type'   => $credentialType,
            'result'            => 'OK',
            'reason'            => null,
            'provider'          => $gwProvider,
            'correlation_id'    => $correlationId,
            'worker_session_id' => $workerSessionId,
        ]);

        $meta = [
            'device_id' => $deviceId,
            'key_id'    => (int) $key['id'],
            'key_label' => $key['label'],
            'owner_type'=> $key['owner_type'],
        ];
        if ($key['worker_id'] !== null) {
            $meta['worker_id'] = (int) $key['worker_id'];
        }
        if ($key['role_id'] !== null) {
            $meta['role_id'] = (int) $key['role_id'];
        }

        try {
            $this->accessEvents->insert(
                $room->id,
                null,
                AccessEvent::KIND_QR_VALIDATE,
                AccessEvent::RESULT_OK,
                null,
                $gwProvider,
                $correlationId,
                $meta,
                $workerSessionId
            );
            $this->accessEvents->insert(
                $room->id,
                null,
                AccessEvent::KIND_OPEN,
                !empty($openResult['ok']) ? AccessEvent::RESULT_OK : AccessEvent::RESULT_FAIL,
                $openResult['error'] ?? null,
                $gwProvider,
                $correlationId,
                $meta,
                $workerSessionId
            );
        } catch (\Throwable $e) {
            error_log('[AccessKeyService] access_event failed: ' . $e->getMessage());
        }

        if ($this->warehouseRecorder !== null) {
            try {
                $signal = ['key_id' => (int) $key['id']];
                if ($key['worker_id'] !== null) {
                    $signal['worker_id'] = (int) $key['worker_id'];
                    $signal['worker_session_id'] = $workerSessionId;
                }
                $this->warehouseRecorder->onSignal($room->id, WarehouseRecordingDecision::EV_QR_OK, $signal);
            } catch (\Throwable $e) {
                error_log('[AccessKeyService] warehouse recording failed: ' . $e->getMessage());
            }
        }

        return [
            'allow'        => true,
            'key_id'       => (int) $key['id'],
            'key_label'    => $key['label'],
            'owner_type'   => $key['owner_type'],
            'worker_id'    => $key['worker_id'],
            'role_id'      => $key['role_id'],
            'worker_session_id' => $workerSessionId,
            'room_id'      => $room->id,
            'room_type_id' => $room->roomTypeId,
            'room_type'    => $roomType?->name,
            'action'       => 'open',
            'reason'       => null,
        ];
    }

    /** @return array<string,mixed> */
    private function deny(
        array $key,
        ?int $roomId,
        string $deviceId,
        string $credentialType,
        string $reason,
        string $provider,
        string $correlationId,
        string $message
    ): array {
        $provider = $this->normaliseProvider($provider);
        $this->recordAccess([
            'key_id'          => $key['id'] ?? null,
            'owner_type'      => $key['owner_type'] ?? null,
            'worker_id'       => $key['worker_id'] ?? null,
            'role_id'         => $key['role_id'] ?? null,
            'room_id'         => $roomId,
            'device_id'       => $deviceId,
            'credential_type' => $credentialType,
            'result'          => 'FAIL',
            'reason'          => $reason,
            'provider'        => $provider,
            'correlation_id'  => $correlationId,
        ]);

        if ($roomId !== null && $roomId > 0) {
            try {
                $this->accessEvents->insert(
                    $roomId, null,
                    AccessEvent::KIND_DENIED,
                    AccessEvent::RESULT_FAIL,
                    $reason,
                    $provider,
                    $correlationId,
                    [
                        'device_id' => $deviceId,
                        'key_id'    => $key['id'] ?? null,
                        'key_label' => $key['label'] ?? null,
                    ]
                );
            } catch (\Throwable $e) {
                error_log('[AccessKeyService] denied access_event failed: ' . $e->getMessage());
            }
        }

        throw new ForbiddenException($reason, $message, ['key_id' => $key['id'] ?? null]);
    }

    /** @param array<string,mixed> $data */
    private function recordAccess(array $data): void
    {
        try {
            $this->keys->insertAccessEvent([
                'key_id'          => $data['key_id'] ?? null,
                'owner_type'      => $data['owner_type'] ?? null,
                'worker_id'       => $data['worker_id'] ?? null,
                'role_id'         => $data['role_id'] ?? null,
                'room_id'         => $data['room_id'] ?? null,
                'room_type_id'    => $data['room_type_id'] ?? null,
                'device_id'       => $data['device_id'] ?? null,
                'credential_type' => $data['credential_type'] ?? 'QR',
                'result'          => $data['result'] ?? 'FAIL',
                'reason'          => $data['reason'] ?? null,
                'provider'        => $data['provider'] ?? null,
                'correlation_id'  => $data['correlation_id'] ?? null,
                'worker_session_id' => $data['worker_session_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log('[AccessKeyService] key_access_event failed: ' . $e->getMessage());
        }
    }

    private function normaliseProvider(string $provider): string
    {
        return in_array($provider, ['TUYA', 'SIMULATED'], true) ? $provider : 'SIMULATED';
    }

    private function normaliseValidUntil(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new UnprocessableException('validation_error', 'valid_until is required');
        }
        // Accept HTML datetime-local (no timezone) and ISO-8601. Values without
        // an explicit timezone are interpreted as UTC, matching the DB.
        $value = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
            $value .= ':00';
        }
        try {
            $dt = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            throw new UnprocessableException('validation_error', 'valid_until is invalid');
        }
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    /** @return list<int> */
    private function normaliseRoomTypeIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /** @param list<int> $ids */
    private function assertRoomTypesExist(array $ids): void
    {
        foreach ($ids as $id) {
            if ($this->roomTypes->findById($id) === null) {
                throw new UnprocessableException(
                    'validation_error',
                    'Unknown room_type_id',
                    ['room_type_id' => $id]
                );
            }
        }
    }

    private static function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
