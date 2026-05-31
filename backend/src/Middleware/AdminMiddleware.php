<?php

declare(strict_types=1);

namespace CouponFind\Middleware;

use CouponFind\Core\Request;
use CouponFind\Core\Response;
use CouponFind\Security\Rbac;

/**
 * Requires an authenticated admin / super_admin. Must run after AuthMiddleware.
 */
final class AdminMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return Response::error('Unauthorized', 401);
        }
        if (!Rbac::isAdmin($user)) {
            return Response::error('Forbidden: admin access required', 403);
        }
        return $next($request);
    }
}
