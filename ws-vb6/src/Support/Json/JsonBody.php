<?php
declare(strict_types=1);

namespace Ws\Support\Json;

use Ws\Support\Errors\BadRequestException;

/** Input validation helpers for WS-VB6 controllers. */
final class JsonBody
{
    /** @param array<string,mixed>|null $body */
    public static function require(?array $body): array
    {
        if ($body === null) {
            throw new BadRequestException('Request body must be a JSON object');
        }
        return $body;
    }

    /** @param array<string,mixed> $data */
    public static function string(array $data, string $key, ?int $maxLength = null): string
    {
        if (!array_key_exists($key, $data)) {
            throw new BadRequestException("Missing field: {$key}");
        }
        if (!is_string($data[$key])) {
            throw new BadRequestException("Field must be a string: {$key}");
        }
        $v = $data[$key];
        if ($maxLength !== null && mb_strlen($v) > $maxLength) {
            throw new BadRequestException("Field too long: {$key}");
        }
        return $v;
    }

    /** @param array<string,mixed> $data */
    public static function stringOpt(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        return self::string($data, $key);
    }

    /** @param array<string,mixed> $data */
    public static function int(array $data, string $key, ?int $min = null): int
    {
        if (!array_key_exists($key, $data)) {
            throw new BadRequestException("Missing field: {$key}");
        }
        $v = $data[$key];
        if (is_int($v)) {
            $n = $v;
        } elseif (is_string($v) && preg_match('/^-?\d+$/', $v)) {
            $n = (int) $v;
        } else {
            throw new BadRequestException("Field must be an integer: {$key}");
        }
        if ($min !== null && $n < $min) {
            throw new BadRequestException("Field out of range: {$key}");
        }
        return $n;
    }
}
