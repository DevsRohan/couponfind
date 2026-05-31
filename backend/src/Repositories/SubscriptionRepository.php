<?php

declare(strict_types=1);

namespace CouponFind\Repositories;

use CouponFind\Core\Database;

final class SubscriptionRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::instance();
    }

    /** Active (or trialing) subscription for a user, joined with the plan. */
    public function activeForUser(int $userId): ?array
    {
        return $this->db->first(
            "SELECT s.*, p.slug AS plan_slug, p.name AS plan_name, p.search_limit AS plan_search_limit,
                    p.search_window AS plan_search_window, p.price_cents, p.`interval`
             FROM subscriptions s JOIN plans p ON p.id = s.plan_id
             WHERE s.user_id = ? AND s.status IN ('active','trialing')
             ORDER BY s.id DESC LIMIT 1",
            [$userId]
        );
    }

    public function findByGatewayId(string $gateway, string $gatewaySubId): ?array
    {
        return $this->db->first(
            'SELECT * FROM subscriptions WHERE gateway = ? AND gateway_subscription_id = ? LIMIT 1',
            [$gateway, $gatewaySubId]
        );
    }

    public function create(array $d): int
    {
        return $this->db->insert(
            'INSERT INTO subscriptions (user_id, plan_id, gateway, gateway_subscription_id, status, is_lifetime, current_period_start, current_period_end, override_search_limit, override_search_window)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $d['user_id'], $d['plan_id'], $d['gateway'] ?? 'manual', $d['gateway_subscription_id'] ?? null,
                $d['status'] ?? 'active', (int) ($d['is_lifetime'] ?? 0),
                $d['current_period_start'] ?? date('Y-m-d H:i:s'),
                $d['current_period_end'] ?? null,
                $d['override_search_limit'] ?? null, $d['override_search_window'] ?? null,
            ]
        );
    }

    public function updateStatus(int $id, string $status, ?string $periodEnd = null): void
    {
        $this->db->execute(
            'UPDATE subscriptions SET status = ?, current_period_end = COALESCE(?, current_period_end) WHERE id = ?',
            [$status, $periodEnd, $id]
        );
    }

    public function cancelAtPeriodEnd(int $id, bool $flag = true): void
    {
        $this->db->execute(
            'UPDATE subscriptions SET cancel_at_period_end = ?, canceled_at = IF(?, NOW(), canceled_at) WHERE id = ?',
            [(int) $flag, (int) $flag, $id]
        );
    }

    public function switchPlan(int $id, int $newPlanId): void
    {
        $this->db->execute('UPDATE subscriptions SET plan_id = ? WHERE id = ?', [$newPlanId, $id]);
    }

    public function setOverride(int $id, ?int $limit, ?string $window, bool $lifetime): void
    {
        $this->db->execute(
            'UPDATE subscriptions SET override_search_limit = ?, override_search_window = ?, is_lifetime = ? WHERE id = ?',
            [$limit, $window, (int) $lifetime, $id]
        );
    }

    public function recentForUser(int $userId): array
    {
        return $this->db->all(
            "SELECT s.*, p.name AS plan_name FROM subscriptions s JOIN plans p ON p.id = s.plan_id
             WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 20",
            [$userId]
        );
    }

    public function countActive(): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM subscriptions WHERE status IN ('active','trialing')");
    }

    public function mrrCents(): int
    {
        // Monthly recurring revenue: normalize yearly to monthly.
        return (int) $this->db->scalar(
            "SELECT COALESCE(SUM(CASE WHEN p.`interval`='year' THEN p.price_cents/12 ELSE p.price_cents END),0)
             FROM subscriptions s JOIN plans p ON p.id = s.plan_id
             WHERE s.status IN ('active','trialing') AND p.price_cents > 0"
        );
    }
}
