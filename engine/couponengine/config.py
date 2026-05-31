"""Environment configuration for the engine.

Reads from the process environment first, then falls back to a .env file at
the repository root (so it works both in Docker and locally).
"""
from __future__ import annotations

import os
from pathlib import Path
from functools import lru_cache


def _load_dotenv() -> None:
    # Look for .env at repo root (engine/ -> repo root is parent of parent).
    candidates = [
        Path(__file__).resolve().parents[2] / ".env",
        Path.cwd() / ".env",
    ]
    for path in candidates:
        if path.is_file():
            try:
                from dotenv import load_dotenv
                load_dotenv(path)
            except Exception:
                # Minimal manual parse if python-dotenv is unavailable.
                for line in path.read_text().splitlines():
                    line = line.strip()
                    if not line or line.startswith("#") or "=" not in line:
                        continue
                    k, v = line.split("=", 1)
                    os.environ.setdefault(k.strip(), v.strip())
            break


_load_dotenv()


def env(key: str, default: str | None = None) -> str | None:
    val = os.environ.get(key)
    return val if val not in (None, "") else default


def env_int(key: str, default: int) -> int:
    try:
        return int(env(key, str(default)))
    except (TypeError, ValueError):
        return default


class Config:
    # MySQL
    DB_HOST = env("DB_HOST", "127.0.0.1")
    DB_PORT = env_int("DB_PORT", 3306)
    DB_NAME = env("DB_DATABASE", "couponfind")
    DB_USER = env("DB_USERNAME", "couponfind")
    DB_PASS = env("DB_PASSWORD", "")

    # Meilisearch
    MEILI_HOST = (env("MEILI_HOST", "http://127.0.0.1:7700") or "").rstrip("/")
    MEILI_KEY = env("MEILI_MASTER_KEY", "")
    MEILI_INDEX = env("MEILI_INDEX", "coupons")

    # AI providers (fallback chain)
    AI_ORDER = [p.strip() for p in (env("AI_PROVIDER_ORDER", "groq,gemini,openai") or "").split(",") if p.strip()]
    GROQ_API_KEY = env("GROQ_API_KEY", "")
    GROQ_MODEL = env("GROQ_MODEL", "llama-3.3-70b-versatile")
    GEMINI_API_KEY = env("GEMINI_API_KEY", "")
    GEMINI_MODEL = env("GEMINI_MODEL", "gemini-1.5-flash")
    OPENAI_API_KEY = env("OPENAI_API_KEY", "")
    OPENAI_MODEL = env("OPENAI_MODEL", "gpt-4o-mini")

    # Crawler behaviour
    USER_AGENT = env("ENGINE_USER_AGENT", "CouponFindBot/1.0 (+https://couponfind.example)")
    CONCURRENCY = env_int("ENGINE_CRAWL_CONCURRENCY", 4)
    REQUEST_TIMEOUT = env_int("ENGINE_REQUEST_TIMEOUT", 20)

    # Schedule intervals (minutes)
    SCHEDULE_DISCOVERY = env_int("ENGINE_SCHEDULE_DISCOVERY_MINUTES", 180)
    SCHEDULE_VALIDATE = env_int("ENGINE_SCHEDULE_VALIDATE_MINUTES", 360)
    SCHEDULE_SYNC = env_int("ENGINE_SCHEDULE_SYNC_MINUTES", 15)


@lru_cache(maxsize=1)
def config() -> Config:
    return Config()
