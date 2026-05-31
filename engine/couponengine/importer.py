"""Imports structured, validated coupons into MySQL (the source of truth)."""
from __future__ import annotations

import logging
import re

from .db import db
from .deduplicator import content_hash

log = logging.getLogger("couponengine.importer")


def slugify(value: str) -> str:
    value = (value or "").strip().lower()
    value = re.sub(r"[^a-z0-9]+", "-", value)
    return value.strip("-")[:120] or "merchant"


def ensure_merchant(name: str, domain: str | None = None, website_url: str | None = None) -> int:
    slug = slugify(name)
    existing = db().first("SELECT id FROM merchants WHERE slug=%s LIMIT 1", (slug,))
    if existing:
        return int(existing["id"])
    if domain:
        by_domain = db().first("SELECT id FROM merchants WHERE domain=%s LIMIT 1", (domain,))
        if by_domain:
            return int(by_domain["id"])
    return db().insert(
        "INSERT INTO merchants (slug, name, domain, website_url, is_active) VALUES (%s,%s,%s,%s,1)",
        (slug, name[:150], domain, website_url),
    )


def import_coupons(coupons: list[dict], default_merchant_id: int | None = None, source_id: int | None = None) -> dict:
    """Upsert a batch of coupons. Returns {inserted, updated}."""
    stats = {"inserted": 0, "updated": 0}
    for c in coupons:
        merchant_id = c.get("merchant_id") or default_merchant_id
        if not merchant_id and c.get("merchant_name"):
            merchant_id = ensure_merchant(c["merchant_name"], c.get("merchant_domain"))
        if not merchant_id:
            continue

        merchant_slug = c.get("merchant_slug")
        if not merchant_slug:
            row = db().first("SELECT slug FROM merchants WHERE id=%s", (merchant_id,))
            merchant_slug = row["slug"] if row else ""

        chash = c.get("content_hash") or content_hash(merchant_slug, c)
        existing = db().first("SELECT id FROM coupons WHERE content_hash=%s LIMIT 1", (chash,))

        if existing:
            db().execute(
                "UPDATE coupons SET title=%s, description=%s, discount_type=%s, discount_value=%s, "
                "landing_url=COALESCE(%s, landing_url), last_seen_at=NOW() WHERE id=%s",
                (c.get("title"), c.get("description"), c.get("discount_type", "other"),
                 c.get("discount_value"), c.get("landing_url"), existing["id"]),
            )
            stats["updated"] += 1
        else:
            db().insert(
                "INSERT INTO coupons (merchant_id, source_id, content_hash, title, description, code, type, "
                "discount_type, discount_value, landing_url, status, last_seen_at) "
                "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'unverified',NOW())",
                (merchant_id, source_id, chash, c.get("title", "Coupon")[:255], c.get("description"),
                 c.get("code"), c.get("type", "deal"), c.get("discount_type", "other"),
                 c.get("discount_value"), c.get("landing_url")),
            )
            stats["inserted"] += 1
    log.info("import: %s", stats)
    return stats
