"""HTTP fetching with a polite user-agent, timeouts, and robots awareness."""
from __future__ import annotations

import logging
import urllib.robotparser as robotparser
from urllib.parse import urlparse

import requests

from .config import config

log = logging.getLogger("couponengine.crawler")

_robots_cache: dict[str, robotparser.RobotFileParser] = {}


def _session() -> requests.Session:
    s = requests.Session()
    s.headers.update({"User-Agent": config().USER_AGENT, "Accept-Language": "en-US,en;q=0.9"})
    return s


def allowed_by_robots(url: str) -> bool:
    """Best-effort robots.txt compliance. Fails open on fetch errors."""
    try:
        parsed = urlparse(url)
        base = f"{parsed.scheme}://{parsed.netloc}"
        rp = _robots_cache.get(base)
        if rp is None:
            rp = robotparser.RobotFileParser()
            rp.set_url(base + "/robots.txt")
            try:
                rp.read()
            except Exception:
                _robots_cache[base] = rp
                return True
            _robots_cache[base] = rp
        return rp.can_fetch(config().USER_AGENT, url)
    except Exception:
        return True


def fetch(url: str, *, respect_robots: bool = True) -> str | None:
    """Return page text, or None on failure / disallowed."""
    if respect_robots and not allowed_by_robots(url):
        log.info("robots.txt disallows %s", url)
        return None
    try:
        resp = _session().get(url, timeout=config().REQUEST_TIMEOUT)
        if resp.status_code >= 400:
            log.info("fetch %s -> HTTP %s", url, resp.status_code)
            return None
        ctype = resp.headers.get("Content-Type", "")
        if "html" not in ctype and "xml" not in ctype and "text" not in ctype and ctype:
            return None
        return resp.text
    except requests.RequestException as exc:
        log.info("fetch error %s: %s", url, exc)
        return None


def head_ok(url: str) -> bool:
    """Lightweight liveness check used by the validator."""
    try:
        resp = _session().head(url, timeout=config().REQUEST_TIMEOUT, allow_redirects=True)
        if resp.status_code in (405, 403):  # some servers reject HEAD
            resp = _session().get(url, timeout=config().REQUEST_TIMEOUT, stream=True)
        return resp.status_code < 400
    except requests.RequestException:
        return False
