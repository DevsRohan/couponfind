<?php

declare(strict_types=1);

namespace CouponFind\Repositories;

use CouponFind\Core\Database;

final class SearchLogRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::instance();
    }

    public function log(array $d): int
    {
        return $this->db->insert(
            'INSERT INTO search_logs (user_id, query_raw, query_normalized, detected_merchant_id, intent_json, result_count, took_ms, cache_hit, ip)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [
                $d['user_id'] ?? null,
                substr((string) $d['query_raw'], 0, 255),
                isset($d['query_normalized']) ? substr((string) $d['query_normalized'], 0, 255) : null,
                $d['detected_merchant_id'] ?? null,
                isset($d['intent']) ? json_encode($d['intent'], JSON_UNESCAPED_SLASHES) : null,
                (int) ($d['result_count'] ?? 0),
                (int) ($d['took_ms'] ?? 0),
                (int) ($d['cache_hit'] ?? 0),
                isset($d['ip']) ? (@inet_pton($d['ip']) ?: null) : null,
            ]
        );
    }

    public function recentForUser(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return $this->db->all(
            'SELECT id, query_raw, result_count, took_ms, created_at FROM search_logs
             WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit,
            [$userId]
        );
    }

    public function totalCount(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM search_logs');
    }

    public function countSince(string $since): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM search_logs WHERE created_at >= ?', [$since]);
    }

    /** Top search terms over a window for analytics. */
    public function topQueries(int $limit = 10, int $days = 30): array
    {
        $limit = max(1, min(50, $limit));
        $days = max(1, $days);
        return $this->db->all(
            "SELECT query_normalized AS term, COUNT(*) AS hits
             FROM search_logs
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL $days DAY) AND query_normalized IS NOT NULL AND query_normalized <> ''
             GROUP BY query_normalized ORDER BY hits DESC LIMIT $limit"
        );
    }

    /** Daily search volume for charts. */
    public function dailyVolume(int $days = 14): array
    {
        $days = max(1, $days);
        return $this->db->all(
            "SELECT DATE(created_at) AS day, COUNT(*) AS hits
             FROM search_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
             GROUP BY DATE(created_at) ORDER BY day ASC"
        );
    }

    public function avgLatencyMs(int $days = 7): float
    {
        $days = max(1, $days);
        return (float) $this->db->scalar(
            "SELECT COALESCE(AVG(took_ms),0) FROM search_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)"
        );
    }
}
