"""End-to-end coupon intelligence pipeline.

discover (sources) -> extract -> AI structure -> dedupe -> import (MySQL)
-> validate -> score -> sync (Meilisearch)
"""
from __future__ import annotations

import logging

from . import ai_structuring, deduplicator, extractor, meili_sync, ranking, rss, sitemap, validator
from .crawler import fetch
from .db import db
from .importer import import_coupons

log = logging.getLogger("couponengine.pipeline")


def _load_sources(only_active: bool = True) -> list[dict]:
    sql = (
        "SELECT cs.id, cs.merchant_id, cs.type, cs.url, m.slug AS merchant_slug, m.name AS merchant_name "
        "FROM coupon_sources cs LEFT JOIN merchants m ON m.id = cs.merchant_id"
    )
    if only_active:
        sql += " WHERE cs.is_active = 1"
    return db().query(sql)


def _candidates_for_source(source: dict) -> list[dict]:
    stype = source["type"]
    url = source["url"]
    try:
        if stype == "rss":
            return rss.discover(url)
        if stype == "sitemap":
            return sitemap.discover(url)
        # offer_page / promo_page / newsletter / user_submission -> fetch HTML
        html = fetch(url)
        if not html:
            return []
        return [{"url": url, "title": source.get("merchant_name") or "", "html": html}]
    except Exception as exc:  # pragma: no cover
        log.info("candidate error for %s: %s", url, exc)
        return []


def discover(source_filter: dict | None = None) -> dict:
    """Crawl sources, extract + structure coupons, dedupe, and import."""
    sources = _load_sources()
    if source_filter and source_filter.get("merchant_id"):
        mid = int(source_filter["merchant_id"])
        sources = [s for s in sources if s.get("merchant_id") == mid]
    if source_filter and source_filter.get("source_id"):
        sid = int(source_filter["source_id"])
        sources = [s for s in sources if s["id"] == sid]

    total = {"sources": 0, "candidates": 0, "coupons": 0, "inserted": 0, "updated": 0}

    for source in sources:
        total["sources"] += 1
        candidates = _candidates_for_source(source)
        total["candidates"] += len(candidates)

        coupons: list[dict] = []
        for cand in candidates:
            for coupon in extractor.extract(cand):
                coupon = ai_structuring.structure(coupon)
                coupon["merchant_id"] = source.get("merchant_id")
                coupon["merchant_slug"] = source.get("merchant_slug") or ""
                coupon["merchant_name"] = source.get("merchant_name")
                coupons.append(coupon)

        coupons = deduplicator.dedupe(coupons)
        total["coupons"] += len(coupons)

        if coupons:
            res = import_coupons(coupons, default_merchant_id=source.get("merchant_id"), source_id=source["id"])
            total["inserted"] += res["inserted"]
            total["updated"] += res["updated"]

        _mark_source(source["id"], ok=True)

    log.info("discover: %s", total)
    return total


def _mark_source(source_id: int, ok: bool, error: str | None = None) -> None:
    try:
        if ok:
            db().execute(
                "UPDATE coupon_sources SET last_crawled_at=NOW(), last_status='ok', last_error=NULL, "
                "success_count=success_count+1 WHERE id=%s",
                (source_id,),
            )
        else:
            db().execute(
                "UPDATE coupon_sources SET last_crawled_at=NOW(), last_status='error', last_error=%s, "
                "failure_count=failure_count+1 WHERE id=%s",
                ((error or "")[:255], source_id),
            )
    except Exception:
        pass


def run_full(http_check: bool = False) -> dict:
    """Run the entire pipeline once."""
    result = {}
    result["discover"] = discover()
    result["validate"] = validator.run(http_check=http_check)
    result["score"] = ranking.run()
    result["sync"] = meili_sync.run()
    log.info("pipeline complete: %s", result)
    return result
