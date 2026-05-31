"""RSS / Atom feed discovery — extracts candidate coupon entries."""
from __future__ import annotations

import logging

import feedparser

log = logging.getLogger("couponengine.rss")


def discover(url: str) -> list[dict]:
    """Parse an RSS/Atom feed and return raw candidate entries."""
    out: list[dict] = []
    try:
        feed = feedparser.parse(url)
        for entry in feed.entries[:50]:
            title = getattr(entry, "title", "") or ""
            summary = getattr(entry, "summary", "") or getattr(entry, "description", "") or ""
            link = getattr(entry, "link", "") or url
            text = f"{title}. {summary}"
            out.append({
                "url": link,
                "title": title.strip(),
                "text": text.strip(),
            })
    except Exception as exc:  # pragma: no cover
        log.info("rss parse error %s: %s", url, exc)
    return out
