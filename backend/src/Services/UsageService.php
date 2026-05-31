<?php

declare(strict_types=1);

namespace CouponFind\Services;

use CouponFind\Core\Database;
use CouponFind\Repositories\SubscriptionRepository;

/**
 * Search quota metering + enforcement.
 *
 * Effective limit resolution (most specific wins):
 *   subscription.override_search_limit  >  plan.search_limit  >  free default (10/day)
 *
 * A NULL effective limit means unlimited. Counters are stored durably per
 * (user, metric, window) and reset implicitly when the window key rolls over.
 */
final class UsageService
{
    private const FREE_LIMIT = 10;
    private const FREE_WINDOW = 'day';

    public function __construct(
        private SubscriptionRepository $subscriptions,
        private ?Database $db = null
    ) {
        $this->db = $db ?? Database::instance();
    }

    /** @return array{limit:?int, window:string, plan:string} */
    public function effectiveLimit(int $userId): array
    {
        $sub = $this->subscriptions->activeForUser($userId);
        if ($sub === null) {
            return ['limit' => self::FREE_LIMIT, 'window' => self::FREE_WINDOW, 'plan' => 'free'];
        }

        if ($sub['is_lifetime'] && $sub['override_search_limit'] === null) {
            return ['limit' => null, 'window' => $sub['override_search_window'] ?? 'day', 'plan' => $sub['plan_slug']];
        }
        if ($sub['override_search_limit'] !== null) {
            return [
                'limit'  => (int) $sub['override_search_limit'],
                'window' => $sub['override_search_window'] ?? 'day',
                'plan'   => $sub['plan_slug'],
            ];
        }
        return [
            'limit'  => $sub['plan_search_limit'] !== null ? (int) $sub['plan_search_limit'] : null,
            'window' => $sub['plan_search_window'] ?? 'day',
            'plan'   => $sub['plan_slug'],
        ];
    }

    /** @return array{limit:?int, used:int, remaining:?int, window:string, plan:string, unlimited:bool} */
    public function status(int $userId): array
    {
        $eff = $this->effectiveLimit($userId);
        $used = $this->currentCount($userId, $eff['window']);
        $unlimited = $eff['limit'] === null;
        return [
            'limit'     => $eff['limit'],
            'used'      => $used,
            'remaining' => $unlimited ? null : max(0, $eff['limit'] - $used),
            'window'    => $eff['window'],
            'plan'      => $eff['plan'],
            'unlimited' => $unlimited,
        ];
    }

    public function canSearch(int $userId): bool
    {
        $s = $this->status($userId);
        return $s['unlimited'] || $s['used'] < $s['limit'];
    }

    /** Increment the counter for the current window; returns the new count. */
    public function record(int $userId): int
    {
        $eff = $this->effectiveLimit($userId);
        $key = $this->windowKey($eff['window']);
        $this->db->execute(
            'INSERT INTO usage_counters (user_id, metric, window_key, count) VALUES (?, "search", ?, 1)
             ON DUPLICATE KEY UPDATE count = count + 1',
            [$userId, $key]
        );
        return $this->currentCount($userId, $eff['window']);
    }

    private function currentCount(int $userId, string $window): int
    {
        return (int) $this->db->scalar(
            'SELECT COALESCE(count,0) FROM usage_counters WHERE user_id = ? AND metric = "search" AND window_key = ?',
            [$userId, $this->windowKey($window)]
        );
    }

    private function windowKey(string $window): string
    {
        return $window === 'month' ? gmdate('Y-m') : gmdate('Y-m-d');
    }
}
