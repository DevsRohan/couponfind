"""CouponFind — Python Coupon Intelligence Engine.

An isolated worker system responsible for coupon discovery, extraction,
AI structuring, validation, deduplication, scoring, import into MySQL, and
synchronization into Meilisearch. It does NOT serve web requests — PHP owns
the application. The two communicate only through MySQL + Meilisearch.
"""

__version__ = "1.0.0"
