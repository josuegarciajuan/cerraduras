<?php
declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Support\Config;

/**
 * DashboardSessionService — F127.
 *
 * Issues and validates a signed, stateless LAN session for /dashboard. The
 * cookie is not a replacement for the CRM session or an API key: it only
 * grants the room-control scopes the public dashboard already exposed.
 *
 * Token format: base64url(payload) . "." . base64url(HMAC-SHA256)
 * Payload: {"v":1,"iat":...,"exp":...,"jti":"..."}
 */
final class DashboardSessionService
{
    public const COOKIE_NAME = 'dashboard_session';
    public const DEFAULT_TTL_SECONDS = 28800;

    private string $secret;
    private int $ttlSeconds;

    public function __construct(?string $secret = null, ?int $ttlSeconds = null)
    {
        $secret = $secret
            ?? Config::get('DASHBOARD_SESSION_SECRET')
            ?? Config::get('QR_SIGNING_SECRET')
            ?? '';
        $this->secret = trim((string) $secret);
        $this->ttlSeconds = $ttlSeconds
            ?? Config::getInt('DASHBOARD_SESSION_TTL_SECONDS', self::DEFAULT_TTL_SECONDS)
            ?? self::DEFAULT_TTL_SECONDS;
        if ($this->ttlSeconds < 60) {
            $this->ttlSeconds = self::DEFAULT_TTL_SECONDS;
        }
    }

    public function enabled(): bool
    {
        return $this->secret !== '';
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    public function cookieName(): string
    {
        return self::COOKIE_NAME;
    }

    /** @return string|null */
    public function issue(?int $now = null): ?string
    {
        if (!$this->enabled()) {
            return null;
        }
        $now = $now ?? time();
        $payload = [
            'v' => 1,
            'iat' => $now,
            'exp' => $now + $this->ttlSeconds,
            'jti' => bin2hex(random_bytes(8)),
        ];
        $encoded = self::b64urlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        return $encoded . '.' . $this->sign($encoded);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function validate(string $token, ?int $now = null): ?array
    {
        if (!$this->enabled() || $token === '') {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$encoded, $signature] = $parts;
        if (!hash_equals($this->sign($encoded), $signature)) {
            return null;
        }
        $raw = self::b64urlDecode($encoded);
        if ($raw === null) {
            return null;
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || ($payload['v'] ?? null) !== 1) {
            return null;
        }
        $now = $now ?? time();
        $iat = (int) ($payload['iat'] ?? 0);
        $exp = (int) ($payload['exp'] ?? 0);
        if ($iat <= 0 || $exp <= $now || $iat > $now + 60) {
            return null;
        }
        return $payload;
    }

    private function sign(string $encoded): string
    {
        return self::b64urlEncode(hash_hmac('sha256', $encoded, $this->secret, true));
    }

    private static function b64urlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $value): ?string
    {
        $pad = strlen($value) % 4;
        if ($pad > 0) {
            $value .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
