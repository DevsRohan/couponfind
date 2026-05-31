<?php

declare(strict_types=1);

namespace CouponFind\Security;

use CouponFind\Core\Database;
use CouponFind\Core\RedisClient;

/**
 * Role-based access control. Permissions are resolved per role from
 * role_permissions and cached in Redis for speed. super_admin implicitly
 * holds every permission.
 */
final class Rbac
{
    private const SUPER_ADMIN = 'super_admin';
    private const CACHE_TTL = 300;

    /** @param array $user user row (must contain role_id, role_slug) */
    public static function can(array $user, string $permission): bool
    {
        $roleSlug = $user['role_slug'] ?? null;
        if ($roleSlug === self::SUPER_ADMIN) {
            return true;
        }
        $roleId = (int) ($user['role_id'] ?? 0);
        if ($roleId === 0) {
            return false;
        }
        return in_array($permission, self::permissionsForRole($roleId), true);
    }

    public static function isSuperAdmin(array $user): bool
    {
        return ($user['role_slug'] ?? null) === self::SUPER_ADMIN;
    }

    public static function isAdmin(array $user): bool
    {
        return in_array($user['role_slug'] ?? null, [self::SUPER_ADMIN, 'admin'], true);
    }

    /** @return string[] */
    public static function permissionsForRole(int $roleId): array
    {
        $cacheKey = 'rbac:role:' . $roleId;
        $redis = RedisClient::instance();
        $cached = $redis->get($cacheKey);
        if ($cached !== null) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $rows = Database::instance()->all(
            'SELECT p.slug FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = ?',
            [$roleId]
        );
        $perms = array_column($rows, 'slug');

        $redis->set($cacheKey, json_encode($perms), self::CACHE_TTL);
        return $perms;
    }
}
