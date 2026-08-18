<?php
declare(strict_types=1);

namespace App\Support\Json;

use App\Support\Errors\BadRequestException;

/**
 * JsonBody: small input-validation helpers for controllers.
 *
 * Controllers receive $request->jsonBody (array|null) and need to extract
 * typed fields with clear error messages. Rather than duplicating this code
 * in every controller, we centralise it here and throw BadRequestException
 * on violations so the error handler produces a uniform 400 envelope.
 */
final class JsonBody
{
    /**
     * Ensures the request body is a JSON object (decoded as associative array)
     * and returns it. null/empty bodies are rejected.
     *
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    public static function require(?array $body): array
    {
        if ($body === null) {
            throw new BadRequestException('Request body must be a JSON object');
        }
        return $body;
    }

    /**
     * @param array<string,mixed> $data
     */
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
            throw new BadRequestException("Field too long: {$key}", ['max' => $maxLength]);
        }
        return $v;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function stringOpt(array $data, string $key, ?int $maxLength = null): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        return self::string($data, $key, $maxLength);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function int(array $data, string $key, ?int $min = null, ?int $max = null): int
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
            throw new BadRequestException("Field out of range: {$key}", ['min' => $min]);
        }
        if ($max !== null && $n > $max) {
            throw new BadRequestException("Field out of range: {$key}", ['max' => $max]);
        }
        return $n;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function intOpt(array $data, string $key, ?int $min = null, ?int $max = null): ?int
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        return self::int($data, $key, $min, $max);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function boolOpt(array $data, string $key): ?bool
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        return self::bool($data, $key);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function bool(array $data, string $key): bool
    {
        $v = $data[$key] ?? null;
        if ($v === null) {
            throw new BadRequestException("Missing field: {$key}");
        }
        if (is_bool($v)) {
            return $v;
        }
        if ($v === 0 || $v === '0' || $v === 'false' || $v === 'no') {
            return false;
        }
        if ($v === 1 || $v === '1' || $v === 'true' || $v === 'yes') {
            return true;
        }
        throw new BadRequestException("Field must be a boolean: {$key}");
    }

    /**
     * @param array<string,mixed> $data
     * @return list<mixed>
     */
    public static function list(array $data, string $key): array
    {
        if (!array_key_exists($key, $data)) {
            throw new BadRequestException("Missing field: {$key}");
        }
        $v = $data[$key];
        if (!is_array($v) || (!empty($v) && !array_is_list_compat($v))) {
            throw new BadRequestException("Field must be a JSON array: {$key}");
        }
        return array_values($v);
    }
}

/**
 * PHP 7.4-compatible is-list check. array_is_list() arrived in 8.1.
 *
 * @param array<mixed> $arr
 */
function array_is_list_compat(array $arr): bool
{
    $expected = 0;
    foreach ($arr as $k => $_) {
        if ($k !== $expected++) {
            return false;
        }
    }
    return true;
}
