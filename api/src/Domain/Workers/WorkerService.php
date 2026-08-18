<?php
declare(strict_types=1);

namespace App\Domain\Workers;

use App\Domain\Rooms\RoomRepositoryInterface;
use App\Support\Clock;
use App\Support\Errors\NotFoundException;
use App\Support\Errors\UnprocessableException;
use App\Support\Qr\QrTokenizer;

/**
 * WorkerService: business logic for workers CRUD, QR tokens, and queries.
 *
 * See RF-W1, RF-W4, RF-W7, TSK-W07.
 */
final class WorkerService
{
    private WorkerRepositoryInterface $repo;
    private WorkerRoleRepositoryInterface $roleRepo;
    private WorkerSessionRepositoryInterface $sessionRepo;
    private QrTokenizer $tokenizer;
    private RoomRepositoryInterface $roomRepo;

    public function __construct(
        WorkerRepositoryInterface $repo,
        WorkerRoleRepositoryInterface $roleRepo,
        WorkerSessionRepositoryInterface $sessionRepo,
        QrTokenizer $tokenizer,
        RoomRepositoryInterface $roomRepo
    ) {
        $this->repo        = $repo;
        $this->roleRepo    = $roleRepo;
        $this->sessionRepo = $sessionRepo;
        $this->tokenizer   = $tokenizer;
        $this->roomRepo    = $roomRepo;
    }

    /**
     * Create a worker with auto-generated master QR token.
     *
     * @param array{name:string, role_id:int, notes?:string|null} $data
     * @return array<string,mixed>  includes 'qr_token' (returned only once)
     */
    public function create(array $data): array
    {
        $name   = trim((string) ($data['name'] ?? ''));
        $roleId = (int) ($data['role_id'] ?? 0);
        $notes  = isset($data['notes']) ? trim((string) $data['notes']) : null;

        $this->validateName($name);

        // Verify role exists
        $role = $this->roleRepo->findById($roleId);
        if ($role === null) {
            throw new NotFoundException('Worker role not found', ['role_id' => $roleId]);
        }

        // Generate JTI and insert worker with placeholder hash
        $jti = self::uuidV4();

        $worker = new Worker(0, $name, $roleId, 'placeholder', $jti, true, $notes, '', '');
        $workerId = $this->repo->insert($worker);

        // Now sign the token with the real worker ID
        $now   = time();
        $exp10 = $now + (10 * 365 * 86400); // ~10 years
        $token = $this->tokenizer->issueWorker($workerId, $jti, $now, $exp10);
        $hash  = QrTokenizer::hashForStorage($token);

        // Update hash in DB
        $this->repo->update($workerId, ['qr_token_hash' => $hash]);

        $created = $this->get($workerId);
        $result  = $created->toArray();
        $result['qr_token'] = $token;
        $result['role']     = ['id' => $role->id, 'name' => $role->name];

        unset($result['qr_token_hash'], $result['qr_jti']);
        return $result;
    }

    /**
     * Update worker fields (name, role_id, notes).
     *
     * @param array{name?:string, role_id?:int, notes?:string|null} $data
     * @return array<string,mixed>
     */
    public function update(int $id, array $data): array
    {
        $this->get($id); // throws 404

        $fields = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) ($data['name'] ?? ''));
            $this->validateName($name);
            $fields['name'] = $name;
        }

        if (array_key_exists('role_id', $data)) {
            $roleId = (int) $data['role_id'];
            if ($this->roleRepo->findById($roleId) === null) {
                throw new NotFoundException('Worker role not found', ['role_id' => $roleId]);
            }
            $fields['role_id'] = $roleId;
        }

        if (array_key_exists('notes', $data)) {
            $fields['notes'] = $data['notes'] !== '' && $data['notes'] !== null
                ? trim((string) $data['notes'])
                : null;
        }

        if (!empty($fields)) {
            $this->repo->update($id, $fields);
        }

        return $this->enrichWorker($this->get($id));
    }

    /**
     * Deactivate a worker (soft delete). Revokes QR and closes active sessions.
     */
    public function deactivate(int $id): array
    {
        $worker = $this->get($id);

        // Close any active sessions
        $activeSessions = $this->sessionRepo->findActiveForWorker($id);
        $now            = Clock::nowUtc()->format('Y-m-d H:i:s.v');
        foreach ($activeSessions as $session) {
            $this->sessionRepo->close($session->id, WorkerSession::EXIT_KIND_AUTO, $now);
        }

        $this->repo->softDelete($id);
        return ['id' => $id, 'active' => false, 'qr_revoked' => true];
    }

    /**
     * Regenerate the master QR token (revokes previous one).
     */
    public function regenerateQr(int $id): array
    {
        $worker = $this->get($id);
        if (!$worker->isActive()) {
            throw new UnprocessableException('validation_error', 'Cannot regenerate QR for inactive worker');
        }

        $jti    = self::uuidV4();
        $now    = time();
        $exp10  = $now + (10 * 365 * 86400);
        $token  = $this->tokenizer->issueWorker($id, $jti, $now, $exp10);
        $hash   = QrTokenizer::hashForStorage($token);

        $this->repo->update($id, [
            'qr_jti'        => $jti,
            'qr_token_hash' => $hash,
        ]);

        return [
            'id'       => $id,
            'qr_token' => $token,
            'message'  => 'QR regenerado. El token anterior ha sido revocado.',
        ];
    }

    /**
     * Check if a worker's role grants access to a room type.
     */
    public function canAccessRoomType(int $roleId, int $roomTypeId): bool
    {
        return $this->roleRepo->canAccessRoomType($roleId, $roomTypeId);
    }

    /**
     * Get the room a worker is currently in (active session).
     *
     * @return array{id:int, code:string}|null
     */
    public function getCurrentRoom(int $workerId): ?array
    {
        $sessions = $this->sessionRepo->findActiveForWorker($workerId);
        if (empty($sessions)) {
            return null;
        }
        $room = $this->roomRepo->findById($sessions[0]->roomId);
        return $room !== null
            ? ['id' => $room->id, 'code' => $room->code]
            : null;
    }

    /**
     * Get sessions for a worker with optional filters.
     *
     * @param array{from?:string, to?:string, room_id?:int, limit?:int} $filters
     * @return list<array<string,mixed>>
     */
    public function getSessions(int $workerId, array $filters = []): array
    {
        $sessions = $this->sessionRepo->findForWorker($workerId, $filters);
        return array_map(function (WorkerSession $ws): array {
            $room = $this->roomRepo->findById($ws->roomId);
            $data = $ws->toArray();
            $data['room'] = $room !== null
                ? ['id' => $room->id, 'code' => $room->code]
                : null;
            // Calculate duration_minutes if exited
            $data['duration_minutes'] = null;
            if ($ws->exitedAt !== null) {
                $enterTs = strtotime($ws->enteredAt . ' UTC');
                $exitTs  = strtotime($ws->exitedAt . ' UTC');
                if ($enterTs !== false && $exitTs !== false) {
                    $data['duration_minutes'] = (int) round(($exitTs - $enterTs) / 60);
                }
            }
            return $data;
        }, $sessions);
    }

    /**
     * Get all workers currently inside rooms.
     *
     * @return list<array<string,mixed>>
     */
    public function getWorkersInside(): array
    {
        $rows = $this->sessionRepo->findWorkersInside();
        return array_map(function (array $row): array {
            /** @var WorkerSession $ws */
            $ws = $row['worker_session'];
            /** @var Worker $worker */
            $worker = $row['worker'];

            $enterTs = strtotime($ws->enteredAt . ' UTC');
            $durMin  = $enterTs !== false
                ? (int) round((time() - $enterTs) / 60)
                : 0;

            return [
                'worker' => [
                    'id'   => $worker->id,
                    'name' => $worker->name,
                    'role' => $this->resolveRoleName($worker->roleId),
                ],
                'room' => [
                    'id'   => $row['room_id'],
                    'code' => $row['room_code'],
                ],
                'entered_at'       => $ws->enteredAt,
                'duration_minutes' => $durMin,
            ];
        }, $rows);
    }

    /**
     * Get stats for a worker: sessions today, avg duration per room_type.
     *
     * @return array<string,mixed>
     */
    public function getStats(int $workerId): array
    {
        $todayStart = gmdate('Y-m-d 00:00:00');

        // All sessions today
        $todaySessions = $this->sessionRepo->findForWorker($workerId, [
            'from' => $todayStart,
            'limit' => 200,
        ]);

        $roomsVisited         = [];
        $totalMinutesToday    = 0;
        $byRoomType           = []; // room_id => [total_min, count]

        foreach ($todaySessions as $ws) {
            $roomsVisited[$ws->roomId] = true;
            if ($ws->exitedAt !== null) {
                $enterTs = strtotime($ws->enteredAt . ' UTC');
                $exitTs  = strtotime($ws->exitedAt . ' UTC');
                if ($enterTs !== false && $exitTs !== false) {
                    $mins = (int) round(($exitTs - $enterTs) / 60);
                    $totalMinutesToday += $mins;
                    if (!isset($byRoomType[$ws->roomId])) {
                        $byRoomType[$ws->roomId] = ['total_min' => 0, 'count' => 0];
                    }
                    $byRoomType[$ws->roomId]['total_min'] += $mins;
                    $byRoomType[$ws->roomId]['count']++;
                }
            }
        }

        // Avg per room
        $avgByRoom = [];
        foreach ($byRoomType as $roomId => $data) {
            $room = $this->roomRepo->findById($roomId);
            $avgByRoom[] = [
                'room_id'   => $roomId,
                'room_code' => $room !== null ? $room->code : (string) $roomId,
                'avg_minutes' => $data['count'] > 0
                    ? (int) round($data['total_min'] / $data['count'])
                    : 0,
                'total_minutes' => $data['total_min'],
                'sessions' => $data['count'],
            ];
        }

        return [
            'rooms_visited_today'  => count($roomsVisited),
            'total_minutes_today'  => $totalMinutesToday,
            'avg_by_room'          => $avgByRoom,
            'current_room'         => $this->getCurrentRoom($workerId),
        ];
    }

    /**
     * List workers with optional filters, enriched with role info.
     *
     * @param array{role_id?:int, active?:bool} $filters
     * @return list<array<string,mixed>>
     */
    public function list(array $filters = []): array
    {
        $workers = $this->repo->findAll($filters);
        $today   = gmdate('Y-m-d');

        return array_map(function (Worker $w) use ($today): array {
            $data = $this->enrichWorker($w);
            // Count sessions today
            $todaySessions = $this->sessionRepo->findForWorker($w->id, [
                'from' => $today . ' 00:00:00',
                'limit' => 200,
            ]);
            $data['sessions_today'] = count($todaySessions);

            // Last access
            $lastSession = !empty($todaySessions) ? $todaySessions[0] : null;
            $data['last_access_at'] = $lastSession !== null ? $lastSession->enteredAt : null;

            return $data;
        }, $workers);
    }

    /**
     * Get a single worker enriched.
     */
    public function get(int $id): Worker
    {
        $worker = $this->repo->findById($id);
        if ($worker === null) {
            throw new NotFoundException('Worker not found', ['worker_id' => $id]);
        }
        return $worker;
    }

    /**
     * Get a single worker with all enrichment.
     */
    public function show(int $id): array
    {
        $worker = $this->get($id);
        $data   = $this->enrichWorker($worker);
        $data['current_room'] = $this->getCurrentRoom($id);
        return $data;
    }

    // -- private helpers -------------------------------------------------------

    /**
     * Enrich a Worker entity with role info, current room, and hide sensitive fields.
     *
     * @return array<string,mixed>
     */
    private function enrichWorker(Worker $worker): array
    {
        $role = $this->roleRepo->findById($worker->roleId);
        $data = $worker->toArray();
        $data['role'] = $role !== null
            ? ['id' => $role->id, 'name' => $role->name]
            : null;
        $data['current_room'] = $this->getCurrentRoom($worker->id);
        unset($data['qr_token_hash'], $data['qr_jti']);
        return $data;
    }

    private function resolveRoleName(int $roleId): string
    {
        $role = $this->roleRepo->findById($roleId);
        return $role !== null ? $role->name : 'Unknown';
    }

    private function validateName(string $name): void
    {
        if ($name === '') {
            throw new UnprocessableException('validation_error', 'name is required');
        }
        if (mb_strlen($name) > 100) {
            throw new UnprocessableException('validation_error', 'name must be <= 100 chars');
        }
    }

    /**
     * Generate a UUID v4 string.
     */
    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        // Set version to 0100 (UUID v4)
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        // Set variant to 10xx
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6))
        );
    }
}
