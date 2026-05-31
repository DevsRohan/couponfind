<?php

declare(strict_types=1);

namespace CouponFind\Security;

use CouponFind\Core\RedisClient;

/**
 * Fixed-window rate limiter backed by Redis. If Redis is unavailable the
 * limiter fails open (returns allowed) so availability is never sacrificed
 * for throttling — abuse protection degrades but the app keeps working.
 */
final class RateLimiter
{
    /**
     * @return array{allowed:bool, remaining:int, limit:int, reset:int}
     */
    public static function hit(string $key, int $limit, int $windowSeconds): array
    {
        $redis = RedisClient::instance();
        if (!$redis->isAvailable()) {
            return ['allowed' => true, 'remaining' => $limit, 'limit' => $limit, 'reset' => $windowSeconds];
        }

        $bucket = 'rl:' . $key;
        $count = $redis->incrementWindow($bucket, $windowSeconds);
        $ttl = $redis->ttl($bucket);
        $reset = $ttl > 0 ? $ttl : $windowSeconds;

        return [
            'allowed'   => $count <= $limit,
            'remaining' => max(0, $limit - $count),
            'limit'     => $limit,
            'reset'     => $reset,
        ];
    }
}
