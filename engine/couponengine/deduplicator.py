"""Coupon deduplication via a stable content hash."""
from __future__ import annotations

import hashlib


def content_hash(merchant_slug: str, coupon: dict) -> str:
    """Deterministic identity for a coupon. Matches the PHP/seed convention:
    sha256(merchant_slug + code + discount_type + discount_value + title).
    """
    parts = [
        merchant_slug or "",
        (coupon.get("code") or "").upper(),
        coupon.get("discount_type") or "",
        str(coupon.get("discount_value") or ""),
        (coupon.get("title") or "").strip().lower(),
    ]
    return hashlib.sha256("|".join(parts).encode("utf-8")).hexdigest()


def dedupe(coupons: list[dict]) -> list[dict]:
    """Remove in-batch duplicates by content hash, keeping highest confidence."""
    best: dict[str, dict] = {}
    for c in coupons:
        key = c.get("content_hash") or content_hash(c.get("merchant_slug", ""), c)
        c["content_hash"] = key
        if key not in best or c.get("confidence", 0) > best[key].get("confidence", 0):
            best[key] = c
    return list(best.values())
