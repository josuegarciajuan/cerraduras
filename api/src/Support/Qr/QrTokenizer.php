<?php
declare(strict_types=1);

namespace App\Support\Qr;

use App\Support\Errors\ApiException;

/**
 * QrTokenizer: signs and verifies QR access tokens (design.md §5).
 *
 * Wire format:
 *
 *     <base64url(payload_json)>.<base64url(hmac_sha256(secret, payload_json))>.<checksum>
 *
 * Guest payload:
 *     { "v":1, "room_id":int, "stay_id":int, "jti":string, "iat":int, "exp":int }
 *
 * Worker payload (F38):
 *     { "v":1, "sub":"worker", "wid":int, "jti":string, "iat":int, "exp":int }
 *
 * HMAC algorithm: SHA-256. The shared secret comes from QR_SIGNING_SECRET.
 * checksum: 4 Crockford Base32 chars — self-check for OCR errors, NOT security.
 *
 * The parser returns the decoded payload but does NOT enforce expiration.
 * Callers decide what to do with iat/exp.
 */
final class QrTokenizer
{
    public const VERSION = 1;
    private const ALGO = 'sha256';
    private const CHECKSUM_LEN = 4;
    private const BASE32_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private string $secret;

    public function __construct(string $secret)
    {
        if (strlen($secret) < 16) {
            throw new \InvalidArgumentException(
                'QR_SIGNING_SECRET must be at least 16 bytes long'
            );
        }
        $this->secret = $secret;
    }

    /**
     * Issue a guest QR token.
     */
    public function issue(int $roomId, int $stayId, string $jti, int $iatEpoch, int $expEpoch): string
    {
        if ($expEpoch <= $iatEpoch) {
            throw new \InvalidArgumentException('exp must be greater than iat');
        }
        $payload = [
            'v'       => self::VERSION,
            'room_id' => $roomId,
            'stay_id' => $stayId,
            'jti'     => $jti,
            'iat'     => $iatEpoch,
            'exp'     => $expEpoch,
        ];
        return $this->signPayload($payload);
    }

    /**
     * Issue a worker QR token (F38).
     */
    public function issueWorker(int $workerId, string $jti, int $iatEpoch, int $expEpoch): string
    {
        if ($expEpoch <= $iatEpoch) {
            throw new \InvalidArgumentException('exp must be greater than iat');
        }
        $payload = [
            'v'   => self::VERSION,
            'sub' => 'worker',
            'wid' => $workerId,
            'jti' => $jti,
            'iat' => $iatEpoch,
            'exp' => $expEpoch,
        ];
        return $this->signPayload($payload);
    }

    /**
     * Sign a payload array into a token string.
     *
     * @param array<string,mixed> $payload
     */
    public function signPayload(array $payload): string
    {
        $json = (string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $payloadB64 = self::base64UrlEncode($json);
        $sig    = hash_hmac(self::ALGO, $json, $this->secret, true);
        $sigB64 = self::base64UrlEncode($sig);
        $checksum = self::checksum($payloadB64 . '.' . $sigB64);
        return $payloadB64 . '.' . $sigB64 . '.' . $checksum;
    }

    /**
     * Parse and verify the structural integrity of a token. Returns the decoded
     * payload on success.
     *
     * Supports both guest and worker tokens:
     *   - Guest: returns {v, room_id, stay_id, jti, iat, exp}
     *   - Worker: returns {v, sub, wid, jti, iat, exp}
     *
     * On failure, throws an ApiException with code 'qr_invalid_signature'.
     *
     * @return array<string,mixed>
     */
    public function parse(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw $this->invalid('Token must have three dot-separated segments');
        }
        [$payloadB64, $sigB64, $checksum] = $parts;
        if ($payloadB64 === '' || $sigB64 === '' || strlen((string)$checksum) !== self::CHECKSUM_LEN) {
            throw $this->invalid('Token segments have invalid length');
        }

        $expectedChecksum = self::checksum($payloadB64 . '.' . $sigB64);
        if (!hash_equals($expectedChecksum, strtoupper($checksum))) {
            throw $this->invalid('Token checksum mismatch');
        }

        $payloadJson = self::base64UrlDecode($payloadB64);
        if ($payloadJson === null) {
            throw $this->invalid('Token payload is not valid base64url');
        }
        $signature = self::base64UrlDecode($sigB64);
        if ($signature === null) {
            throw $this->invalid('Token signature is not valid base64url');
        }
        $expected = hash_hmac(self::ALGO, $payloadJson, $this->secret, true);
        if (!hash_equals($expected, $signature)) {
            throw $this->invalid('Token signature mismatch');
        }

        $decoded = json_decode($payloadJson, true);
        if (!is_array($decoded)) {
            throw $this->invalid('Token payload is not a JSON object');
        }
        if ((int) $decoded['v'] !== self::VERSION) {
            throw $this->invalid('Token version not supported', ['v' => $decoded['v']]);
        }

        // Common fields for both guest and worker tokens
        foreach (['jti','iat','exp'] as $f) {
            if (!array_key_exists($f, $decoded)) {
                throw $this->invalid("Token payload missing field: {$f}");
            }
        }

        // Discriminate by sub field
        $sub = isset($decoded['sub']) ? (string) $decoded['sub'] : 'guest';

        if ($sub === 'worker') {
            if (!array_key_exists('wid', $decoded)) {
                throw $this->invalid('Token payload missing field: wid');
            }
            return [
                'v'   => (int) $decoded['v'],
                'sub' => 'worker',
                'wid' => (int) $decoded['wid'],
                'jti' => (string) $decoded['jti'],
                'iat' => (int) $decoded['iat'],
                'exp' => (int) $decoded['exp'],
            ];
        }

        // Guest (default): require room_id and stay_id
        foreach (['room_id','stay_id'] as $f) {
            if (!array_key_exists($f, $decoded)) {
                throw $this->invalid("Token payload missing field: {$f}");
            }
        }
        return [
            'v'       => (int) $decoded['v'],
            'room_id' => (int) $decoded['room_id'],
            'stay_id' => (int) $decoded['stay_id'],
            'jti'     => (string) $decoded['jti'],
            'iat'     => (int) $decoded['iat'],
            'exp'     => (int) $decoded['exp'],
        ];
    }

    /**
     * SHA-256(token) hex digest. Persisted in qr_credentials.token_hash so the
     * full token never lives in the database. Also used for worker QR hashes.
     */
    public static function hashForStorage(string $token): string
    {
        return hash('sha256', $token);
    }

    // -- internals -----------------------------------------------------------

    private static function checksum(string $material): string
    {
        $sha1 = sha1($material, true);
        $bytes = substr($sha1, 0, 2);
        $value = (ord($bytes[0]) << 8) | ord($bytes[1]);
        $out = '';
        for ($i = 0; $i < self::CHECKSUM_LEN; $i++) {
            if ($i < 3) {
                $shift = 11 - ($i * 5);
                $idx = ($value >> $shift) & 0x1F;
            } else {
                $idx = ($value & 0x1) << 4;
            }
            $out .= self::BASE32_ALPHABET[$idx];
        }
        return $out;
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $b64url): ?string
    {
        $b64 = strtr($b64url, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad !== 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($b64, true);
        return $decoded === false ? null : $decoded;
    }

    /**
     * @param array<string,mixed> $details
     */
    private function invalid(string $message, array $details = []): ApiException
    {
        return new ApiException(403, 'qr_invalid_signature', $message, $details);
    }
}
