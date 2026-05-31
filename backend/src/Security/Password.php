<?php

declare(strict_types=1);

namespace CouponFind\Security;

/**
 * Password hashing. Prefers Argon2id when available, otherwise bcrypt.
 * Verification supports both, and reports when a stored hash needs rehashing.
 */
final class Password
{
    public static function hash(string $plain): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($plain, PASSWORD_ARGON2ID, [
                'memory_cost' => 1 << 16, // 64 MB
                'time_cost'   => 3,
                'threads'     => 2,
            ]);
        }
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_needs_rehash($hash, PASSWORD_ARGON2ID);
        }
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** Hash an opaque token (refresh / reset) for at-rest storage. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
