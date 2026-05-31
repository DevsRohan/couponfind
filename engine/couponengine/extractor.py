"""Heuristic coupon extraction from HTML / text.

Pulls structured coupon candidates (title, code, discount type/value) using
robust regex + DOM heuristics. This is the deterministic baseline; the
AI structuring layer refines low-confidence extractions when configured.
"""
from __future__ import annotations

import re

from bs4 import BeautifulSoup

# Uppercase alnum tokens 4-20 chars that look like coupon codes.
_CODE_RE = re.compile(r"\b([A-Z0-9]{4,20})\b")
_PERCENT_RE = re.compile(r"(\d{1,3})\s*%")
_AMOUNT_RE = re.compile(r"[$€£₹]\s*(\d{1,5})(?:\.\d{1,2})?")
_FREE_SHIP_RE = re.compile(r"free\s+shipping", re.I)

# Words that look uppercase but aren't codes.
_CODE_STOP = {
    "HTML", "HTTPS", "JSON", "FREE", "SHOP", "SAVE", "CODE", "DEAL", "SALE",
    "OFFER", "TERMS", "ONLY", "NEW", "NULL", "TRUE", "FALSE", "MENU", "HOME",
}


def text_from_html(html: str) -> str:
    soup = BeautifulSoup(html, "lxml")
    for tag in soup(["script", "style", "noscript"]):
        tag.decompose()
    return re.sub(r"\s+", " ", soup.get_text(" ")).strip()


def _detect_discount(text: str) -> tuple[str, float | None]:
    m = _PERCENT_RE.search(text)
    if m:
        val = float(m.group(1))
        if 1 <= val <= 99:
            return "percent", val
    m = _AMOUNT_RE.search(text)
    if m:
        return "amount", float(m.group(1))
    if _FREE_SHIP_RE.search(text):
        return "free_shipping", None
    return "other", None


def _find_code(text: str) -> str | None:
    # Prefer codes mentioned near "code"/"coupon".
    near = re.search(r"(?:code|coupon)\s*[:\-]?\s*([A-Z0-9]{4,20})", text, re.I)
    if near:
        cand = near.group(1).upper()
        if cand not in _CODE_STOP:
            return cand
    for cand in _CODE_RE.findall(text):
        if cand in _CODE_STOP or cand.isdigit():
            continue
        # Mixed letters+digits OR clearly promo-ish uppercase word.
        if any(c.isdigit() for c in cand) and any(c.isalpha() for c in cand):
            return cand
    return None


def extract(candidate: dict) -> list[dict]:
    """Turn a raw discovery candidate into 0..n structured coupons."""
    title = (candidate.get("title") or "").strip()
    body = candidate.get("text") or ""
    html = candidate.get("html")
    if html and not body:
        body = text_from_html(html)

    blob = f"{title}. {body}"[:2000]
    if not blob.strip(" ."):
        return []

    dtype, dval = _detect_discount(blob)
    code = _find_code(blob)

    if not title:
        title = (body[:80] + "…") if body else "Coupon offer"

    ctype = "code" if code else ("free_shipping" if dtype == "free_shipping" else "deal")

    coupon = {
        "title": title[:255],
        "description": body[:480] or None,
        "code": code,
        "type": ctype,
        "discount_type": dtype,
        "discount_value": dval,
        "landing_url": candidate.get("url"),
        "confidence": 0.75 if code else (0.6 if dval else 0.4),
    }
    return [coupon]
