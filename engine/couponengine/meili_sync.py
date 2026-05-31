"""Synchronizes active coupons from MySQL into Meilisearch.

Creates/configures the index (searchable, filterable, sortable attributes and
typo tolerance) and pushes a flattened, search-optimized projection. PHP reads
from this index during search.
"""
from __future__ import annotations

import logging
from datetime import datetime

import requests

from .config import config
from .db import db

log = logging.getLogger("couponengine.meili")


def _headers() -> dict:
    h = {"Content-Type": "application/json"}
    if config().MEILI_KEY:
        h["Authorization"] = f"Bearer {config().MEILI_KEY}"
    return h


def is_healthy() -> bool:
    try:
        r = requests.get(f"{config().MEILI_HOST}/health", headers=_headers(), timeout=5)
        return r.ok and r.json().get("status") == "available"
    except Exception:
        return False


def ensure_index() -> bool:
    cfg = config()
    try:
        requests.post(f"{cfg.MEILI_HOST}/indexes", headers=_headers(),
                      json={"uid": cfg.MEILI_INDEX, "primaryKey": "id"}, timeout=10)
        settings = {
            "searchableAttributes": ["title", "merchant_name", "code", "description", "category"],
            "filterableAttributes": ["merchant_id", "merchant_slug", "status", "discount_value", "type", "category"],
            "sortableAttributes": ["score", "discount_value", "valid_until_ts"],
            "rankingRules": ["words", "typo", "proximity", "attribute", "sort", "exactness", "score:desc"],
            "typoTolerance": {"enabled": True, "minWordSizeForTypos": {"oneTypo": 3, "twoTypos": 6}},
        }
        r = requests.patch(f"{cfg.MEILI_HOST}/indexes/{cfg.MEILI_INDEX}/settings",
                           headers=_headers(), json=settings, timeout=10)
        return r.ok
    except Exception as exc:
        log.warning("ensure_index failed: %s", exc)
        return False


def _to_ts(value) -> int | None:
    if not value:
        return None
    try:
        dt = value if isinstance(value, datetime) else datetime.fromisoformat(str(value))
        return int(dt.timestamp())
    except (ValueError, TypeError):
        return None


def build_documents(limit: int = 5000) -> list[dict]:
    rows = db().query(
        "SELECT c.id, c.title, c.description, c.code, c.type, c.discount_type, c.discount_value, "
        "c.landing_url, c.valid_until, c.status, COALESCE(cs.score,0) AS score, "
        "m.id AS merchant_id, m.name AS merchant_name, m.slug AS merchant_slug, m.category, m.logo_url AS merchant_logo "
        "FROM coupons c JOIN merchants m ON m.id=c.merchant_id "
        "LEFT JOIN coupon_scores cs ON cs.coupon_id=c.id "
        "WHERE c.status='active' ORDER BY cs.score DESC LIMIT %s",
        (limit,),
    )
    docs = []
    for r in rows:
        docs.append({
            "id": int(r["id"]),
            "title": r["title"],
            "description": r["description"],
            "code": r["code"],
            "type": r["type"],
            "discount_type": r["discount_type"],
            "discount_value": float(r["discount_value"]) if r["discount_value"] is not None else 0.0,
            "landing_url": r["landing_url"],
            "valid_until": str(r["valid_until"]) if r["valid_until"] else None,
            "valid_until_ts": _to_ts(r["valid_until"]) or 0,
            "status": r["status"],
            "score": float(r["score"]),
            "merchant_id": int(r["merchant_id"]),
            "merchant_name": r["merchant_name"],
            "merchant_slug": r["merchant_slug"],
            "merchant_logo": r["merchant_logo"],
            "category": r["category"],
        })
    return docs


def run(limit: int = 5000) -> dict:
    if not is_healthy():
        log.warning("Meilisearch not available — skipping sync")
        return {"synced": 0, "ok": False}
    ensure_index()
    docs = build_documents(limit)
    if not docs:
        return {"synced": 0, "ok": True}
    try:
        r = requests.post(
            f"{config().MEILI_HOST}/indexes/{config().MEILI_INDEX}/documents",
            headers=_headers(), json=docs, timeout=30,
        )
        ok = r.ok
    except Exception as exc:
        log.warning("meili push failed: %s", exc)
        ok = False
    log.info("meili sync: %d docs ok=%s", len(docs), ok)
    return {"synced": len(docs), "ok": ok}
