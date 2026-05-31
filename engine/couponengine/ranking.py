"""Coupon scoring engine.

Computes a composite 0..1 score from four components:
  freshness   — how recently the coupon was seen / how long until expiry
  reliability — success/(success+fail) feedback ratio
  popularity  — normalized usage count
  value       — normalized discount value

The blended score drives search ranking (mirrored into Meilisearch).
"""
from __future__ import annotations

import logging
from datetime import datetime

from .db import db

log = logging.getLogger("couponengine.ranking")

WEIGHTS = {"freshness": 0.30, "reliability": 0.30, "popularity": 0.20, "value": 0.20}


def _freshness(last_seen, valid_until) -> float:
    score = 0.5
    now = datetime.utcnow()
    if last_seen:
        try:
            ls = last_seen if isinstance(last_seen, datetime) else datetime.fromisoformat(str(last_seen))
            days = max(0.0, (now - ls).total_seconds() / 86400)
            score = max(0.1, 1.0 - min(1.0, days / 30.0))
        except (ValueError, TypeError):
            pass
    if valid_until:
        try:
            vu = valid_until if isinstance(valid_until, datetime) else datetime.fromisoformat(str(valid_until))
            if vu < now:
                return 0.05
        except (ValueError, TypeError):
            pass
    return round(score, 5)


def _reliability(success: int, fail: int) -> float:
    total = success + fail
    if total == 0:
        return 0.6  # neutral prior
    return round(success / total, 5)


def _popularity(times_used: int) -> float:
    return round(min(1.0, times_used / 1000.0), 5)


def _value(discount_type: str | None, discount_value) -> float:
    if discount_type == "percent" and discount_value:
        return round(min(1.0, float(discount_value) / 100.0), 5)
    if discount_type == "amount" and discount_value:
        return round(min(1.0, float(discount_value) / 200.0), 5)
    if discount_type == "free_shipping":
        return 0.4
    return 0.25


def score_coupon(row: dict) -> dict:
    fr = _freshness(row.get("last_seen_at"), row.get("valid_until"))
    rel = _reliability(int(row.get("success_count") or 0), int(row.get("fail_count") or 0))
    pop = _popularity(int(row.get("times_used") or 0))
    val = _value(row.get("discount_type"), row.get("discount_value"))
    featured = 0.1 if row.get("is_featured") else 0.0
    composite = (
        WEIGHTS["freshness"] * fr
        + WEIGHTS["reliability"] * rel
        + WEIGHTS["popularity"] * pop
        + WEIGHTS["value"] * val
        + featured
    )
    return {
        "score": round(min(0.99999, composite), 5),
        "freshness": fr,
        "reliability": rel,
        "popularity": pop,
        "value_score": val,
    }


def run(limit: int = 1000) -> int:
    rows = db().query(
        "SELECT id, last_seen_at, valid_until, success_count, fail_count, times_used, "
        "discount_type, discount_value, is_featured FROM coupons "
        "WHERE status='active' ORDER BY updated_at DESC LIMIT %s",
        (limit,),
    )
    for row in rows:
        s = score_coupon(row)
        db().execute(
            "INSERT INTO coupon_scores (coupon_id, score, freshness, reliability, popularity, value_score) "
            "VALUES (%s,%s,%s,%s,%s,%s) "
            "ON DUPLICATE KEY UPDATE score=VALUES(score), freshness=VALUES(freshness), "
            "reliability=VALUES(reliability), popularity=VALUES(popularity), value_score=VALUES(value_score)",
            (row["id"], s["score"], s["freshness"], s["reliability"], s["popularity"], s["value_score"]),
        )
    log.info("scored %d coupons", len(rows))
    return len(rows)
