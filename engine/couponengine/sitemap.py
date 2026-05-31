"""Sitemap discovery — finds promo/coupon/deal URLs from XML sitemaps."""
from __future__ import annotations

import logging
import re

from bs4 import BeautifulSoup

from .crawler import fetch

log = logging.getLogger("couponengine.sitemap")

_KEYWORDS = ("coupon", "promo", "deal", "offer", "discount", "sale", "voucher")


def discover(url: str, limit: int = 100) -> list[dict]:
    """Return candidate URLs whose loc looks coupon/deal related.

    Handles both sitemap indexes (nested <sitemap>) and url sets (<url>).
    """
    text = fetch(url)
    if not text:
        return []

    candidates: list[dict] = []
    try:
        soup = BeautifulSoup(text, "xml")

        # Sitemap index -> recurse one level.
        sitemaps = [s.get_text(strip=True) for s in soup.find_all("loc") if s.find_parent("sitemap")]
        if sitemaps:
            for sm in sitemaps[:5]:
                candidates.extend(discover(sm, limit=limit // 2))
                if len(candidates) >= limit:
                    break
            return candidates[:limit]

        for loc in soup.find_all("loc"):
            href = loc.get_text(strip=True)
            if href and any(k in href.lower() for k in _KEYWORDS):
                candidates.append({"url": href, "title": _slug_to_title(href), "text": ""})
                if len(candidates) >= limit:
                    break
    except Exception as exc:  # pragma: no cover
        log.info("sitemap parse error %s: %s", url, exc)
    return candidates


def _slug_to_title(url: str) -> str:
    slug = re.sub(r"https?://[^/]+/", "", url).strip("/").split("/")[-1]
    slug = re.sub(r"[-_]+", " ", slug)
    return slug[:120].title()
