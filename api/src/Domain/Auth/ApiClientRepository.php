<?php
declare(strict_types=1);

namespace App\Domain\Auth;

use PDO;

/**
 * ApiClientRepository: look up api_clients by api_key_hash.
 *
 * We store SHA-256(hash) of the plaintext key; the middleware hashes the
 * incoming header and queries by hash. Timing attack surface is minimal
 * because the DB index uses fixed-size CHAR(64) comparisons.
 */
final class ApiClientRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findActiveByKeyHash(string $sha256Hex): ?ApiClient
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, code, kind, scopes_csv, ip_whitelist_csv, active
             FROM api_clients
             WHERE api_key_hash = :h AND active = 1
             LIMIT 1'
        );
        $stmt->execute([':h' => $sha256Hex]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return $this->hydrate($row);
    }

    public function findById(int $id): ?ApiClient
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, code, kind, scopes_csv, ip_whitelist_csv, active FROM api_clients WHERE id = :id'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /** @return ApiClient[] */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT id, code, kind, scopes_csv, ip_whitelist_csv, active FROM api_clients ORDER BY code');
        return array_map(fn($r) => $this->hydrate($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function update(int $id, array $fields): void
    {
        $allowed = ['code', 'kind', 'api_key_hash', 'scopes_csv', 'ip_whitelist_csv', 'active'];
        $sets = [];
        $params = [':id' => $id];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $sets[] = "`{$k}` = :{$k}";
            $params[":{$k}"] = $v;
        }
        if (empty($sets)) return;
        $sql = 'UPDATE api_clients SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): ApiClient
    {
        $scopes = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $row['scopes_csv'])
        ), static function ($s) { return $s !== ''; }));

        $whitelist = null;
        $wlRaw = (string) ($row['ip_whitelist_csv'] ?? '');
        if ($wlRaw !== '') {
            $whitelist = array_values(array_filter(array_map('trim', explode(',', $wlRaw)), static function ($s) {
                return $s !== '';
            }));
        }

        return new ApiClient(
            (int) $row['id'],
            (string) $row['code'],
            (string) $row['kind'],
            $scopes,
            $whitelist,
            (bool) $row['active']
        );
    }
}
