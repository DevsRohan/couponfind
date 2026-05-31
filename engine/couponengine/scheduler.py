"""Lightweight in-process scheduler.

Runs the engine on fixed intervals (configurable via env) and continuously
drains the job queue created by the admin panel. This is the default command
the Docker `engine` service runs.
"""
from __future__ import annotations

import logging
import time

from . import meili_sync, pipeline, ranking, validator, worker
from .config import config

log = logging.getLogger("couponengine.scheduler")


def run() -> None:
    cfg = config()
    log.info(
        "scheduler started (discovery=%dm validate=%dm sync=%dm)",
        cfg.SCHEDULE_DISCOVERY, cfg.SCHEDULE_VALIDATE, cfg.SCHEDULE_SYNC,
    )

    last_discovery = 0.0
    last_validate = 0.0
    last_sync = 0.0

    # Ensure the index exists immediately on boot.
    try:
        meili_sync.ensure_index()
        meili_sync.run()
    except Exception as exc:  # pragma: no cover
        log.warning("initial sync skipped: %s", exc)

    while True:
        now = time.time()
        try:
            # Always drain admin-dispatched jobs first (low latency).
            handled = worker.drain(max_jobs=20)
            if handled:
                log.info("drained %d queued job(s)", handled)

            if now - last_discovery >= cfg.SCHEDULE_DISCOVERY * 60:
                log.info("scheduled discovery run")
                pipeline.discover()
                ranking.run()
                last_discovery = now

            if now - last_validate >= cfg.SCHEDULE_VALIDATE * 60:
                log.info("scheduled validation run")
                validator.run()
                ranking.run()
                last_validate = now

            if now - last_sync >= cfg.SCHEDULE_SYNC * 60:
                log.info("scheduled meili sync")
                meili_sync.run()
                last_sync = now

        except Exception:  # pragma: no cover
            log.exception("scheduler tick error")

        time.sleep(15)
