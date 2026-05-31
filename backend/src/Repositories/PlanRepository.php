<?php

declare(strict_types=1);

namespace CouponFind\Repositories;

use CouponFind\Core\Database;

final class PlanRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::instance();
    }

    public function publicPlans(): array
    {
        return array_map([$this, 'hydrate'], $this->db->all(
            'SELECT * FROM plans WHERE is_active = 1 AND is_public = 1 ORDER BY sort_order ASC'
        ));
    }

    public function all(): array
    {
        return array_map([$this, 'hydrate'], $this->db->all('SELECT * FROM plans ORDER BY sort_order ASC'));
    }

    public function find(int $id): ?array
    {
        $row = $this->db->first('SELECT * FROM plans WHERE id = ? LIMIT 1', [$id]);
        return $row ? $this->hydrate($row) : null;
    }

    public function findBySlug(string $slug): ?array
    {
        $row = $this->db->first('SELECT * FROM plans WHERE slug = ? LIMIT 1', [$slug]);
        return $row ? $this->hydrate($row) : null;
    }

    public function create(array $d): int
    {
        return $this->db->insert(
            'INSERT INTO plans (slug, name, description, price_cents, currency, `interval`, search_limit, search_window, is_active, is_public, sort_order, stripe_price_id, razorpay_plan_id, features_json)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $d['slug'], $d['name'], $d['description'] ?? null, (int) ($d['price_cents'] ?? 0),
                $d['currency'] ?? 'USD', $d['interval'] ?? 'month',
                isset($d['search_limit']) ? (int) $d['search_limit'] : null,
                $d['search_window'] ?? 'day', (int) ($d['is_active'] ?? 1), (int) ($d['is_public'] ?? 1),
                (int) ($d['sort_order'] ?? 0), $d['stripe_price_id'] ?? null, $d['razorpay_plan_id'] ?? null,
                isset($d['features']) ? json_encode($d['features']) : null,
            ]
        );
    }

    public function update(int $id, array $d): void
    {
        $this->db->execute(
            'UPDATE plans SET name=?, description=?, price_cents=?, currency=?, `interval`=?, search_limit=?, search_window=?, is_active=?, is_public=?, sort_order=?, stripe_price_id=?, razorpay_plan_id=?, features_json=? WHERE id=?',
            [
                $d['name'], $d['description'] ?? null, (int) ($d['price_cents'] ?? 0), $d['currency'] ?? 'USD',
                $d['interval'] ?? 'month', isset($d['search_limit']) ? (int) $d['search_limit'] : null,
                $d['search_window'] ?? 'day', (int) ($d['is_active'] ?? 1), (int) ($d['is_public'] ?? 1),
                (int) ($d['sort_order'] ?? 0), $d['stripe_price_id'] ?? null, $d['razorpay_plan_id'] ?? null,
                isset($d['features']) ? json_encode($d['features']) : null, $id,
            ]
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM plans WHERE id = ?', [$id]);
    }

    private function hydrate(array $row): array
    {
        $row['features'] = $row['features_json'] ? json_decode($row['features_json'], true) : [];
        $row['price'] = round(((int) $row['price_cents']) / 100, 2);
        unset($row['features_json']);
        return $row;
    }
}
