"""MySQL access layer for the engine (PyMySQL, prepared statements)."""
from __future__ import annotations

import logging
from contextlib import contextmanager
from typing import Any, Iterable

import pymysql
from pymysql.cursors import DictCursor

from .config import config

log = logging.getLogger("couponengine.db")


class Database:
    def __init__(self) -> None:
        self._conn: pymysql.connections.Connection | None = None

    def connect(self) -> pymysql.connections.Connection:
        cfg = config()
        if self._conn is not None and self._conn.open:
            return self._conn
        self._conn = pymysql.connect(
            host=cfg.DB_HOST,
            port=cfg.DB_PORT,
            user=cfg.DB_USER,
            password=cfg.DB_PASS,
            database=cfg.DB_NAME,
            charset="utf8mb4",
            cursorclass=DictCursor,
            autocommit=True,
            connect_timeout=10,
        )
        return self._conn

    @contextmanager
    def cursor(self):
        conn = self.connect()
        try:
            conn.ping(reconnect=True)
        except Exception:
            self._conn = None
            conn = self.connect()
        cur = conn.cursor()
        try:
            yield cur
        finally:
            cur.close()

    def query(self, sql: str, params: Iterable[Any] | None = None) -> list[dict]:
        with self.cursor() as cur:
            cur.execute(sql, tuple(params or ()))
            return list(cur.fetchall())

    def first(self, sql: str, params: Iterable[Any] | None = None) -> dict | None:
        rows = self.query(sql, params)
        return rows[0] if rows else None

    def scalar(self, sql: str, params: Iterable[Any] | None = None) -> Any:
        row = self.first(sql, params)
        if not row:
            return None
        return next(iter(row.values()))

    def execute(self, sql: str, params: Iterable[Any] | None = None) -> int:
        with self.cursor() as cur:
            cur.execute(sql, tuple(params or ()))
            return cur.rowcount

    def insert(self, sql: str, params: Iterable[Any] | None = None) -> int:
        with self.cursor() as cur:
            cur.execute(sql, tuple(params or ()))
            return cur.lastrowid

    def healthy(self) -> bool:
        try:
            return self.scalar("SELECT 1") == 1
        except Exception as exc:  # pragma: no cover
            log.warning("DB health check failed: %s", exc)
            return False


_db: Database | None = None


def db() -> Database:
    global _db
    if _db is None:
        _db = Database()
    return _db
