# CouponFind — System Architecture

> An AI-powered coupon search SaaS. **PHP** is the primary application backend.
> **Python** is a dedicated, isolated *Coupon Intelligence Engine* (discovery, extraction,
> validation, scoring, indexing). The two communicate only through **MySQL** and **Meilisearch** —
> never directly. Search **never** scrapes; it only reads pre-indexed data.

---

## 1. High-level topology

```
                           ┌──────────────────────────────────────────┐
                           │                Browser (SPA)              │
                           │  HTML5 + TailwindCSS + Vanilla JS         │
                           │  Landing · Auth · User App · Admin Panel  │
                           └───────────────┬──────────────────────────┘
                                           │ HTTPS (JSON / cookies)
                                           ▼
                  ┌───────────────────────────────────────────────────┐
                  │              PHP 8.4 Application Backend            │
                  │  (primary backend — owns the whole SaaS)           │
                  │                                                     │
                  │  Router → Middleware → Controllers → Services       │
                  │  Auth/JWT · RBAC · CSRF · RateLimit · Sessions      │
                  │  Search · Billing(Stripe/Razorpay) · Admin · APIs   │
                  └───┬───────────────┬───────────────┬────────────────┘
                      │               │               │
            ┌─────────▼───┐   ┌───────▼──────┐  ┌─────▼───────────┐
            │   MySQL 8    │   │  Meilisearch │  │     Redis       │
            │ source of    │   │  search index│  │ cache · rate    │
            │ truth (OLTP) │   │  (read-only  │  │ limit · session │
            │              │   │  for search) │  │ · queue         │
            └─────▲────────┘   └──────▲───────┘  └─────────────────┘
                  │ writes            │ sync
                  │                   │
       ┌──────────┴───────────────────┴───────────────────────────┐
       │           Python Coupon Intelligence Engine               │
       │  (isolated worker — NOT a request backend)                │
       │                                                           │
       │  Scheduler → Workers:                                     │
       │   discovery → rss/sitemap → crawler → extractor →         │
       │   AI structuring → validator → deduplicator → ranking →   │
       │   importer (MySQL) → meili_sync (Meilisearch)             │
       └───────────────────────────┬───────────────────────────────┘
                                    │ outbound HTTP
                  ┌─────────────────┼──────────────────┐
                  ▼                 ▼                  ▼
            Merchant sites      RSS feeds          AI Providers
            offer/promo pages   sitemaps           Groq→Gemini→OpenAI
```

## 2. Responsibility split (hard boundary)

| Concern | PHP | Python |
|---|---|---|
| Auth, sessions, RBAC, CSRF | ✅ | — |
| User & Admin dashboards | ✅ | — |
| Coupon **search** (read) | ✅ | — |
| Billing, subscriptions, webhooks | ✅ | — |
| Notifications, watchlists, alerts | ✅ | — |
| Coupon **discovery / crawl** | — | ✅ |
| RSS & sitemap processing | — | ✅ |
| Coupon extraction & AI structuring | — | ✅ |
| Validation, dedupe, scoring | — | ✅ |
| Import into MySQL | — | ✅ |
| Meilisearch index sync | — | ✅ |

PHP **reads** the data Python produces. The user-facing search path touches only
Meilisearch + Redis + MySQL — it issues **zero** outbound scraping calls, guaranteeing the
`<200ms` target.

## 3. Search request lifecycle (target < 200ms)

```
query "bst niek coupn"
   │
   ├─ 1. Redis cache lookup (normalized query hash)            ~1ms   (hit → return)
   ├─ 2. Query Understanding (PHP):                            ~2ms
   │      typo-normalize → merchant intent (alias map) →
   │      discount intent → time intent → filters
   ├─ 3. Meilisearch query (typo-tolerant, ranked)             ~5-40ms
   ├─ 4. Post-rank with coupon_scores + freshness              ~2ms
   ├─ 5. Log search (async via Redis queue) + usage meter
   └─ 6. Cache result (short TTL) → return JSON
```

Natural language, spelling mistakes, merchant/discount/time intent are handled in
`QueryUnderstanding` (deterministic + alias tables) with an optional AI rewrite for
hard queries via the provider fallback chain (Groq → Gemini → OpenAI).

## 4. Directory layout

```
couponfind/
├── ARCHITECTURE.md            · this document
├── README.md                  · setup & run guide
├── docker-compose.yml         · MySQL · Redis · Meilisearch · PHP · Python
├── .env.example               · all configuration (single source)
├── docker/                    · service Dockerfiles + nginx vhost
├── database/
│   ├── migrations/            · versioned schema (.sql)
│   └── seeds/                 · roles, plans, demo merchants/coupons
├── backend/                   · PHP primary backend
│   ├── public/                · front controller (index.php) + dev router
│   ├── config/                · config bootstrap
│   ├── routes/                · api.php route table
│   └── src/
│       ├── Core/              · Env, Database, Redis, Router, Request, Response, App
│       ├── Security/          · Jwt, Csrf, Password, RateLimiter, Rbac
│       ├── Middleware/        · Auth, Admin, RateLimit, Csrf
│       ├── Repositories/      · data access per entity
│       ├── Services/          · Search, Meilisearch, QueryUnderstanding, AI, Billing
│       └── Controllers/       · Auth, Search, Coupon, Plan, Subscription, Webhook, Admin
├── frontend/                  · HTML + Tailwind + vanilla JS
│   ├── index.html             · premium landing
│   ├── login / register       · auth
│   ├── app/                   · user dashboard
│   ├── admin/                 · super admin mission control
│   └── assets/{css,js}        · design system + app logic
└── engine/                    · Python coupon intelligence engine
    ├── requirements.txt
    ├── cli.py                 · entrypoint (run-once / pipeline / sync)
    └── couponengine/          · discovery, rss, sitemap, crawler, extractor,
                                 ai_structuring, validator, deduplicator,
                                 ranking, importer, meili_sync, scheduler, worker
```

## 5. Security architecture

- **AuthN:** Argon2id password hashing; JWT access tokens (short TTL) + opaque refresh
  tokens stored hashed in DB; HttpOnly + Secure + SameSite cookies for the web app.
- **AuthZ:** RBAC with `roles` / `permissions` / `role_permissions`; route guards via
  `AuthMiddleware` + `AdminMiddleware`.
- **CSRF:** double-submit token for cookie-authenticated state-changing requests.
- **Injection:** PDO prepared statements everywhere; strict input validation.
- **XSS:** output encoding on render; JSON API by default; CSP header.
- **Rate limiting:** Redis sliding-window per IP + per user + per route class.
- **Secrets:** all via environment (`.env`), never committed.
- **Audit:** `audit_logs` capture admin/security-relevant actions.
- **Webhooks:** Stripe & Razorpay signatures verified before processing.

## 6. Data design principles

- Source of truth is MySQL (InnoDB, utf8mb4). Designed for millions of coupons:
  covering indexes on hot search/filter columns, foreign keys with sensible cascade,
  soft-expiry via `valid_until` + `status`, and denormalized `coupon_scores` for ranking.
- Meilisearch holds a flattened, search-optimized projection of active coupons.
- Redis holds ephemeral state (cache, rate-limit counters, sessions, queues).

## 7. Deployment

- `docker-compose up` brings up MySQL, Redis, Meilisearch, the PHP app (nginx + php-fpm),
  and the Python engine worker/scheduler.
- Migrations + seeds run on first boot. Meilisearch index is created and configured by the
  Python `meili_sync` bootstrap.
- For production: terminate TLS at a load balancer, run php-fpm horizontally, run the
  Python engine as a separate scheduled deployment, and point all services at managed
  MySQL / Redis / Meilisearch.
```
