<?php

declare(strict_types=1);

namespace CouponFind\Middleware;

use CouponFind\Core\Request;
use CouponFind\Core\Response;
use CouponFind\Security\Csrf;

/**
 * Enforces CSRF only for cookie-authenticated, state-changing requests.
 * Bearer-token (API) requests and safe methods are exempt.
 */
final class CsrfMiddleware
{
    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];

    public function __invoke(Request $request, callable $next): Response
    {
        if (in_array($request->method(), self::SAFE, true)) {
            return $next($request);
        }
        // Pure API token requests are not CSRF-able.
        if ($request->bearerToken() !== null) {
            return $next($request);
        }
        // Only relevant when relying on the cookie session.
        if ($request->cookie('cf_session') === null) {
            return $next($request);
        }

        $cookie = $request->cookie(Csrf::COOKIE);
        $header = $request->header(Csrf::HEADER);
        if (!Csrf::matches($cookie, $header)) {
            return Response::error('CSRF token mismatch', 419);
        }
        return $next($request);
    }
}
