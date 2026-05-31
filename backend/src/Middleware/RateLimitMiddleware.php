<?php

declare(strict_types=1);

namespace CouponFind\Middleware;

use CouponFind\Core\Request;
use CouponFind\Core\Response;
use CouponFind\Security\RateLimiter;

/**
 * Global per-IP request throttle. Sets standard X-RateLimit-* headers.
 * Returns 429 once the window budget is exhausted.
 */
final class RateLimitMiddleware
{
    public function __construct(
        private int $limit = 120,
        private int $windowSeconds = 60
    ) {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        $key = 'ip:' . $request->ip() . ':global';
        $result = RateLimiter::hit($key, $this->limit, $this->windowSeconds);

        if (!$result['allowed']) {
            return Response::error('Too many requests. Please slow down.', 429)
                ->header('Retry-After', (string) $result['reset'])
                ->header('X-RateLimit-Limit', (string) $result['limit'])
                ->header('X-RateLimit-Remaining', '0');
        }

        $response = $next($request);
        return $response
            ->header('X-RateLimit-Limit', (string) $result['limit'])
            ->header('X-RateLimit-Remaining', (string) $result['remaining']);
    }
}
