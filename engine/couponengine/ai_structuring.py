"""AI structuring with provider fallback chain (Groq -> Gemini -> OpenAI).

Refines a low-confidence heuristic extraction into clean structured fields.
Entirely optional: if no provider key is configured, the heuristic result is
returned unchanged. Never raises — failures degrade gracefully.
"""
from __future__ import annotations

import json
import logging
import re

import requests

from .config import config

log = logging.getLogger("couponengine.ai")

_SYSTEM = (
    "You extract coupon data from text. Respond with ONLY compact JSON: "
    '{"title": string, "code": string|null, "discount_type": "percent"|"amount"|"free_shipping"|"other", '
    '"discount_value": number|null}. Fix obvious spelling. No prose.'
)


def _extract_json(text: str) -> dict | None:
    text = re.sub(r"```(?:json)?", "", text)
    m = re.search(r"\{.*\}", text, re.S)
    if not m:
        return None
    try:
        data = json.loads(m.group(0))
        return data if isinstance(data, dict) else None
    except json.JSONDecodeError:
        return None


def _call_openai_compatible(url: str, key: str, model: str, user: str) -> str | None:
    if not key:
        return None
    try:
        resp = requests.post(
            url,
            headers={"Authorization": f"Bearer {key}", "Content-Type": "application/json"},
            json={
                "model": model,
                "temperature": 0,
                "messages": [
                    {"role": "system", "content": _SYSTEM},
                    {"role": "user", "content": user},
                ],
            },
            timeout=15,
        )
        if resp.status_code >= 400:
            return None
        return resp.json()["choices"][0]["message"]["content"]
    except Exception as exc:  # pragma: no cover
        log.info("ai openai-compat error: %s", exc)
        return None


def _call_gemini(model: str, key: str, user: str) -> str | None:
    if not key:
        return None
    try:
        url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={key}"
        resp = requests.post(
            url,
            json={
                "systemInstruction": {"parts": [{"text": _SYSTEM}]},
                "contents": [{"parts": [{"text": user}]}],
                "generationConfig": {"temperature": 0},
            },
            timeout=15,
        )
        if resp.status_code >= 400:
            return None
        return resp.json()["candidates"][0]["content"]["parts"][0]["text"]
    except Exception as exc:  # pragma: no cover
        log.info("ai gemini error: %s", exc)
        return None


def _complete(user_text: str) -> dict | None:
    cfg = config()
    for provider in cfg.AI_ORDER:
        text = None
        if provider == "groq":
            text = _call_openai_compatible("https://api.groq.com/openai/v1/chat/completions", cfg.GROQ_API_KEY, cfg.GROQ_MODEL, user_text)
        elif provider == "openai":
            text = _call_openai_compatible("https://api.openai.com/v1/chat/completions", cfg.OPENAI_API_KEY, cfg.OPENAI_MODEL, user_text)
        elif provider == "gemini":
            text = _call_gemini(cfg.GEMINI_MODEL, cfg.GEMINI_API_KEY, user_text)
        if text:
            parsed = _extract_json(text)
            if parsed:
                log.info("ai structuring via %s", provider)
                return parsed
    return None


def structure(coupon: dict) -> dict:
    """Refine a coupon dict via AI if confidence is low and a provider exists."""
    if coupon.get("confidence", 0) >= 0.75:
        return coupon
    if not any([config().GROQ_API_KEY, config().GEMINI_API_KEY, config().OPENAI_API_KEY]):
        return coupon

    source_text = f"{coupon.get('title', '')}. {coupon.get('description', '')}"[:1500]
    refined = _complete(source_text)
    if not refined:
        return coupon

    coupon = dict(coupon)
    if refined.get("title"):
        coupon["title"] = str(refined["title"])[:255]
    if refined.get("code"):
        coupon["code"] = str(refined["code"]).upper()[:80]
    if refined.get("discount_type") in ("percent", "amount", "free_shipping", "other"):
        coupon["discount_type"] = refined["discount_type"]
    if isinstance(refined.get("discount_value"), (int, float)):
        coupon["discount_value"] = float(refined["discount_value"])
    coupon["confidence"] = max(coupon.get("confidence", 0), 0.8)
    return coupon
