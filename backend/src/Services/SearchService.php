<?php

declare(strict_types=1);

namespace CouponFind\Services;

use CouponFind\Core\Env;
use CouponFind\Core\RedisClient;
use CouponFind\Repositories\CouponRepository;
use CouponFind\Repositories\MerchantRepository;
use CouponFind\Repositories\SearchLogRepository;

/**
 * The user-facing search orchestrator. Designed for sub-200ms responses:
 *
 *   1. Redis cache lookup (normalized query + filters)
 *   2. Deterministic query understanding (typo/merchant/discount/time intent)
 *   3. Optional AI query rewrite (only on low confidence, behind a flag)
 *   4. Meilisearch query (typo-tolerant + ranked), MySQL fallback on outage
 *   5. Score-aware ordering + freshness boost
 *   6. Cache + async search logging
 *
 * It NEVER scrapes — it only reads pre-indexed data, guaranteeing speed.
 */
final class SearchService
{
    private Meilisearch $meili;
    private QueryUnderstanding $understanding;
    private CouponRepository $coupons;
    private MerchantRepository $merchants;
    private SearchLogRepository $searchLogs;
    private AiProviderManager $ai;
    private RedisClient $redis;

    public function __construct()
    {
        $this->merchants = new MerchantRepository();
        $this->meili = new Meilisearch();
        $this->understanding = new QueryUnderstanding($this->merchants);
        $this->coupons = new CouponRepository();
        $this->searchLogs = new SearchLogRepository();
        $this->ai = new AiProviderManager();
        $this->redis = RedisClient::instance();
    }

    /**
     * @return array{query:string, intent:array, source:string, cache_hit:bool,
     *               took_ms:int, count:int, results:array}
     */
    public function search(string $raw, ?int $userId = null, ?string $ip = null, int $limit = 40): array
    {
        $start = microtime(true);
        $limit = max(1, min((int) Env::int('SEARCH_MAX_RESULTS', 40), $limit));
        $cacheKey = 'search:' . md5(mb_strtolower(trim($raw)) . '|' . $limit);

        // 1. Cache.
        $cached = $this->redis->get($cacheKey);
        if ($cached !== null) {
            $payload = json_decode($cached, true);
            if (is_array($payload)) {
                $payload['cache_hit'] = true;
                $payload['took_ms'] = (int) round((microtime(true) - $start) * 1000);
                $this->logSearch($raw, $payload, $userId, $ip, true);
                return $payload;
            }
        }

        // 2. Understanding.
        $intent = $this->understanding->analyze($raw);
        $merchantId = $intent['merchant_id'];
        $keywords = $intent['keywords'] !== '' ? $intent['keywords'] : $intent['normalized'];
        $minDiscount = $intent['discount']['type'] === 'percent' || $intent['discount']['type'] === 'amount'
            ? ($intent['discount']['value'] ?? null)
            : null;

        // 3. AI rewrite on low confidence (best-effort, optional).
        if ($intent['confidence'] < 0.55 && $this->featureEnabled('ai_query_rewrite')) {
            $rewrite = $this->ai->rewriteQuery($raw);
            if ($rewrite) {
                if (!empty($rewrite['keywords'])) {
                    $keywords = (string) $rewrite['keywords'];
                }
                if ($merchantId === null && !empty($rewrite['merchant'])) {
                    $m = $this->merchants->findBySlug($this->slugify((string) $rewrite['merchant']));
                    if ($m) {
                        $merchantId = (int) $m['id'];
                    }
                }
                if ($minDiscount === null && isset($rewrite['discount_value']) && is_numeric($rewrite['discount_value'])) {
                    $minDiscount = (float) $rewrite['discount_value'];
                }
                $intent['ai_rewrite'] = $rewrite;
            }
        }

        // 4. Meilisearch, with MySQL fallback.
        [$results, $source] = $this->execute($keywords, $merchantId, $minDiscount, $intent, $limit);

        // 5. Freshness boost for time-sensitive intent.
        if (($intent['time']['window'] ?? null) === 'fresh') {
            usort($results, fn ($a, $b) => strcmp((string) ($b['valid_until'] ?? ''), (string) ($a['valid_until'] ?? '')));
        }

        $payload = [
            'query'     => $raw,
            'intent'    => $intent,
            'source'    => $source,
            'cache_hit' => false,
            'count'     => count($results),
            'results'   => $results,
            'took_ms'   => (int) round((microtime(true) - $start) * 1000),
        ];

        // 6. Cache + log.
        $ttl = max(5, Env::int('SEARCH_CACHE_TTL', 60));
        $this->redis->set($cacheKey, json_encode($payload, JSON_UNESCAPED_SLASHES), $ttl);
        $this->logSearch($raw, $payload, $userId, $ip, false);

        return $payload;
    }

    /** @return array{0:array,1:string} [results, source] */
    private function execute(string $keywords, ?int $merchantId, ?float $minDiscount, array $intent, int $limit): array
    {
        $filters = ["status = 'active'"];
        if ($merchantId !== null) {
            $filters[] = 'merchant_id = ' . (int) $merchantId;
        }
        if ($minDiscount !== null) {
            $filters[] = 'discount_value >= ' . (float) $minDiscount;
        }

        $meiliResult = $this->meili->search($keywords, [
            'filter' => implode(' AND ', $filters),
            'sort'   => ['score:desc'],
            'limit'  => $limit,
        ]);

        if ($meiliResult !== null) {
            return [$this->normalizeHits($meiliResult['hits']), 'meilisearch'];
        }

        // Fallback: relational search.
        $rows = $this->coupons->search($merchantId, $keywords, $minDiscount, $limit);
        return [array_map([$this, 'normalizeRow'], $rows), 'mysql'];
    }

    private function normalizeHits(array $hits): array
    {
        return array_map(function ($h) {
            return [
                'id'             => (int) ($h['id'] ?? 0),
                'title'          => $h['title'] ?? '',
                'description'    => $h['description'] ?? null,
                'code'           => $h['code'] ?? null,
                'type'           => $h['type'] ?? 'code',
                'discount_type'  => $h['discount_type'] ?? null,
                'discount_value' => isset($h['discount_value']) ? (float) $h['discount_value'] : null,
                'landing_url'    => $h['landing_url'] ?? null,
                'valid_until'    => $h['valid_until'] ?? null,
                'merchant_id'    => (int) ($h['merchant_id'] ?? 0),
                'merchant_name'  => $h['merchant_name'] ?? '',
                'merchant_slug'  => $h['merchant_slug'] ?? '',
                'merchant_logo'  => $h['merchant_logo'] ?? null,
                'score'          => (float) ($h['score'] ?? 0),
            ];
        }, $hits);
    }

    private function normalizeRow(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['merchant_id'] = (int) $r['merchant_id'];
        $r['discount_value'] = $r['discount_value'] !== null ? (float) $r['discount_value'] : null;
        $r['score'] = (float) $r['score'];
        unset($r['success_count'], $r['fail_count'], $r['times_used'], $r['terms'], $r['status'], $r['is_featured'], $r['currency']);
        return $r;
    }

    private function logSearch(string $raw, array $payload, ?int $userId, ?string $ip, bool $cacheHit): void
    {
        try {
            $this->searchLogs->log([
                'user_id'              => $userId,
                'query_raw'            => $raw,
                'query_normalized'     => $payload['intent']['normalized'] ?? null,
                'detected_merchant_id' => $payload['intent']['merchant_id'] ?? null,
                'intent'               => $payload['intent'] ?? [],
                'result_count'         => $payload['count'] ?? 0,
                'took_ms'              => $payload['took_ms'] ?? 0,
                'cache_hit'            => $cacheHit ? 1 : 0,
                'ip'                   => $ip,
            ]);
        } catch (\Throwable) {
            // logging is best-effort
        }
    }

    private function featureEnabled(string $key): bool
    {
        try {
            return (int) \CouponFind\Core\Database::instance()->scalar(
                'SELECT is_enabled FROM feature_flags WHERE `key` = ?',
                [$key]
            ) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
        return trim($s, '-');
    }

    public function suggestions(string $prefix, int $limit = 6): array
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return [];
        }
        $merchants = $this->merchants->all(true);
        $out = [];
        foreach ($merchants as $m) {
            if (stripos($m['name'], $prefix) !== false) {
                $out[] = ['type' => 'merchant', 'label' => $m['name'], 'slug' => $m['slug']];
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }
}
