<?php

declare(strict_types=1);

namespace CouponFind\Controllers;

use CouponFind\Core\Database;
use CouponFind\Core\RedisClient;
use CouponFind\Core\Request;
use CouponFind\Core\Response;
use CouponFind\Repositories\CouponRepository;
use CouponFind\Repositories\MerchantRepository;
use CouponFind\Repositories\PlanRepository;
use CouponFind\Repositories\SearchLogRepository;
use CouponFind\Repositories\SubscriptionRepository;
use CouponFind\Repositories\UserRepository;
use CouponFind\Services\Meilisearch;
use CouponFind\Support\Audit;
use CouponFind\Support\HttpException;
use CouponFind\Support\Validator;

/**
 * Super Admin "mission control". Every mutating action is audit-logged.
 * Heavy engine operations (crawl / validate / reindex) are dispatched as
 * durable engine_jobs that the Python engine picks up.
 */
final class AdminController
{
    private Database $db;
    private UserRepository $users;
    private PlanRepository $plans;
    private MerchantRepository $merchants;
    private CouponRepository $coupons;
    private SubscriptionRepository $subs;
    private SearchLogRepository $searchLogs;

    public function __construct()
    {
        $this->db = Database::instance();
        $this->users = new UserRepository();
        $this->plans = new PlanRepository();
        $this->merchants = new MerchantRepository();
        $this->coupons = new CouponRepository();
        $this->subs = new SubscriptionRepository();
        $this->searchLogs = new SearchLogRepository();
    }

    // ---- Dashboard ----
    public function dashboard(Request $request): Response
    {
        return Response::ok([
            'users_total'        => (int) $this->db->scalar('SELECT COUNT(*) FROM users'),
            'users_active_24h'   => (int) $this->db->scalar('SELECT COUNT(*) FROM users WHERE last_login_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)'),
            'subscriptions'      => $this->subs->countActive(),
            'mrr'                => round($this->subs->mrrCents() / 100, 2),
            'coupons_total'      => $this->coupons->totalCount(),
            'coupons_active'     => $this->coupons->activeCount(),
            'merchants'          => $this->merchants->count(),
            'searches_total'     => $this->searchLogs->totalCount(),
            'searches_24h'       => $this->searchLogs->countSince(date('Y-m-d H:i:s', strtotime('-1 day'))),
            'avg_latency_ms'     => round($this->searchLogs->avgLatencyMs(7), 1),
            'search_volume'      => $this->searchLogs->dailyVolume(14),
            'top_queries'        => $this->searchLogs->topQueries(10, 30),
            'revenue_30d'        => round(((int) $this->db->scalar("SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE status='succeeded' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")) / 100, 2),
        ]);
    }

    // ---- Users ----
    public function users(Request $request): Response
    {
        $page = max(1, (int) $request->query('page', 1));
        $per = min(100, max(5, (int) $request->query('per_page', 25)));
        return Response::ok($this->users->paginate($page, $per, $request->query('search')));
    }

    public function setUserStatus(Request $request, array $params): Response
    {
        $data = Validator::make($request->all(), ['status' => 'required|in:active,suspended,pending']);
        $this->users->setStatus((int) $params['id'], $data['status']);
        Audit::log((int) $request->userId(), 'admin.user.status', 'user', $params['id'], ['status' => $data['status']], $request->ip());
        return Response::ok(null, 'User updated');
    }

    public function setUserRole(Request $request, array $params): Response
    {
        $data = Validator::make($request->all(), ['role_id' => 'required|int']);
        $this->users->setRole((int) $params['id'], (int) $data['role_id']);
        Audit::log((int) $request->userId(), 'admin.user.role', 'user', $params['id'], ['role_id' => $data['role_id']], $request->ip());
        return Response::ok(null, 'Role updated');
    }

    // ---- Plans CRUD ----
    public function plans(Request $request): Response
    {
        return Response::ok(['plans' => $this->plans->all()]);
    }

    public function createPlan(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'slug'        => 'required|string|max:50',
            'name'        => 'required|string|max:100',
            'price_cents' => 'required|int',
            'interval'    => 'required|in:day,month,year,lifetime',
        ]);
        $id = $this->plans->create($request->all());
        Audit::log((int) $request->userId(), 'admin.plan.create', 'plan', (string) $id, $data, $request->ip());
        return Response::created(['id' => $id], 'Plan created');
    }

    public function updatePlan(Request $request, array $params): Response
    {
        $this->plans->update((int) $params['id'], $request->all());
        Audit::log((int) $request->userId(), 'admin.plan.update', 'plan', $params['id'], [], $request->ip());
        return Response::ok(null, 'Plan updated');
    }

    public function deletePlan(Request $request, array $params): Response
    {
        $this->plans->delete((int) $params['id']);
        Audit::log((int) $request->userId(), 'admin.plan.delete', 'plan', $params['id'], [], $request->ip());
        return Response::ok(null, 'Plan deleted');
    }

    // ---- Subscriptions: assign custom / lifetime / override ----
    public function subscriptions(Request $request): Response
    {
        $rows = $this->db->all(
            "SELECT s.*, u.email, u.name, p.name AS plan_name FROM subscriptions s
             JOIN users u ON u.id = s.user_id JOIN plans p ON p.id = s.plan_id
             ORDER BY s.id DESC LIMIT 200"
        );
        return Response::ok(['subscriptions' => $rows]);
    }

    public function assignSubscription(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'user_id' => 'required|int',
            'plan_id' => 'required|int',
        ]);
        $plan = $this->plans->find((int) $data['plan_id']);
        if ($plan === null) {
            throw HttpException::notFound('Plan not found');
        }
        $lifetime = filter_var($request->input('lifetime', false), FILTER_VALIDATE_BOOLEAN);
        $id = $this->subs->create([
            'user_id'                => (int) $data['user_id'],
            'plan_id'                => (int) $data['plan_id'],
            'gateway'                => 'manual',
            'status'                 => 'active',
            'is_lifetime'            => $lifetime ? 1 : 0,
            'current_period_end'     => $lifetime ? null : date('Y-m-d H:i:s', strtotime('+1 year')),
            'override_search_limit'  => $request->input('override_search_limit'),
            'override_search_window' => $request->input('override_search_window'),
        ]);
        Audit::log((int) $request->userId(), 'admin.subscription.assign', 'subscription', (string) $id, $request->all(), $request->ip());
        return Response::created(['id' => $id], 'Subscription assigned');
    }

    public function overrideSubscription(Request $request, array $params): Response
    {
        $limit = $request->input('override_search_limit');
        $window = $request->input('override_search_window');
        $lifetime = filter_var($request->input('lifetime', false), FILTER_VALIDATE_BOOLEAN);
        $this->subs->setOverride((int) $params['id'], $limit !== null ? (int) $limit : null, $window, $lifetime);
        Audit::log((int) $request->userId(), 'admin.subscription.override', 'subscription', $params['id'], $request->all(), $request->ip());
        return Response::ok(null, 'Override applied');
    }

    // ---- Merchants CRUD ----
    public function merchants(Request $request): Response
    {
        return Response::ok(['merchants' => $this->merchants->all(false)]);
    }

    public function createMerchant(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'slug' => 'required|string|max:120',
            'name' => 'required|string|max:150',
        ]);
        $id = $this->merchants->create($request->all());
        Audit::log((int) $request->userId(), 'admin.merchant.create', 'merchant', (string) $id, $data, $request->ip());
        return Response::created(['id' => $id], 'Merchant created');
    }

    public function updateMerchant(Request $request, array $params): Response
    {
        $this->merchants->update((int) $params['id'], $request->all());
        Audit::log((int) $request->userId(), 'admin.merchant.update', 'merchant', $params['id'], [], $request->ip());
        return Response::ok(null, 'Merchant updated');
    }

    public function deleteMerchant(Request $request, array $params): Response
    {
        $this->merchants->delete((int) $params['id']);
        Audit::log((int) $request->userId(), 'admin.merchant.delete', 'merchant', $params['id'], [], $request->ip());
        return Response::ok(null, 'Merchant deleted');
    }

    // ---- Coupons management ----
    public function coupons(Request $request): Response
    {
        $page = max(1, (int) $request->query('page', 1));
        $per = min(100, max(5, (int) $request->query('per_page', 25)));
        return Response::ok($this->coupons->paginate($page, $per, $request->query('status'), $request->query('search')));
    }

    public function setCouponStatus(Request $request, array $params): Response
    {
        $data = Validator::make($request->all(), ['status' => 'required|in:active,expired,unverified,rejected,draft']);
        $this->coupons->setStatus((int) $params['id'], $data['status']);
        Audit::log((int) $request->userId(), 'admin.coupon.status', 'coupon', $params['id'], $data, $request->ip());
        return Response::ok(null, 'Coupon updated');
    }

    public function expireCoupon(Request $request, array $params): Response
    {
        $this->coupons->expire((int) $params['id']);
        (new Meilisearch())->deleteDocument((int) $params['id']);
        Audit::log((int) $request->userId(), 'admin.coupon.expire', 'coupon', $params['id'], [], $request->ip());
        return Response::ok(null, 'Coupon expired');
    }

    // ---- Coupon sources ----
    public function sources(Request $request): Response
    {
        $rows = $this->db->all(
            'SELECT cs.*, m.name AS merchant_name FROM coupon_sources cs
             LEFT JOIN merchants m ON m.id = cs.merchant_id ORDER BY cs.id DESC LIMIT 200'
        );
        return Response::ok(['sources' => $rows]);
    }

    public function createSource(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'type' => 'required|in:offer_page,promo_page,rss,sitemap,newsletter,user_submission',
            'url'  => 'required|url',
        ]);
        $id = $this->db->insert(
            'INSERT INTO coupon_sources (merchant_id, type, url, is_active, crawl_frequency_minutes) VALUES (?,?,?,?,?)',
            [$request->input('merchant_id') ? (int) $request->input('merchant_id') : null, $data['type'], $data['url'], 1, (int) $request->input('crawl_frequency_minutes', 180)]
        );
        Audit::log((int) $request->userId(), 'admin.source.create', 'coupon_source', (string) $id, $data, $request->ip());
        return Response::created(['id' => $id], 'Source added');
    }

    public function deleteSource(Request $request, array $params): Response
    {
        $this->db->execute('DELETE FROM coupon_sources WHERE id = ?', [(int) $params['id']]);
        Audit::log((int) $request->userId(), 'admin.source.delete', 'coupon_source', $params['id'], [], $request->ip());
        return Response::ok(null, 'Source removed');
    }

    // ---- Analytics ----
    public function searchAnalytics(Request $request): Response
    {
        return Response::ok([
            'daily_volume' => $this->searchLogs->dailyVolume(30),
            'top_queries'  => $this->searchLogs->topQueries(20, 30),
            'avg_latency'  => round($this->searchLogs->avgLatencyMs(30), 1),
            'zero_result'  => (int) $this->db->scalar('SELECT COUNT(*) FROM search_logs WHERE result_count = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'),
        ]);
    }

    public function revenueAnalytics(Request $request): Response
    {
        return Response::ok([
            'mrr'         => round($this->subs->mrrCents() / 100, 2),
            'by_day'      => $this->db->all("SELECT DATE(created_at) AS day, COALESCE(SUM(amount_cents),0)/100 AS revenue FROM payments WHERE status='succeeded' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY day ASC"),
            'by_plan'     => $this->db->all("SELECT p.name, COUNT(*) AS subscribers FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.status IN ('active','trialing') GROUP BY p.id ORDER BY subscribers DESC"),
            'failed_30d'  => (int) $this->db->scalar("SELECT COUNT(*) FROM payments WHERE status='failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"),
        ]);
    }

    // ---- AI control center ----
    public function aiProviders(Request $request): Response
    {
        return Response::ok(['providers' => $this->db->all('SELECT * FROM ai_providers ORDER BY priority ASC')]);
    }

    public function updateAiProvider(Request $request, array $params): Response
    {
        $enabled = filter_var($request->input('is_enabled', true), FILTER_VALIDATE_BOOLEAN);
        $this->db->execute(
            'UPDATE ai_providers SET is_enabled = ?, priority = COALESCE(?, priority), model = COALESCE(?, model) WHERE id = ?',
            [(int) $enabled, $request->input('priority'), $request->input('model'), (int) $params['id']]
        );
        Audit::log((int) $request->userId(), 'admin.ai.update', 'ai_provider', $params['id'], $request->all(), $request->ip());
        return Response::ok(null, 'AI provider updated');
    }

    // ---- Engine control (crawler / validation / indexer) ----
    public function dispatchJob(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'type' => 'required|in:discover,crawl,validate,score,sync,import',
        ]);
        $payload = $request->input('payload', []);
        $id = $this->db->insert(
            'INSERT INTO engine_jobs (type, payload, status, scheduled_at) VALUES (?,?,?,NOW())',
            [$data['type'], is_array($payload) ? json_encode($payload) : null, 'queued']
        );
        // Mirror onto the Redis queue for the worker to pick up promptly.
        RedisClient::instance()->rpush('engine:jobs', json_encode(['id' => $id, 'type' => $data['type'], 'payload' => $payload]));
        Audit::log((int) $request->userId(), 'admin.engine.dispatch', 'engine_job', (string) $id, $data, $request->ip());
        return Response::created(['id' => $id], ucfirst($data['type']) . ' job queued');
    }

    public function jobs(Request $request): Response
    {
        return Response::ok(['jobs' => $this->db->all('SELECT * FROM engine_jobs ORDER BY id DESC LIMIT 100')]);
    }

    public function reindex(Request $request): Response
    {
        $meili = new Meilisearch();
        $meili->ensureIndex();
        $id = $this->db->insert('INSERT INTO engine_jobs (type, status, scheduled_at) VALUES ("sync","queued",NOW())', []);
        RedisClient::instance()->rpush('engine:jobs', json_encode(['id' => $id, 'type' => 'sync']));
        Audit::log((int) $request->userId(), 'admin.indexer.reindex', 'index', null, [], $request->ip());
        return Response::ok(['job_id' => $id], 'Reindex queued');
    }

    // ---- Feature flags ----
    public function flags(Request $request): Response
    {
        return Response::ok(['flags' => $this->db->all('SELECT * FROM feature_flags ORDER BY `key` ASC')]);
    }

    public function updateFlag(Request $request, array $params): Response
    {
        $enabled = filter_var($request->input('is_enabled', false), FILTER_VALIDATE_BOOLEAN);
        $this->db->execute(
            'UPDATE feature_flags SET is_enabled = ?, rollout_pct = COALESCE(?, rollout_pct) WHERE `key` = ?',
            [(int) $enabled, $request->input('rollout_pct'), $params['key']]
        );
        Audit::log((int) $request->userId(), 'admin.flag.update', 'feature_flag', $params['key'], $request->all(), $request->ip());
        return Response::ok(null, 'Flag updated');
    }

    // ---- Settings ----
    public function settings(Request $request): Response
    {
        return Response::ok(['settings' => $this->db->all('SELECT * FROM settings ORDER BY `key` ASC')]);
    }

    public function updateSetting(Request $request, array $params): Response
    {
        $value = (string) $request->input('value', '');
        $this->db->execute(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$params['key'], $value]
        );
        Audit::log((int) $request->userId(), 'admin.setting.update', 'setting', $params['key'], ['value' => $value], $request->ip());
        return Response::ok(null, 'Setting saved');
    }

    // ---- Logs / audit ----
    public function auditLogs(Request $request): Response
    {
        $rows = $this->db->all(
            'SELECT a.id, a.action, a.entity_type, a.entity_id, a.meta_json, a.created_at,
                    u.name AS actor_name, u.email AS actor_email
             FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id
             ORDER BY a.id DESC LIMIT 200'
        );
        return Response::ok(['logs' => $rows]);
    }

    public function apiLogs(Request $request): Response
    {
        $rows = $this->db->all(
            'SELECT method, path, status_code, took_ms, created_at FROM api_logs ORDER BY id DESC LIMIT 200'
        );
        return Response::ok(['logs' => $rows]);
    }

    // ---- System health ----
    public function health(Request $request): Response
    {
        $redis = RedisClient::instance();
        $meili = new Meilisearch();
        return Response::ok([
            'database'    => Database::instance()->healthy(),
            'redis'       => $redis->isAvailable(),
            'meilisearch' => $meili->isHealthy(),
            'meili_stats' => $meili->stats(),
            'queued_jobs' => (int) $this->db->scalar("SELECT COUNT(*) FROM engine_jobs WHERE status = 'queued'"),
            'failed_jobs' => (int) $this->db->scalar("SELECT COUNT(*) FROM engine_jobs WHERE status = 'failed'"),
            'php_version' => PHP_VERSION,
            'time'        => gmdate('c'),
        ]);
    }
}
