<?php
declare(strict_types=1);

namespace App\Domain\Locks;

use PDO;

/**
 * AccessEventRepository: PDO persistence for access_events.
 */
final class AccessEventRepository implements AccessEventRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<string,mixed>|null $meta
     */
    public function insert(
        int     $roomId,
        ?int    $stayId,
        string  $kind,
        string  $result,
        ?string $reason,
        string  $provider,
        string  $correlationId,
        ?array  $meta,
        ?int    $workerSessionId = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO access_events
                (room_id, stay_id, worker_session_id, kind, result, reason, provider, correlation_id, meta_json)
             VALUES
                (:room, :stay, :wsid, :kind, :res, :reason, :prov, :corr, :meta)'
        );
        $stmt->execute([
            ':room'   => $roomId,
            ':stay'   => $stayId,
            ':wsid'   => $workerSessionId,
            ':kind'   => $kind,
            ':res'    => $result,
            ':reason' => $reason,
            ':prov'   => $provider,
            ':corr'   => $correlationId,
            ':meta'   => $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
