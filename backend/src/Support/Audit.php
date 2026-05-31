<?php

declare(strict_types=1);

namespace CouponFind\Support;

use CouponFind\Core\Database;

/**
 * Centralized audit logging for security/admin-relevant actions.
 * Failures here never propagate — auditing must not break the request.
 */
final class Audit
{
    public static function log(
        ?int $actorId,
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        array $meta = [],
        ?string $ip = null
    ): void {
        try {
            Database::instance()->execute(
                'INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, meta_json, ip)
                 VALUES (?,?,?,?,?,?)',
                [
                    $actorId,
                    $action,
                    $entityType,
                    $entityId,
                    $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null,
                    $ip ? (@inet_pton($ip) ?: null) : null,
                ]
            );
        } catch (\Throwable) {
            // swallow
        }
    }
}
