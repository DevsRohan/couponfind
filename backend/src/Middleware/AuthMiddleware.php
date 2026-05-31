<?php

declare(strict_types=1);

namespace CouponFind\Middleware;

use CouponFind\Core\Database;
use CouponFind\Core\Request;
use CouponFind\Core\Response;
use CouponFind\Security\Jwt;

/**
 * Authenticates the request via a Bearer access token (API clients) or the
 * HttpOnly access-token cookie (web app). Loads the user + role and attaches
 * it to the request. Rejects with 401 when missing/invalid.
 */
final class AuthMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        $user = self::resolveUser($request);
        if ($user === null) {
            return Response::error('Unauthorized', 401);
        }
        if (($user['status'] ?? 'active') === 'suspended') {
            return Response::error('Account suspended', 403);
        }
        $request->setAttribute('user', $user);
        return $next($request);
    }

    public static function resolveUser(Request $request): ?array
    {
        $token = $request->bearerToken() ?? $request->cookie('cf_session');
        if ($token === null) {
            return null;
        }
        $claims = Jwt::verify($token);
        if ($claims === null || ($claims['typ'] ?? '') !== 'access') {
            return null;
        }
        $userId = (int) ($claims['sub'] ?? 0);
        if ($userId === 0) {
            return null;
        }

        return Database::instance()->first(
            'SELECT u.id, u.uuid, u.name, u.email, u.status, u.role_id, u.avatar_url,
                    u.referral_code, r.slug AS role_slug, r.name AS role_name
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? LIMIT 1',
            [$userId]
        );
    }
}
