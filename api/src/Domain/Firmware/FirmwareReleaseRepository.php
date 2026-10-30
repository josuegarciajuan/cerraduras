<?php
declare(strict_types=1);

namespace App\Domain\Firmware;

use PDO;

/**
 * FirmwareReleaseRepository — F100/RF-180.
 *
 * Published firmware images are immutable. A device operation pins a release
 * id, so publishing a newer image never changes an already queued update.
 */
final class FirmwareReleaseRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPublished(?string $variant = null): array
    {
        $sql = "SELECT * FROM firmware_releases WHERE published = 1";
        $params = [];
        if ($variant !== null && $variant !== '') {
            $sql .= " AND variant = :v";
            $params[':v'] = strtoupper($variant);
        }
        $sql .= " ORDER BY published_at DESC, id DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'normalizeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Latest published release for a variant. The panel never compiles code:
     * it can only target an image that was explicitly published.
     *
     * @return array<string,mixed>|null
     */
    public function findLatestPublished(string $variant): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM firmware_releases
              WHERE variant = :v AND published = 1
              ORDER BY published_at DESC, id DESC LIMIT 1"
        );
        $stmt->execute([':v' => strtoupper($variant)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->normalizeRow($row);
    }

    /** @return array<string,mixed>|null */
    public function findByVariantVersion(string $variant, string $version): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM firmware_releases WHERE variant = :v AND version = :ver LIMIT 1"
        );
        $stmt->execute([':v' => strtoupper($variant), ':ver' => $version]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->normalizeRow($row);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM firmware_releases WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->normalizeRow($row);
    }

    /**
     * Register a binary in the catalogue. The caller (publication CLI) has
     * already copied the file into immutable storage and verified its hash.
     *
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO firmware_releases
                (variant, version, build_id, commit_ref, board, partition_scheme,
                 protocol, sha256, size_bytes, signature, binary_path, notes, published, published_at)
             VALUES
                (:variant, :version, :build_id, :commit_ref, :board, :partition_scheme,
                 :protocol, :sha256, :size_bytes, :signature, :binary_path, :notes,
                 :published, :published_at)"
        );
        $published = !empty($data['published']);
        $stmt->execute([
            ':variant'          => strtoupper((string) $data['variant']),
            ':version'          => (string) $data['version'],
            ':build_id'         => $data['build_id'] ?? null,
            ':commit_ref'       => $data['commit_ref'] ?? null,
            ':board'            => $data['board'] ?? 'esp32s3usbotg',
            ':partition_scheme' => $data['partition_scheme'] ?? 'ota-2app',
            ':protocol'         => $data['protocol'] ?? 'ota-v1',
            ':sha256'           => strtolower((string) $data['sha256']),
            ':size_bytes'       => (int) $data['size_bytes'],
            ':signature'        => $data['signature'] ?? null,
            ':binary_path'      => (string) $data['binary_path'],
            ':notes'            => $data['notes'] ?? null,
            ':published'        => $published ? 1 : 0,
            ':published_at'     => $published ? gmdate('Y-m-d H:i:s') : null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function withdraw(int $id): void
    {
        $this->pdo->prepare("UPDATE firmware_releases SET published = 0 WHERE id = :id")
            ->execute([':id' => $id]);
    }

    /** @param array<string,mixed> $row */
    private function normalizeRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['size_bytes'] = (int) $row['size_bytes'];
        $row['published'] = (bool) $row['published'];
        return $row;
    }
}
