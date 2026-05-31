#!/usr/bin/env python3
"""CouponFind Coupon Intelligence Engine — command-line entrypoint.

Usage:
    python cli.py schedule        # run scheduler loop (default in Docker)
    python cli.py run-once        # run the full pipeline once
    python cli.py discover        # crawl sources -> extract -> import
    python cli.py validate        # validate active/unverified coupons
    python cli.py score           # recompute coupon scores
    python cli.py sync            # push active coupons into Meilisearch
    python cli.py worker          # drain the engine_jobs queue once
    python cli.py health          # check DB + Meilisearch connectivity
"""
from __future__ import annotations

import json
import logging
import sys


def _setup_logging() -> None:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)-7s %(name)s | %(message)s",
        datefmt="%H:%M:%S",
    )


def main(argv: list[str]) -> int:
    _setup_logging()
    command = argv[1] if len(argv) > 1 else "schedule"

    # Imported lazily so `health`/`--help` work even if deps are partial.
    from couponengine import meili_sync, pipeline, ranking, validator, worker
    from couponengine.db import db

    if command == "schedule":
        from couponengine import scheduler
        scheduler.run()
        return 0

    if command == "run-once":
        print(json.dumps(pipeline.run_full(), indent=2, default=str))
        return 0

    if command == "discover":
        print(json.dumps(pipeline.discover(), indent=2, default=str))
        return 0

    if command == "validate":
        http = "--http" in argv
        print(json.dumps(validator.run(http_check=http), indent=2, default=str))
        return 0

    if command == "score":
        print(json.dumps({"scored": ranking.run()}, indent=2))
        return 0

    if command == "sync":
        print(json.dumps(meili_sync.run(), indent=2))
        return 0

    if command == "worker":
        handled = worker.drain()
        print(json.dumps({"handled": handled}, indent=2))
        return 0

    if command == "health":
        print(json.dumps({
            "database": db().healthy(),
            "meilisearch": meili_sync.is_healthy(),
        }, indent=2))
        return 0

    print(__doc__)
    return 1


if __name__ == "__main__":
    sys.exit(main(sys.argv))
