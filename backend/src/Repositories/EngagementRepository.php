<?php

declare(strict_types=1);

namespace CouponFind\Repositories;

use CouponFind\Core\Database;

/**
 * User engagement data: saved coupons, watchlists, deal alerts, notifications.
 */
final class EngagementRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::instance();
    }

    // ---- Saved coupons ----
    public function saveCoupon(int $userId, int $couponId, ?string $note = null): void
    {
        $this->db->execute(
            'INSERT INTO saved_coupons (user_id, coupon_id, note) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE note = VALUES(note)',
            [$userId, $couponId, $note]
        );
    }

    public function unsaveCoupon(int $userId, int $couponId): void
    {
        $this->db->execute('DELETE FROM saved_coupons WHERE user_id = ? AND coupon_id = ?', [$userId, $couponId]);
    }

    public function savedCoupons(int $userId): array
    {
        return $this->db->all(
            "SELECT sc.id AS saved_id, sc.note, sc.created_at AS saved_at,
                    c.id, c.title, c.code, c.discount_value, c.discount_type, c.landing_url, c.valid_until,
                    m.name AS merchant_name, m.slug AS merchant_slug, m.logo_url AS merchant_logo
             FROM saved_coupons sc
             JOIN coupons c ON c.id = sc.coupon_id
             JOIN merchants m ON m.id = c.merchant_id
             WHERE sc.user_id = ? ORDER BY sc.id DESC",
            [$userId]
        );
    }

    // ---- Watchlists ----
    public function addWatch(int $userId, ?int $merchantId, ?string $keyword): int
    {
        return $this->db->insert(
            'INSERT INTO watchlists (user_id, merchant_id, keyword) VALUES (?,?,?)',
            [$userId, $merchantId, $keyword]
        );
    }

    public function removeWatch(int $userId, int $watchId): void
    {
        $this->db->execute('DELETE FROM watchlists WHERE id = ? AND user_id = ?', [$watchId, $userId]);
    }

    public function watchlist(int $userId): array
    {
        return $this->db->all(
            'SELECT w.id, w.keyword, w.created_at, m.id AS merchant_id, m.name AS merchant_name, m.slug AS merchant_slug
             FROM watchlists w LEFT JOIN merchants m ON m.id = w.merchant_id
             WHERE w.user_id = ? ORDER BY w.id DESC',
            [$userId]
        );
    }

    // ---- Deal alerts ----
    public function addAlert(int $userId, array $d): int
    {
        return $this->db->insert(
            'INSERT INTO deal_alerts (user_id, merchant_id, keyword, min_discount, channel) VALUES (?,?,?,?,?)',
            [$userId, $d['merchant_id'] ?? null, $d['keyword'] ?? null, $d['min_discount'] ?? null, $d['channel'] ?? 'in_app']
        );
    }

    public function removeAlert(int $userId, int $alertId): void
    {
        $this->db->execute('DELETE FROM deal_alerts WHERE id = ? AND user_id = ?', [$alertId, $userId]);
    }

    public function alerts(int $userId): array
    {
        return $this->db->all(
            'SELECT da.id, da.keyword, da.min_discount, da.channel, da.is_active, da.last_triggered_at,
                    m.name AS merchant_name
             FROM deal_alerts da LEFT JOIN merchants m ON m.id = da.merchant_id
             WHERE da.user_id = ? ORDER BY da.id DESC',
            [$userId]
        );
    }

    // ---- Notifications ----
    public function notify(int $userId, string $type, string $title, ?string $body = null, array $data = []): int
    {
        return $this->db->insert(
            'INSERT INTO notifications (user_id, type, title, body, data_json) VALUES (?,?,?,?,?)',
            [$userId, $type, $title, $body, $data ? json_encode($data) : null]
        );
    }

    public function notifications(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        return $this->db->all(
            'SELECT id, type, title, body, read_at, created_at FROM notifications
             WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit,
            [$userId]
        );
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL',
            [$userId]
        );
    }

    public function markRead(int $userId, int $notificationId): void
    {
        $this->db->execute(
            'UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL',
            [$notificationId, $userId]
        );
    }

    public function markAllRead(int $userId): void
    {
        $this->db->execute('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }
}
