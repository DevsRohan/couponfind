<?php

declare(strict_types=1);

namespace CouponFind\Security;

/**
 * Stateless double-submit CSRF protection for cookie-authenticated,
 * state-changing requests.
 *
 *  - A random token is placed in a readable cookie (cf_csrf).
 *  - The JS client echoes it back in the X-CSRF-Token header.
 *  - The server confirms the two match (constant-time).
 *
 * Combined with HttpOnly + SameSite access-token cookies this gives strong
 * CSRF protection with no server-side state. Pure Bearer-token API calls are
 * exempt (they carry no ambient browser credentials).
 */
final class Csrf
{
    public const COOKIE = 'cf_csrf';
    public const HEADER = 'X-CSRF-Token';

    public static function generate(): string
    {
        return bin2hex(random_bytes(24));
    }

    public static function matches(?string $cookie, ?string $header): bool
    {
        if ($cookie === null || $cookie === '' || $header === null || $header === '') {
            return false;
        }
        return hash_equals($cookie, $header);
    }
}
