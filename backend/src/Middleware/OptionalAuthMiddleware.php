<?php

declare(strict_types=1);

namespace CouponFind\Middleware;

use CouponFind\Core\Request;
use CouponFind\Core\Response;

/**
 * Attaches the authenticated user when a valid token is present, but never
 * rejects the request. Used for endpoints (like search) that work for guests
 * yet personalize / meter differently when logged in.
 */
final class OptionalAuthMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        $user = AuthMiddleware::resolveUser($request);
        if ($user !== null && ($user['status'] ?? 'active') !== 'suspended') {
            $request->setAttribute('user', $user);
        }
        return $next($request);
    }
}
