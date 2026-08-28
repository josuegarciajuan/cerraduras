<?php
declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * ApiClient: in-memory representation of an api_clients row.
 *
 * Holds the identity and the scopes list. The plaintext API key is NEVER
 * stored in this object; only the hashed version is kept server-side.
 */
final class ApiClient
{
    public int $id;
    public string $code;
    public string $kind;
    /** @var list<string> */
    public array $scopes;
    /** @var list<string>|null null = any IP allowed */
    public ?array $ipWhitelist;
    public bool $active;
    public ?int $deviceId;

    /**
     * @param list<string> $scopes
     * @param list<string>|null $ipWhitelist
     */
    public function __construct(
        int $id,
        string $code,
        string $kind,
        array $scopes,
        ?array $ipWhitelist,
        bool $active,
        ?int $deviceId = null
    ) {
        $this->id = $id;
        $this->code = $code;
        $this->kind = $kind;
        $this->scopes = $scopes;
        $this->ipWhitelist = $ipWhitelist;
        $this->active = $active;
        $this->deviceId = $deviceId;
    }

    /**
     * Returns true if the client has all of the required scopes.
     * Wildcard support: a scope "sim:*" grants any "sim:something".
     *
     * @param list<string> $required
     */
    public function hasAllScopes(array $required): bool
    {
        foreach ($required as $needed) {
            if (!$this->hasScope($needed)) {
                return false;
            }
        }
        return true;
    }

    public function hasScope(string $needed): bool
    {
        foreach ($this->scopes as $granted) {
            if ($granted === $needed) {
                return true;
            }
            // Wildcard: "sim:*" grants "sim:anything".
            if (substr($granted, -2) === ':*') {
                $prefix = substr($granted, 0, -1); // keeps "sim:"
                if (strncmp($needed, $prefix, strlen($prefix)) === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    public function ipAllowed(string $ip): bool
    {
        if ($this->ipWhitelist === null) {
            return true;
        }
        return in_array($ip, $this->ipWhitelist, true);
    }
}
