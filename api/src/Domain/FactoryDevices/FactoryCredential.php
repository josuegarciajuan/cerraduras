<?php
declare(strict_types=1);

namespace App\Domain\FactoryDevices;

final class FactoryCredential
{
    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    public static function matches(string $key, string $hash): bool
    {
        return hash_equals($hash, self::hash($key));
    }

    public static function validKey(string $key): bool
    {
        return strlen($key) >= 32 && strlen($key) <= 128;
    }

    public static function validChipId(string $chipId): bool
    {
        return preg_match('/^[0-9a-f]{12}$/', $chipId) === 1;
    }
}
