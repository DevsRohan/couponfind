"""Coupon validation: heuristic + optional HTTP liveness check.

Produces a result (valid/invalid/expired/unknown) with a confidence score and
records it in coupon_validations, updating the coupon status accordingly.
"""
from __future__ import annotations

import logging
from datetime import datetime

from .crawler import head_ok
from .db import db

log = logging.getLogger("couponengine.validator")


def _heuristic(coupon: dict) -> tuple[str, float, str]:
    valid_until = coupon.get("valid_until")
    if valid_until:
        try:
            dt = valid_until if isinstance(valid_until, datetime) else datetime.fromisoformat(str(valid_until))
            if dt < datetime.utcnow():
                return "expired", 0.95, "Past valid_until"
        except (ValueError, TypeError):
            pass

    code = coupon.get("code")
    if code:
        if 4 <= len(code) <= 20 and any(ch.isalnum() for ch in code):
            return "valid", 0.7, "Code format ok"
        return "invalid", 0.6, "Suspicious code format"

    # Code-less deal: rely on a discount being present.
    if coupon.get("discount_value") or coupon.get("discount_type") == "free_shipping":
        return "valid", 0.6, "Deal with discount"
    return "unknown", 0.4, "Insufficient signal"


def validate_coupon(coupon: dict, *, http_check: bool = False) -> dict:
    result, confidence, detail = _heuristic(coupon)
    method = "heuristic"

    if http_check and result in ("valid", "unknown") and coupon.get("landing_url"):
        method = "http_check"
        if head_ok(coupon["landing_url"]):
            confidence = min(0.95, confidence + 0.2)
            detail = "Landing page reachable"
        else:
            result = "invalid"
            confidence = 0.7
            detail = "Landing page unreachable"

    return {"result": result, "confidence": confidence, "detail": detail, "method": method}


def run(limit: int = 200, http_check: bool = False) -> dict:
    """Validate active/unverified coupons and update their status."""
    rows = db().query(
        "SELECT id, code, discount_type, discount_value, landing_url, valid_until, status "
        "FROM coupons WHERE status IN ('active','unverified') ORDER BY last_seen_at DESC LIMIT %s",
        (limit,),
    )
    stats = {"checked": 0, "valid": 0, "expired": 0, "invalid": 0, "unknown": 0}
    for row in rows:
        v = validate_coupon(row, http_check=http_check)
        stats["checked"] += 1
        stats[v["result"]] = stats.get(v["result"], 0) + 1

        db().insert(
            "INSERT INTO coupon_validations (coupon_id, method, result, confidence, detail) VALUES (%s,%s,%s,%s,%s)",
            (row["id"], v["method"], v["result"], v["confidence"], v["detail"][:255]),
        )
        new_status = {"valid": "active", "expired": "expired", "invalid": "rejected"}.get(v["result"])
        if new_status:
            db().execute("UPDATE coupons SET status=%s WHERE id=%s", (new_status, row["id"]))

    log.info("validation: %s", stats)
    return stats
