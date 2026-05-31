<?php

declare(strict_types=1);

namespace CouponFind\Repositories;

use CouponFind\Core\Database;
use CouponFind\Security\Password;

final class UserRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::instance();
    }

    public function findById(int $id): ?array
    {
        return $this->db->first(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name
             FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1',
            [$id]
        );
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->first(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name
             FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = ? LIMIT 1',
            [strtolower(trim($email))]
        );
    }

    public function emailExists(string $email): bool
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM users WHERE email = ?', [strtolower(trim($email))]) > 0;
    }

    public function create(string $name, string $email, string $passwordPlain, int $roleId = 3, ?int $referredBy = null): int
    {
        $uuid = self::uuid();
        $referral = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $name) . bin2hex(random_bytes(3)), 0, 8));
        return $this->db->insert(
            'INSERT INTO users (uuid, role_id, name, email, password_hash, status, referral_code, referred_by)
             VALUES (?,?,?,?,?,?,?,?)',
            [$uuid, $roleId, $name, strtolower(trim($email)), Password::hash($passwordPlain), 'active', $referral, $referredBy]
        );
    }

    public function updatePassword(int $userId, string $newPlain): void
    {
        $this->db->execute('UPDATE users SET password_hash = ? WHERE id = ?', [Password::hash($newPlain), $userId]);
    }

    public function touchLogin(int $userId, string $ip): void
    {
        $this->db->execute(
            'UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?',
            [@inet_pton($ip) ?: null, $userId]
        );
    }

    public function findByReferralCode(string $code): ?array
    {
        return $this->db->first('SELECT * FROM users WHERE referral_code = ? LIMIT 1', [$code]);
    }

    /** Admin listing with pagination + search. */
    public function paginate(int $page, int $perPage, ?string $search = null): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $where = '';
        $params = [];
        if ($search) {
            $where = 'WHERE u.name LIKE ? OR u.email LIKE ?';
            $params = ['%' . $search . '%', '%' . $search . '%'];
        }
        $rows = $this->db->all(
            "SELECT u.id, u.uuid, u.name, u.email, u.status, u.created_at, u.last_login_at,
                    r.slug AS role_slug, r.name AS role_name
             FROM users u JOIN roles r ON r.id = u.role_id
             $where ORDER BY u.id DESC LIMIT $perPage OFFSET $offset",
            $params
        );
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM users u $where", $params);
        return ['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function setStatus(int $userId, string $status): void
    {
        $this->db->execute('UPDATE users SET status = ? WHERE id = ?', [$status, $userId]);
    }

    public function setRole(int $userId, int $roleId): void
    {
        $this->db->execute('UPDATE users SET role_id = ? WHERE id = ?', [$roleId, $userId]);
    }

    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
