<?php

declare(strict_types=1);

namespace CouponFind\Security;

use CouponFind\Core\Env;

/**
 * Dependency-free HS256 JWT implementation (encode/decode + verification).
 * Used for stateless access tokens. Refresh tokens are opaque + stored hashed.
 */
final class Jwt
{
    public static function issue(array $claims, int $ttlSeconds): string
    {
        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'jti' => bin2hex(random_bytes(8)),
        ]);

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $segments = [
            self::b64encode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::b64encode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];
        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, self::secret(), true);
        $segments[] = self::b64encode($signature);

        return implode('.', $segments);
    }

    /**
     * @return array|null Decoded claims, or null if invalid/expired.
     */
    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;

        $signingInput = $h . '.' . $p;
        $expected = hash_hmac('sha256', $signingInput, self::secret(), true);
        $provided = self::b64decode($s);
        if ($provided === null || !hash_equals($expected, $provided)) {
            return null;
        }

        $header = json_decode((string) self::b64decode($h), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            return null;
        }

        $payload = json_decode((string) self::b64decode($p), true);
        if (!is_array($payload)) {
            return null;
        }

        $now = time();
        if (isset($payload['nbf']) && $now < (int) $payload['nbf']) {
            return null;
        }
        if (isset($payload['exp']) && $now >= (int) $payload['exp']) {
            return null;
        }

        return $payload;
    }

    private static function secret(): string
    {
        $secret = Env::string('JWT_SECRET', '');
        if ($secret === '') {
            // Deterministic but loud fallback for misconfigured envs.
            $secret = 'insecure-dev-secret-set-JWT_SECRET';
        }
        return $secret;
    }

    private static function b64encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64decode(string $data): ?string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
