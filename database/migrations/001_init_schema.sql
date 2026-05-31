-- =====================================================================
-- CouponFind — initial schema (MySQL 8, InnoDB, utf8mb4)
-- Designed for millions of coupons. PHP is source-of-truth writer for
-- SaaS data; Python engine writes coupon/merchant/validation/score data.
-- =====================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- RBAC: roles, permissions, role_permissions
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug          VARCHAR(50)  NOT NULL,
    name          VARCHAR(100) NOT NULL,
    description   VARCHAR(255) NULL,
    is_system     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug          VARCHAR(80)  NOT NULL,
    name          VARCHAR(120) NOT NULL,
    `group`       VARCHAR(60)  NOT NULL DEFAULT 'general',
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Users + auth artifacts
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid             CHAR(36)        NOT NULL,
    role_id          INT UNSIGNED    NOT NULL,
    name             VARCHAR(120)    NOT NULL,
    email            VARCHAR(190)    NOT NULL,
    email_verified_at TIMESTAMP      NULL,
    password_hash    VARCHAR(255)    NOT NULL,
    status           ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
    avatar_url       VARCHAR(255)    NULL,
    referral_code    VARCHAR(20)     NULL,
    referred_by      BIGINT UNSIGNED NULL,
    last_login_at    TIMESTAMP       NULL,
    last_login_ip    VARBINARY(16)   NULL,
    created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_uuid (uuid),
    UNIQUE KEY uq_users_referral (referral_code),
    KEY idx_users_role (role_id),
    KEY idx_users_status (status),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id),
    CONSTRAINT fk_users_referrer FOREIGN KEY (referred_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hashed refresh tokens (rotating). Access tokens are stateless JWT.
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NOT NULL,
    token_hash    CHAR(64)        NOT NULL,
    user_agent    VARCHAR(255)    NULL,
    ip            VARBINARY(16)   NULL,
    expires_at    TIMESTAMP       NOT NULL,
    revoked_at    TIMESTAMP       NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_refresh_hash (token_hash),
    KEY idx_refresh_user (user_id),
    CONSTRAINT fk_refresh_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NOT NULL,
    token_hash    CHAR(64)        NOT NULL,
    expires_at    TIMESTAMP       NOT NULL,
    used_at       TIMESTAMP       NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_preset_hash (token_hash),
    KEY idx_preset_user (user_id),
    CONSTRAINT fk_preset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Plans + subscriptions + billing
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS plans (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug               VARCHAR(50)  NOT NULL,
    name               VARCHAR(100) NOT NULL,
    description        VARCHAR(255) NULL,
    price_cents        INT UNSIGNED NOT NULL DEFAULT 0,
    currency           CHAR(3)      NOT NULL DEFAULT 'USD',
    `interval`         ENUM('day','month','year','lifetime') NOT NULL DEFAULT 'month',
    -- search quota: limit + window (day/month). NULL limit = unlimited.
    search_limit       INT          NULL,
    search_window      ENUM('day','month') NOT NULL DEFAULT 'day',
    is_active          TINYINT(1)   NOT NULL DEFAULT 1,
    is_public          TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order         INT          NOT NULL DEFAULT 0,
    stripe_price_id    VARCHAR(120) NULL,
    razorpay_plan_id   VARCHAR(120) NULL,
    features_json      JSON         NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_plans_slug (slug),
    KEY idx_plans_active (is_active, is_public)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriptions (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             BIGINT UNSIGNED NOT NULL,
    plan_id             INT UNSIGNED    NOT NULL,
    gateway             ENUM('stripe','razorpay','manual') NOT NULL DEFAULT 'manual',
    gateway_subscription_id VARCHAR(120) NULL,
    status              ENUM('active','trialing','past_due','canceled','expired','incomplete') NOT NULL DEFAULT 'active',
    -- Admin overrides: custom limit beats plan limit when set.
    override_search_limit  INT        NULL,
    override_search_window ENUM('day','month') NULL,
    is_lifetime         TINYINT(1)      NOT NULL DEFAULT 0,
    current_period_start TIMESTAMP      NULL,
    current_period_end   TIMESTAMP      NULL,
    cancel_at_period_end TINYINT(1)     NOT NULL DEFAULT 0,
    canceled_at         TIMESTAMP       NULL,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_subs_user (user_id),
    KEY idx_subs_plan (plan_id),
    KEY idx_subs_status (status),
    KEY idx_subs_gateway (gateway, gateway_subscription_id),
    CONSTRAINT fk_subs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_subs_plan FOREIGN KEY (plan_id) REFERENCES plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NULL,
    number          VARCHAR(40)     NOT NULL,
    gateway         ENUM('stripe','razorpay','manual') NOT NULL DEFAULT 'manual',
    gateway_invoice_id VARCHAR(120) NULL,
    amount_cents    INT UNSIGNED    NOT NULL DEFAULT 0,
    currency        CHAR(3)         NOT NULL DEFAULT 'USD',
    status          ENUM('draft','open','paid','void','uncollectible','refunded') NOT NULL DEFAULT 'open',
    hosted_url      VARCHAR(255)    NULL,
    pdf_url         VARCHAR(255)    NULL,
    issued_at       TIMESTAMP       NULL,
    paid_at         TIMESTAMP       NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoice_number (number),
    KEY idx_inv_user (user_id),
    KEY idx_inv_status (status),
    CONSTRAINT fk_inv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_inv_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    invoice_id      BIGINT UNSIGNED NULL,
    gateway         ENUM('stripe','razorpay') NOT NULL,
    gateway_payment_id VARCHAR(120) NOT NULL,
    amount_cents    INT UNSIGNED    NOT NULL,
    currency        CHAR(3)         NOT NULL DEFAULT 'USD',
    status          ENUM('pending','succeeded','failed','refunded','partially_refunded') NOT NULL DEFAULT 'pending',
    failure_reason  VARCHAR(255)    NULL,
    retry_count     INT UNSIGNED    NOT NULL DEFAULT 0,
    refunded_cents  INT UNSIGNED    NOT NULL DEFAULT 0,
    raw_payload     JSON            NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_gateway (gateway, gateway_payment_id),
    KEY idx_pay_user (user_id),
    KEY idx_pay_status (status),
    CONSTRAINT fk_pay_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_pay_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotency for gateway webhooks
CREATE TABLE IF NOT EXISTS webhook_events (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    gateway       ENUM('stripe','razorpay') NOT NULL,
    event_id      VARCHAR(120)    NOT NULL,
    type          VARCHAR(120)    NOT NULL,
    processed_at  TIMESTAMP       NULL,
    payload       JSON            NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_event (gateway, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Merchants + aliases + coupon sources
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS merchants (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug          VARCHAR(120)    NOT NULL,
    name          VARCHAR(150)    NOT NULL,
    domain        VARCHAR(190)    NULL,
    website_url   VARCHAR(255)    NULL,
    logo_url      VARCHAR(255)    NULL,
    category      VARCHAR(80)     NULL,
    country       CHAR(2)         NULL,
    description   TEXT            NULL,
    popularity    INT UNSIGNED    NOT NULL DEFAULT 0,
    is_active     TINYINT(1)      NOT NULL DEFAULT 1,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_merchants_slug (slug),
    KEY idx_merchants_domain (domain),
    KEY idx_merchants_category (category),
    KEY idx_merchants_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alias map powers merchant-intent detection & typo resolution ("niek" -> nike)
CREATE TABLE IF NOT EXISTS merchant_aliases (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id   BIGINT UNSIGNED NOT NULL,
    alias         VARCHAR(150)    NOT NULL,
    normalized    VARCHAR(150)    NOT NULL,
    weight        SMALLINT        NOT NULL DEFAULT 100,
    PRIMARY KEY (id),
    UNIQUE KEY uq_alias_norm (merchant_id, normalized),
    KEY idx_alias_norm (normalized),
    CONSTRAINT fk_alias_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupon_sources (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id     BIGINT UNSIGNED NULL,
    type            ENUM('offer_page','promo_page','rss','sitemap','newsletter','user_submission') NOT NULL,
    url             VARCHAR(500)    NOT NULL,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    crawl_frequency_minutes INT UNSIGNED NOT NULL DEFAULT 180,
    last_crawled_at TIMESTAMP       NULL,
    last_status     VARCHAR(60)     NULL,
    last_error      VARCHAR(255)    NULL,
    success_count   INT UNSIGNED    NOT NULL DEFAULT 0,
    failure_count   INT UNSIGNED    NOT NULL DEFAULT 0,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_source_url (url(255)),
    KEY idx_source_merchant (merchant_id),
    KEY idx_source_active (is_active, type),
    CONSTRAINT fk_source_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Coupons + scores + validation (written by Python engine)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS coupons (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id     BIGINT UNSIGNED NOT NULL,
    source_id       BIGINT UNSIGNED NULL,
    -- content_hash = sha256 of (merchant + code + type + value + title) for dedupe
    content_hash    CHAR(64)        NOT NULL,
    title           VARCHAR(255)    NOT NULL,
    description     TEXT            NULL,
    code            VARCHAR(80)     NULL,
    type            ENUM('code','deal','free_shipping','bogo','cashback') NOT NULL DEFAULT 'code',
    discount_type   ENUM('percent','amount','free_shipping','other') NOT NULL DEFAULT 'other',
    discount_value  DECIMAL(10,2)   NULL,
    currency        CHAR(3)         NULL,
    landing_url     VARCHAR(500)    NULL,
    terms           VARCHAR(500)    NULL,
    status          ENUM('active','expired','unverified','rejected','draft') NOT NULL DEFAULT 'unverified',
    is_featured     TINYINT(1)      NOT NULL DEFAULT 0,
    starts_at       TIMESTAMP       NULL,
    valid_until     TIMESTAMP       NULL,
    last_seen_at    TIMESTAMP       NULL,
    success_count   INT UNSIGNED    NOT NULL DEFAULT 0,
    fail_count      INT UNSIGNED    NOT NULL DEFAULT 0,
    times_used      INT UNSIGNED    NOT NULL DEFAULT 0,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_coupon_hash (content_hash),
    KEY idx_coupon_merchant (merchant_id),
    KEY idx_coupon_status (status),
    KEY idx_coupon_validuntil (valid_until),
    KEY idx_coupon_featured (is_featured),
    KEY idx_coupon_merchant_status (merchant_id, status, valid_until),
    CONSTRAINT fk_coupon_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE,
    CONSTRAINT fk_coupon_source FOREIGN KEY (source_id) REFERENCES coupon_sources(id) ON DELETE SET NULL,
    FULLTEXT KEY ft_coupon_text (title, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupon_scores (
    coupon_id       BIGINT UNSIGNED NOT NULL,
    -- 0..1 composite score and components used by the ranking engine
    score           DECIMAL(6,5)    NOT NULL DEFAULT 0,
    freshness       DECIMAL(6,5)    NOT NULL DEFAULT 0,
    reliability     DECIMAL(6,5)    NOT NULL DEFAULT 0,
    popularity      DECIMAL(6,5)    NOT NULL DEFAULT 0,
    value_score     DECIMAL(6,5)    NOT NULL DEFAULT 0,
    computed_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (coupon_id),
    KEY idx_score_value (score),
    CONSTRAINT fk_score_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupon_validations (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    coupon_id       BIGINT UNSIGNED NOT NULL,
    method          ENUM('heuristic','http_check','ai','manual') NOT NULL DEFAULT 'heuristic',
    result          ENUM('valid','invalid','expired','unknown') NOT NULL DEFAULT 'unknown',
    confidence      DECIMAL(5,4)    NOT NULL DEFAULT 0,
    detail          VARCHAR(255)    NULL,
    checked_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_val_coupon (coupon_id),
    KEY idx_val_result (result),
    CONSTRAINT fk_val_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Raw discoveries before structuring/import (engine staging area)
CREATE TABLE IF NOT EXISTS raw_discoveries (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_id     BIGINT UNSIGNED NULL,
    url           VARCHAR(500)    NOT NULL,
    raw_hash      CHAR(64)        NOT NULL,
    raw_text      MEDIUMTEXT      NULL,
    status        ENUM('pending','structured','discarded') NOT NULL DEFAULT 'pending',
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_raw_hash (raw_hash),
    KEY idx_raw_status (status),
    CONSTRAINT fk_raw_source FOREIGN KEY (source_id) REFERENCES coupon_sources(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- User engagement: saved coupons, watchlists, deal alerts, notifications
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS saved_coupons (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    coupon_id   BIGINT UNSIGNED NOT NULL,
    note        VARCHAR(255)    NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_saved (user_id, coupon_id),
    CONSTRAINT fk_saved_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_saved_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS watchlists (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    merchant_id BIGINT UNSIGNED NULL,
    keyword     VARCHAR(150)    NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_watch_user (user_id),
    CONSTRAINT fk_watch_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_watch_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deal_alerts (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NOT NULL,
    merchant_id   BIGINT UNSIGNED NULL,
    keyword       VARCHAR(150)    NULL,
    min_discount  DECIMAL(10,2)   NULL,
    channel       ENUM('email','in_app') NOT NULL DEFAULT 'in_app',
    is_active     TINYINT(1)      NOT NULL DEFAULT 1,
    last_triggered_at TIMESTAMP   NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_alert_user (user_id),
    CONSTRAINT fk_alert_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_alert_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    type        VARCHAR(60)     NOT NULL DEFAULT 'system',
    title       VARCHAR(190)    NOT NULL,
    body        VARCHAR(500)    NULL,
    data_json   JSON            NULL,
    read_at     TIMESTAMP       NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notif_user (user_id, read_at),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Search logs / analytics / usage metering
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS search_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NULL,
    query_raw       VARCHAR(255)    NOT NULL,
    query_normalized VARCHAR(255)   NULL,
    detected_merchant_id BIGINT UNSIGNED NULL,
    intent_json     JSON            NULL,
    result_count    INT UNSIGNED    NOT NULL DEFAULT 0,
    took_ms         INT UNSIGNED    NOT NULL DEFAULT 0,
    cache_hit       TINYINT(1)      NOT NULL DEFAULT 0,
    ip              VARBINARY(16)   NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_search_user (user_id),
    KEY idx_search_created (created_at),
    KEY idx_search_merchant (detected_merchant_id),
    CONSTRAINT fk_search_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user usage counters per window (fast quota enforcement; Redis is primary, this is durable)
CREATE TABLE IF NOT EXISTS usage_counters (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    metric      VARCHAR(40)     NOT NULL DEFAULT 'search',
    window_key  VARCHAR(20)     NOT NULL,   -- e.g. 2026-05-31 (day) or 2026-05 (month)
    count       INT UNSIGNED    NOT NULL DEFAULT 0,
    updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usage (user_id, metric, window_key),
    CONSTRAINT fk_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Platform: settings, feature flags, audit logs, api logs, AI providers
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    `key`       VARCHAR(100)    NOT NULL,
    value       TEXT            NULL,
    type        ENUM('string','int','bool','json') NOT NULL DEFAULT 'string',
    updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feature_flags (
    `key`       VARCHAR(80)     NOT NULL,
    name        VARCHAR(120)    NOT NULL,
    description VARCHAR(255)    NULL,
    is_enabled  TINYINT(1)      NOT NULL DEFAULT 0,
    rollout_pct TINYINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_providers (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug          ENUM('groq','gemini','openai') NOT NULL,
    name          VARCHAR(60)  NOT NULL,
    is_enabled    TINYINT(1)   NOT NULL DEFAULT 1,
    priority      INT          NOT NULL DEFAULT 0,
    model         VARCHAR(120) NULL,
    last_ok_at    TIMESTAMP    NULL,
    last_error    VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ai_slug (slug),
    KEY idx_ai_priority (is_enabled, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id    BIGINT UNSIGNED NULL,
    action      VARCHAR(120)    NOT NULL,
    entity_type VARCHAR(80)     NULL,
    entity_id   VARCHAR(80)     NULL,
    meta_json   JSON            NULL,
    ip          VARBINARY(16)   NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_actor (actor_id),
    KEY idx_audit_action (action),
    KEY idx_audit_created (created_at),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_logs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NULL,
    method      VARCHAR(10)     NOT NULL,
    path        VARCHAR(255)    NOT NULL,
    status_code SMALLINT UNSIGNED NOT NULL,
    took_ms     INT UNSIGNED    NOT NULL DEFAULT 0,
    ip          VARBINARY(16)   NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_apilog_path (path),
    KEY idx_apilog_created (created_at),
    CONSTRAINT fk_apilog_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Background jobs for the Python engine (durable queue mirrored in Redis)
CREATE TABLE IF NOT EXISTS engine_jobs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type        VARCHAR(60)     NOT NULL,   -- discover|crawl|validate|score|sync|import
    payload     JSON            NULL,
    status      ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
    attempts    INT UNSIGNED    NOT NULL DEFAULT 0,
    error       VARCHAR(255)    NULL,
    scheduled_at TIMESTAMP      NULL,
    started_at  TIMESTAMP       NULL,
    finished_at TIMESTAMP       NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_job_status (status, type),
    KEY idx_job_sched (scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
