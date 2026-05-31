-- =====================================================================
-- CouponFind — seed data
-- Roles, permissions, plans, AI providers, settings, feature flags,
-- a super-admin + demo user, demo merchants/aliases/sources/coupons.
--
-- Seeded credentials (CHANGE IN PRODUCTION):
--   super-admin  : admin@couponfind.local / Admin@12345
--   demo user    : user@couponfind.local  / User@12345
-- =====================================================================
SET NAMES utf8mb4;

-- ---- Roles ----
INSERT INTO roles (id, slug, name, description, is_system) VALUES
    (1, 'super_admin', 'Super Admin', 'Full platform control', 1),
    (2, 'admin',       'Admin',       'Operational admin', 1),
    (3, 'user',        'User',        'Standard end user', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ---- Permissions ----
INSERT INTO permissions (slug, name, `group`) VALUES
    ('users.manage',         'Manage users',          'users'),
    ('plans.manage',         'Manage plans',          'billing'),
    ('subscriptions.manage', 'Manage subscriptions',  'billing'),
    ('revenue.view',         'View revenue',          'analytics'),
    ('coupons.manage',       'Manage coupons',        'catalog'),
    ('merchants.manage',     'Manage merchants',      'catalog'),
    ('sources.manage',       'Manage coupon sources', 'catalog'),
    ('search.analytics',     'View search analytics', 'analytics'),
    ('ai.manage',            'Manage AI providers',   'system'),
    ('crawler.control',      'Control crawler',       'system'),
    ('validation.control',   'Control validation',    'system'),
    ('indexer.control',      'Control indexer',       'system'),
    ('flags.manage',         'Manage feature flags',  'system'),
    ('logs.view',            'View logs',             'system'),
    ('settings.manage',      'Manage settings',       'system')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- super_admin gets everything
INSERT INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions
ON DUPLICATE KEY UPDATE role_id = role_id;

-- admin gets operational subset
INSERT INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions
WHERE slug IN ('users.manage','coupons.manage','merchants.manage','sources.manage',
               'search.analytics','crawler.control','validation.control','indexer.control','logs.view')
ON DUPLICATE KEY UPDATE role_id = role_id;

-- ---- Plans (per product spec) ----
INSERT INTO plans (slug, name, description, price_cents, currency, `interval`, search_limit, search_window, is_active, is_public, sort_order, features_json) VALUES
    ('free',         'Free',        '10 searches per day',          0,    'USD', 'month', 10,  'day',   1, 1, 1, JSON_ARRAY('AI search','Basic results','Save coupons')),
    ('starter',      'Starter',     '100 searches per month',       500,  'USD', 'month', 100, 'month', 1, 1, 2, JSON_ARRAY('Everything in Free','Watchlists','Deal alerts')),
    ('pro',          'Pro',         '200 searches per month',       1000, 'USD', 'month', 200, 'month', 1, 1, 3, JSON_ARRAY('Everything in Starter','Priority ranking','Email alerts')),
    ('yearly_pro',   'Yearly Pro',  '100 searches per day',         4900, 'USD', 'year',  100, 'day',   1, 1, 4, JSON_ARRAY('Best for power users','100/day','Priority support')),
    ('yearly_elite', 'Yearly Elite','200 searches per day',         9900, 'USD', 'year',  200, 'day',   1, 1, 5, JSON_ARRAY('Maximum quota','200/day','Dedicated support'))
ON DUPLICATE KEY UPDATE name = VALUES(name), price_cents = VALUES(price_cents), search_limit = VALUES(search_limit), search_window = VALUES(search_window);

-- ---- AI providers (fallback chain: groq -> gemini -> openai) ----
INSERT INTO ai_providers (slug, name, is_enabled, priority, model) VALUES
    ('groq',   'Groq',   1, 1, 'llama-3.3-70b-versatile'),
    ('gemini', 'Gemini', 1, 2, 'gemini-1.5-flash'),
    ('openai', 'OpenAI', 1, 3, 'gpt-4o-mini')
ON DUPLICATE KEY UPDATE priority = VALUES(priority), model = VALUES(model);

-- ---- Feature flags ----
INSERT INTO feature_flags (`key`, name, description, is_enabled, rollout_pct) VALUES
    ('ai_query_rewrite', 'AI query rewrite',    'Use AI to rewrite hard queries', 1, 100),
    ('referrals',        'Referral program',    'Enable referral center',         1, 100),
    ('email_alerts',     'Email deal alerts',   'Send deal alerts via email',     0, 0)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ---- Settings ----
INSERT INTO settings (`key`, value, type) VALUES
    ('site.name',            'CouponFind', 'string'),
    ('search.cache_ttl',     '60',         'int'),
    ('search.max_results',   '40',         'int'),
    ('signup.default_plan',  'free',       'string')
ON DUPLICATE KEY UPDATE value = VALUES(value);

-- ---- Users (super admin + demo) ----
INSERT INTO users (uuid, role_id, name, email, email_verified_at, password_hash, status, referral_code) VALUES
    (UUID(), 1, 'Super Admin', 'admin@couponfind.local', NOW(),
     '$2y$12$lU2LkQYRGsMNqw5tNsqIee/XnhNn54goF/phIVdP2E5Ktq4u4xj9S', 'active', 'ADMIN1'),
    (UUID(), 3, 'Demo User', 'user@couponfind.local', NOW(),
     '$2y$12$KhM7bvSO850jYLm9QME2NOU3os/FWJETLDzaBAxvs6npgS1yEcWCy', 'active', 'DEMO01')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- demo user on free plan
INSERT INTO subscriptions (user_id, plan_id, gateway, status, current_period_start, current_period_end)
SELECT u.id, p.id, 'manual', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH)
FROM users u, plans p
WHERE u.email = 'user@couponfind.local' AND p.slug = 'free'
LIMIT 1;

-- ---- Demo merchants ----
INSERT INTO merchants (slug, name, domain, website_url, category, country, popularity, is_active) VALUES
    ('amazon',    'Amazon',    'amazon.com',    'https://www.amazon.com',    'marketplace', 'US', 1000, 1),
    ('nike',      'Nike',      'nike.com',      'https://www.nike.com',      'fashion',     'US', 800,  1),
    ('hostinger', 'Hostinger', 'hostinger.com', 'https://www.hostinger.com', 'hosting',     'US', 700,  1),
    ('nordvpn',   'NordVPN',   'nordvpn.com',   'https://www.nordvpn.com',   'vpn',         'US', 650,  1),
    ('adidas',    'Adidas',    'adidas.com',    'https://www.adidas.com',    'fashion',     'US', 600,  1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ---- Merchant aliases (power typo/intent detection) ----
INSERT INTO merchant_aliases (merchant_id, alias, normalized, weight)
SELECT m.id, a.alias, a.normalized, a.weight FROM merchants m
JOIN (
    SELECT 'amazon' s, 'amazon'  alias, 'amazon'  normalized, 100 weight UNION ALL
    SELECT 'amazon', 'amzn', 'amzn', 90 UNION ALL
    SELECT 'amazon', 'amazn', 'amazn', 70 UNION ALL
    SELECT 'nike', 'nike', 'nike', 100 UNION ALL
    SELECT 'nike', 'niek', 'niek', 70 UNION ALL
    SELECT 'nike', 'nikee', 'nikee', 60 UNION ALL
    SELECT 'hostinger', 'hostinger', 'hostinger', 100 UNION ALL
    SELECT 'hostinger', 'hostingr', 'hostingr', 70 UNION ALL
    SELECT 'nordvpn', 'nordvpn', 'nordvpn', 100 UNION ALL
    SELECT 'nordvpn', 'nord vpn', 'nord vpn', 90 UNION ALL
    SELECT 'nordvpn', 'vpn', 'vpn', 40 UNION ALL
    SELECT 'adidas', 'adidas', 'adidas', 100 UNION ALL
    SELECT 'adidas', 'addidas', 'addidas', 70
) a ON a.s = m.slug
ON DUPLICATE KEY UPDATE weight = VALUES(weight);

-- ---- Demo coupon sources ----
INSERT INTO coupon_sources (merchant_id, type, url, is_active, crawl_frequency_minutes)
SELECT m.id, 'offer_page', CONCAT(m.website_url, '/deals'), 1, 180 FROM merchants m
ON DUPLICATE KEY UPDATE is_active = VALUES(is_active);

-- ---- Demo coupons (so search works out of the box before the engine runs) ----
INSERT INTO coupons (merchant_id, content_hash, title, description, code, type, discount_type, discount_value, currency, landing_url, status, is_featured, valid_until, last_seen_at, success_count, fail_count, times_used)
SELECT m.id, SHA2(CONCAT(m.slug, c.code, c.title), 256), c.title, c.descr, c.code, c.ctype, c.dtype, c.dval, 'USD', m.website_url, 'active', c.feat, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW(), c.succ, c.fail, c.used
FROM merchants m
JOIN (
    SELECT 'amazon' s, 'Amazon 20% off electronics' title, 'Save 20% on select electronics today' descr, 'AMZ20' code, 'code' ctype, 'percent' dtype, 20.00 dval, 1 feat, 320 succ, 12 fail, 540 used UNION ALL
    SELECT 'amazon', 'Free shipping on orders $25+', 'Free shipping with no code needed', 'FREESHIP' , 'free_shipping', 'free_shipping', NULL, 0, 210, 8, 410 UNION ALL
    SELECT 'nike', 'Nike 25% off sitewide', 'Limited time 25% off everything', 'NIKE25', 'code', 'percent', 25.00, 1, 180, 9, 260 UNION ALL
    SELECT 'nike', 'Extra 15% off sale items', 'Stack an extra 15% on sale', 'EXTRA15', 'code', 'percent', 15.00, 0, 95, 4, 120 UNION ALL
    SELECT 'hostinger', 'Hostinger 75% off hosting', 'Up to 75% off web hosting plans', 'HOST75', 'code', 'percent', 75.00, 1, 410, 6, 720 UNION ALL
    SELECT 'nordvpn', 'NordVPN 68% off 2-year plan', 'Best VPN deal: 68% off + 3 months free', 'NORD68', 'code', 'percent', 68.00, 1, 290, 5, 500 UNION ALL
    SELECT 'adidas', 'Adidas 30% off running shoes', '30% off select running shoes', 'RUN30', 'code', 'percent', 30.00, 0, 130, 7, 150
) c ON c.s = m.slug
ON DUPLICATE KEY UPDATE last_seen_at = NOW();

-- ---- Coupon scores for the demo coupons (so ranking has data) ----
INSERT INTO coupon_scores (coupon_id, score, freshness, reliability, popularity, value_score)
SELECT c.id,
       LEAST(0.99, 0.4 + (c.success_count / NULLIF(c.success_count + c.fail_count,0)) * 0.4 + (c.is_featured * 0.1)),
       0.9,
       (c.success_count / NULLIF(c.success_count + c.fail_count,0)),
       LEAST(0.99, c.times_used / 1000),
       LEAST(0.99, COALESCE(c.discount_value,0) / 100)
FROM coupons c
ON DUPLICATE KEY UPDATE score = VALUES(score), computed_at = NOW();

-- ---- Mark coupons validated ----
INSERT INTO coupon_validations (coupon_id, method, result, confidence, detail)
SELECT id, 'heuristic', 'valid', 0.9, 'Seed data' FROM coupons;
