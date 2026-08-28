<?php
declare(strict_types=1);

namespace App\Support;

use App\Http\Request;

/**
 * Trusts forwarded TLS only from explicitly configured reverse proxies.
 * Direct TLS is determined by the PHP server, never by a request header.
 */
final class TransportSecurity
{
    public static function isHttps(Request $request): bool
    {
        if ($request->isTls) {
            return true;
        }

        $forwarded = strtolower(trim((string) $request->header('x-forwarded-proto')));
        if ($forwarded !== 'https' || !self::trustedProxy($request->remoteIp)) {
            return false;
        }

        return true;
    }

    private static function trustedProxy(string $remoteIp): bool
    {
        $configured = Config::get('TRUSTED_PROXY_IPS', '');
        if ($configured === null || $configured === '') {
            return false;
        }
        foreach (explode(',', $configured) as $candidate) {
            if (trim($candidate) === $remoteIp) {
                return true;
            }
        }
        return false;
    }
}
