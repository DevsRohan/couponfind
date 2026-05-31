<?php

declare(strict_types=1);

namespace CouponFind\Repositories;

use CouponFind\Core\Database;

final class CouponRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::instance();
    }

    private const SELECT = "
        SELECT c.id, c.title, c.description, c.code, c.type, c.discount_type, c.discount_value,
               c.currency, c.landing_url, c.terms, c.status, c.is_featured, c.valid_until,
               c.success_count, c.fail_count, c.times_used,
               m.id AS merchant_id, m.name AS merchant_name, m.slug AS merchant_slug, m.logo_url AS merchant_logo,
               COALESCE(cs.score, 0) AS score
        FROM coupons c
        JOIN merchants m ON m.id = c.merchant_id
        LEFT JOIN coupon_scores cs ON cs.coupon_id = c.id
    ";

    public function find(int $id): ?array
    {
        return $this->db->first(self::SELECT . ' WHERE c.id = ? LIMIT 1', [$id]);
    }

    /**
     * Database-side search used as a fallback when Meilisearch is unavailable.
     * Filters by merchant + active status, applies fulltext / LIKE matching,
     * and orders by composite score then freshness.
     */
    public function search(?int $merchantId, ?string $text, ?float $minDiscount, int $limit = 40): array
    {
        $conditions = ["c.status = 'active'", '(c.valid_until IS NULL OR c.valid_until > NOW())'];
        $params = [];

        if ($merchantId !== null) {
            $conditions[] = 'c.merchant_id = ?';
            $params[] = $merchantId;
        }
        if ($minDiscount !== null) {
            $conditions[] = 'c.discount_value >= ?';
            $params[] = $minDiscount;
        }
        if ($text !== null && trim($text) !== '') {
            $conditions[] = '(c.title LIKE ? OR c.description LIKE ? OR m.name LIKE ?)';
            $like = '%' . trim($text) . '%';
            array_push($params, $like, $like, $like);
        }

        $where = implode(' AND ', $conditions);
        $limit = max(1, min(100, $limit));

        return $this->db->all(
            self::SELECT . " WHERE $where ORDER BY c.is_featured DESC, score DESC, c.last_seen_at DESC LIMIT $limit",
            $params
        );
    }

    public function featured(int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db->all(
            self::SELECT . " WHERE c.status = 'active' AND (c.valid_until IS NULL OR c.valid_until > NOW())
             ORDER BY c.is_featured DESC, score DESC LIMIT $limit"
        );
    }

    public function byMerchant(int $merchantId, int $limit = 40): array
    {
        $limit = max(1, min(100, $limit));
        return $this->db->all(
            self::SELECT . " WHERE c.merchant_id = ? AND c.status = 'active' ORDER BY score DESC LIMIT $limit",
            [$merchantId]
        );
    }

    public function paginate(int $page, int $perPage, ?string $status = null, ?string $search = null): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $conditions = [];
        $params = [];
        if ($status) {
            $conditions[] = 'c.status = ?';
            $params[] = $status;
        }
        if ($search) {
            $conditions[] = '(c.title LIKE ? OR c.code LIKE ? OR m.name LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $rows = $this->db->all(
            self::SELECT . " $where ORDER BY c.id DESC LIMIT $perPage OFFSET $offset",
            $params
        );
        $total = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM coupons c JOIN merchants m ON m.id = c.merchant_id $where",
            $params
        );
        return ['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->execute('UPDATE coupons SET status = ? WHERE id = ?', [$status, $id]);
    }

    public function expire(int $id): void
    {
        $this->db->execute("UPDATE coupons SET status = 'expired' WHERE id = ?", [$id]);
    }

    public function recordUse(int $id): void
    {
        $this->db->execute('UPDATE coupons SET times_used = times_used + 1 WHERE id = ?', [$id]);
    }

    public function recordFeedback(int $id, bool $worked): void
    {
        $col = $worked ? 'success_count' : 'fail_count';
        $this->db->execute("UPDATE coupons SET $col = $col + 1 WHERE id = ?", [$id]);
    }

    public function activeCount(): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM coupons WHERE status = 'active'");
    }

    public function totalCount(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM coupons');
    }
}
