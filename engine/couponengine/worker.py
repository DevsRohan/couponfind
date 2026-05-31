"""Background worker that drains the engine_jobs queue.

Jobs are created by the PHP admin panel (and mirrored onto a Redis list).
This worker is DB-driven (durable) and idempotent; the Redis mirror is only a
low-latency wakeup hint. Supported job types:
  discover | crawl | validate | score | sync | import
"""
from __future__ import annotations

import json
import logging

from . import meili_sync, pipeline, ranking, validator
from .db import db

log = logging.getLogger("couponengine.worker")


def _claim_job() -> dict | None:
    """Atomically claim the next queued job."""
    row = db().first(
        "SELECT id FROM engine_jobs WHERE status='queued' ORDER BY id ASC LIMIT 1"
    )
    if not row:
        return None
    affected = db().execute(
        "UPDATE engine_jobs SET status='running', started_at=NOW(), attempts=attempts+1 "
        "WHERE id=%s AND status='queued'",
        (row["id"],),
    )
    if affected == 0:
        return None  # someone else grabbed it
    return db().first("SELECT * FROM engine_jobs WHERE id=%s", (row["id"],))


def _run_job(job: dict) -> dict:
    jtype = job["type"]
    payload = {}
    if job.get("payload"):
        try:
            payload = json.loads(job["payload"]) if isinstance(job["payload"], str) else job["payload"]
        except (json.JSONDecodeError, TypeError):
            payload = {}

    if jtype in ("discover", "crawl", "import"):
        return pipeline.discover(source_filter=payload or None)
    if jtype == "validate":
        return validator.run(http_check=bool(payload.get("http_check")))
    if jtype == "score":
        return {"scored": ranking.run()}
    if jtype == "sync":
        return meili_sync.run()
    raise ValueError(f"Unknown job type: {jtype}")


def process_once() -> bool:
    """Process a single job. Returns True if a job was handled."""
    job = _claim_job()
    if not job:
        return False
    log.info("running job #%s (%s)", job["id"], job["type"])
    try:
        result = _run_job(job)
        db().execute(
            "UPDATE engine_jobs SET status='done', finished_at=NOW(), error=NULL WHERE id=%s",
            (job["id"],),
        )
        log.info("job #%s done: %s", job["id"], result)
    except Exception as exc:
        log.exception("job #%s failed", job["id"])
        db().execute(
            "UPDATE engine_jobs SET status='failed', finished_at=NOW(), error=%s WHERE id=%s",
            (str(exc)[:255], job["id"]),
        )
    return True


def drain(max_jobs: int = 100) -> int:
    count = 0
    while count < max_jobs and process_once():
        count += 1
    return count
