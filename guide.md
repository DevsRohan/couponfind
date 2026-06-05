# CouponFind — AI Coupon Search SaaS

A production-grade, AI-powered coupon search platform. **PHP** is the primary
application backend (auth, dashboards, search, billing, admin, APIs). **Python**
is an isolated *Coupon Intelligence Engine* (discovery, extraction, validation,
scoring, indexing). The two communicate only through **MySQL** + **Meilisearch** —
search reads pre-indexed data and **never scrapes**, so it stays well under 200ms.

> Architecture deep-dive: see [`ARCHITECTURE.md`](ARCHITECTURE.md).

```
Browser (HTML + Tailwind + vanilla JS)
        │  JSON / cookies
        ▼
PHP 8 backend  ──►  MySQL (source of truth)
   router · RBAC · JWT · CSRF · rate limit       ▲   writes
   search · billing(Stripe/Razorpay) · admin     │
        │                                         │
        ├──►  Meilisearch (typo-tolerant index) ◄─┤  sync
        └──►  Redis (cache · rate limit · queue)  │
                                                  │
Python engine ────────────────────────────────────┘
   discover → rss/sitemap → crawl → extract → AI structure
   → validate → dedupe → score → import → meili_sync
```

## Tech stack

| Layer | Technology |
|---|---|
| Frontend | HTML5, TailwindCSS, vanilla JavaScript |
| App backend | PHP 8.2+ (zero-framework micro-kernel, PSR-4) |
| Intelligence engine | Python 3.11+ |
| Database | MySQL 8 (InnoDB, utf8mb4) |
| Search | Meilisearch v1.10 |
| Cache / queue | Redis 7 |
| Payments | Stripe + Razorpay (REST, signature-verified webhooks) |
| AI | Groq → Gemini → OpenAI (auto-failover chain) |

The PHP backend intentionally has **no third-party Composer dependencies** — JWT
(HS256), Redis client (phpredis or raw-socket fallback), Meilisearch/Stripe/
Razorpay/AI clients are all implemented over `ext-curl`/`ext-pdo`. This keeps the
app runnable anywhere with just `composer dump-autoload`.

---

## Quick start (Docker — full stack)

```bash
cp .env.example .env          # fill in API keys for Stripe/Razorpay/AI if you have them
docker compose up --build
```

This brings up MySQL (schema + seeds auto-loaded), Redis, Meilisearch, the PHP
app (nginx + php-fpm), and the Python engine scheduler. Then open:

- App: **http://localhost:8080**
- API health: **http://localhost:8080/api/health**

## Quick start (local, no Docker)

You need PHP 8.2+, MySQL 8, Redis, and Meilisearch running locally (or point
`.env` at managed instances).

```bash
# 1. Configure
cp .env.example .env          # set DB/Redis/Meili hosts + JWT_SECRET

# 2. Backend autoloader
cd backend && composer dump-autoload && cd ..

# 3. Database (schema + seeds)
mysql -u root -p < database/migrations/001_init_schema.sql
mysql -u root -p couponfind < database/seeds/001_seed.sql

# 4. Run the app (serves frontend + API on one port)
php -S 127.0.0.1:8080 backend/public/router.php

# 5. Run the Python engine (separate terminal)
cd engine
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
python cli.py sync         # build the Meilisearch index from seeded coupons
python cli.py run-once     # full pipeline once (discover→validate→score→sync)
# or: python cli.py schedule   # continuous scheduler + job worker
```

### Seeded demo accounts

| Role | Email | Password |
|---|---|---|
| Super Admin | `admin@couponfind.local` | `Admin@12345` |
| User | `user@couponfind.local` | `User@12345` |

> Change these immediately in any non-local environment.

---

## Using the platform

- **Landing / search** (`/`): try `best amazon coupon today`, `hostinger discount`,
  `nike offer`, `best vpn deal`, or even the misspelled `bst niek coupn`.
- **User dashboard** (`/app`): AI search, saved coupons, watchlists, deal alerts,
  notifications, search history, billing & plans, invoices, referrals, profile.
  Press <kbd>Ctrl</kbd>/<kbd>⌘</kbd>+<kbd>K</kbd> for the command palette.
- **Super Admin** (`/admin`): dashboard, users, plans CRUD, subscriptions
  (assign custom / lifetime / override limits), revenue, coupons, merchants,
  coupon sources, search analytics, AI control center, engine control
  (force crawl / validate / reindex), feature flags, logs & audit, system health,
  settings.

## Subscription plans (seeded)

| Plan | Price | Quota |
|---|---|---|
| Free | $0 | 10 searches / day |
| Starter | $5 / mo | 100 / month |
| Pro | $10 / mo | 200 / month |
| Yearly Pro | $49 / yr | 100 / day |
| Yearly Elite | $99 / yr | 200 / day |

Super Admin can create/edit/delete plans, assign custom or lifetime plans, and
override per-subscription limits.

---

## API overview

All endpoints are under `/api`. Responses use a consistent envelope:
`{ "success": bool, "message": string, "data": ... }`.

| Area | Examples |
|---|---|
| Auth | `POST /api/auth/register`, `/login`, `/refresh`, `/logout`, `/forgot-password`, `/reset-password`; `GET /api/auth/me` |
| Search | `POST /api/search`, `GET /api/search/suggest` |
| Catalog | `GET /api/plans`, `/merchants`, `/merchants/{slug}`, `/coupons/featured`, `/coupons/{id}` |
| User | `GET/POST /api/me/...` (saved, watchlist, alerts, notifications, history, invoices, **invoice PDF**, **recommendations**, profile, referrals) |
| Billing | `GET /api/subscription`, `POST /api/subscription/checkout|cancel` |
| Webhooks | `POST /api/webhooks/stripe`, `/api/webhooks/razorpay` |
| Admin | `GET/POST/PUT/DELETE /api/admin/...` (users, plans, subscriptions, **payments + refunds**, merchants, coupons, sources, analytics, ai, engine, flags, settings, logs, health) |

Auth uses short-lived **JWT access tokens** (Bearer or HttpOnly cookie) +
rotating opaque **refresh tokens** stored hashed. Cookie-based mutating requests
are CSRF-protected (double-submit); Bearer API calls are exempt by design.

## Email (SMTP)

Configure `MAIL_*` in `.env` to enable real email (password-reset links + deal
alerts). Leave `MAIL_HOST` empty to disable sending (messages are logged; the
reset flow still works via the link returned when `APP_DEBUG=true`). Verify with:

```bash
php backend/console.php mail:test you@example.com
```

## Background jobs (cron)

The Python engine handles coupon discovery/validation/scoring/indexing. Two
PHP-side periodic tasks complete the loop — add these to crontab on your VPS:

```cron
# Notify users when newly-imported coupons match their alerts/watchlists
*/10 * * * *  php /var/www/couponfind/backend/console.php alerts:dispatch
# Expire coupons past their valid_until
*/30 * * * *  php /var/www/couponfind/backend/console.php coupons:expire
```

## Python engine commands

```bash
python cli.py schedule    # scheduler loop + job-queue worker (default in Docker)
python cli.py run-once    # full pipeline once
python cli.py discover    # crawl sources → extract → AI structure → import
python cli.py validate    # validate active/unverified coupons (--http for liveness)
python cli.py score       # recompute coupon scores
python cli.py sync        # push active coupons into Meilisearch
python cli.py worker      # drain the engine_jobs queue once
python cli.py health      # check DB + Meilisearch connectivity
```

The admin panel's "Engine Control" dispatches jobs into the `engine_jobs` table
(mirrored onto a Redis list); the engine's worker drains them.

---

## Security

- Argon2id (bcrypt fallback) password hashing; rotating hashed refresh tokens.
- Stateless HS256 JWT access tokens; HttpOnly + SameSite cookies for the web app.
- RBAC (`roles` / `permissions` / `role_permissions`) enforced by middleware.
- PDO prepared statements everywhere (no string-built SQL with user input).
- Redis sliding/fixed-window rate limiting (fails open for availability).
- CSRF double-submit for cookie sessions; security headers on every response.
- Stripe & Razorpay webhook signatures verified against the raw body.
- Audit logging for admin/security-relevant actions; API request logging.

## Project layout

```
couponfind/
├── ARCHITECTURE.md          system design
├── docker-compose.yml       MySQL · Redis · Meilisearch · PHP/nginx · engine
├── .env.example             all configuration
├── database/{migrations,seeds}
├── backend/                 PHP primary backend (Core, Security, Middleware,
│                            Repositories, Services, Controllers, routes)
├── frontend/                landing · auth · /app · /admin (Tailwind + JS)
└── engine/                  Python coupon intelligence engine + CLI
```

## Validation performed

- PHP: every source file passes `php -l`; an in-process kernel harness verifies
  routing, middleware (auth/admin/CSRF/rate-limit), validation, JSON envelopes,
  and the JWT/CSRF/password primitives.
- Database: `001_init_schema.sql` + `001_seed.sql` execute cleanly on a real
  MySQL-compatible server (roles, permissions, plans, merchants, aliases,
  coupons, scores, AI providers all seed correctly).
- Frontend + API are served together by the dev router with correct MIME types,
  clean URLs, and SPA fallback.
- Python: all engine modules compile.

## Production notes

- Generate strong `JWT_SECRET` / `MEILI_MASTER_KEY` and set `APP_DEBUG=false`,
  `COOKIE_SECURE=true`, terminate TLS at a load balancer.
- Run php-fpm horizontally; run the Python engine as a separate scheduled
  deployment; use managed MySQL / Redis / Meilisearch.
- Configure Stripe/Razorpay price/plan IDs on each plan and point gateway
  webhooks at `/api/webhooks/{stripe|razorpay}`.
```
