<?php

declare(strict_types=1);

namespace CouponFind\Repositories;

use CouponFind\Core\Database;

final class MerchantRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::instance();
    }

    public function all(bool $activeOnly = true): array
    {
        $where = $activeOnly ? 'WHERE is_active = 1' : '';
        return $this->db->all("SELECT * FROM merchants $where ORDER BY popularity DESC, name ASC");
    }

    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM merchants WHERE id = ? LIMIT 1', [$id]);
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->db->first('SELECT * FROM merchants WHERE slug = ? LIMIT 1', [$slug]);
    }

    /**
     * Returns alias map [normalized_alias => merchant_id] for query intent
     * detection. Cached by the caller (SearchService) where appropriate.
     */
    public function aliasMap(): array
    {
        $rows = $this->db->all('SELECT normalized, merchant_id, weight FROM merchant_aliases');
        $map = [];
        foreach ($rows as $r) {
            $map[$r['normalized']] = ['merchant_id' => (int) $r['merchant_id'], 'weight' => (int) $r['weight']];
        }
        return $map;
    }

    public function create(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO merchants (slug, name, domain, website_url, logo_url, category, country, description, is_active)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [
                $data['slug'], $data['name'], $data['domain'] ?? null, $data['website_url'] ?? null,
                $data['logo_url'] ?? null, $data['category'] ?? null, $data['country'] ?? null,
                $data['description'] ?? null, (int) ($data['is_active'] ?? 1),
            ]
        );
    }

    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE merchants SET name=?, domain=?, website_url=?, category=?, country=?, description=?, is_active=? WHERE id=?',
            [
                $data['name'], $data['domain'] ?? null, $data['website_url'] ?? null,
                $data['category'] ?? null, $data['country'] ?? null, $data['description'] ?? null,
                (int) ($data['is_active'] ?? 1), $id,
            ]
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM merchants WHERE id = ?', [$id]);
    }

    public function count(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM merchants');
    }
}
