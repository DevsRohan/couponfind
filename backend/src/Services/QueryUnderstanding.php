<?php

declare(strict_types=1);

namespace CouponFind\Services;

use CouponFind\Core\RedisClient;
use CouponFind\Repositories\MerchantRepository;

/**
 * Turns a messy natural-language query into structured search intent:
 *   - typo normalization
 *   - merchant intent (alias exact-match + fuzzy Levenshtein against alias map)
 *   - discount intent ("20% off", "$10 off", "free shipping")
 *   - time intent ("today", "this week", "new")
 *   - cleaned keyword text for the search engine
 *
 * Fully deterministic and fast (<2ms). The optional AI rewrite lives in
 * SearchService and only runs when this resolver yields low confidence.
 */
final class QueryUnderstanding
{
    /** Common filler words stripped before merchant matching. */
    private const STOPWORDS = [
        'best', 'top', 'coupon', 'coupons', 'code', 'codes', 'promo', 'promos', 'deal', 'deals',
        'offer', 'offers', 'discount', 'discounts', 'sale', 'today', 'now', 'latest', 'new',
        'for', 'the', 'a', 'an', 'on', 'off', 'get', 'find', 'me', 'my', 'of', 'and', 'to', 'with',
    ];

    public function __construct(private MerchantRepository $merchants)
    {
    }

    public function analyze(string $raw): array
    {
        $normalized = $this->normalize($raw);
        $tokens = array_values(array_filter(explode(' ', $normalized), fn ($t) => $t !== ''));

        $discount = $this->detectDiscountIntent($normalized);
        $time = $this->detectTimeIntent($normalized);
        $merchant = $this->detectMerchant($tokens);

        // Keyword text = tokens minus stopwords and minus matched merchant alias.
        $keywordTokens = array_filter($tokens, function ($t) use ($merchant) {
            if (in_array($t, self::STOPWORDS, true)) {
                return false;
            }
            if ($merchant && $t === $merchant['matched_alias']) {
                return false;
            }
            return true;
        });

        $confidence = 0.4;
        if ($merchant) {
            $confidence += 0.4 * ($merchant['score']);
        }
        if ($discount['type'] !== null) {
            $confidence += 0.1;
        }
        if (count($tokens) > 0) {
            $confidence += 0.1;
        }

        return [
            'raw'              => $raw,
            'normalized'       => $normalized,
            'tokens'           => $tokens,
            'keywords'         => trim(implode(' ', $keywordTokens)),
            'merchant_id'      => $merchant['merchant_id'] ?? null,
            'merchant_match'   => $merchant,
            'discount'         => $discount,
            'time'             => $time,
            'confidence'       => round(min(1.0, $confidence), 3),
        ];
    }

    public function normalize(string $raw): string
    {
        $s = mb_strtolower(trim($raw));
        $s = preg_replace('/[^\p{L}\p{N}%$\s.]/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }

    private function detectDiscountIntent(string $s): array
    {
        // Percentage: "20% off", "20 percent"
        if (preg_match('/(\d{1,3})\s*(%|percent|percentage)/', $s, $m)) {
            return ['type' => 'percent', 'value' => (float) $m[1]];
        }
        // Amount: "$10 off", "10 dollars off"
        if (preg_match('/\$\s*(\d{1,5})/', $s, $m) || preg_match('/(\d{1,5})\s*(dollars|usd|rs|inr)/', $s, $m)) {
            return ['type' => 'amount', 'value' => (float) $m[1]];
        }
        if (str_contains($s, 'free shipping') || str_contains($s, 'freeshipping')) {
            return ['type' => 'free_shipping', 'value' => null];
        }
        if (str_contains($s, 'bogo') || str_contains($s, 'buy one')) {
            return ['type' => 'bogo', 'value' => null];
        }
        return ['type' => null, 'value' => null];
    }

    private function detectTimeIntent(string $s): array
    {
        if (preg_match('/\b(today|tonight|now)\b/', $s)) {
            return ['window' => 'today'];
        }
        if (preg_match('/\b(this week|weekend|week)\b/', $s)) {
            return ['window' => 'week'];
        }
        if (preg_match('/\b(this month|month)\b/', $s)) {
            return ['window' => 'month'];
        }
        if (preg_match('/\b(new|latest|recent)\b/', $s)) {
            return ['window' => 'fresh'];
        }
        return ['window' => null];
    }

    /**
     * Detect merchant via the alias map. Exact normalized match wins; otherwise
     * a fuzzy pass uses Levenshtein distance to catch typos ("niek" -> "nike").
     */
    private function detectMerchant(array $tokens): ?array
    {
        $map = $this->aliasMap();
        if ($map === []) {
            return null;
        }

        // Pass 1: exact single-token + adjacent two-token alias match.
        $candidates = $tokens;
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            $candidates[] = $tokens[$i] . ' ' . $tokens[$i + 1];
        }
        foreach ($candidates as $cand) {
            if (isset($map[$cand])) {
                return [
                    'merchant_id'   => $map[$cand]['merchant_id'],
                    'matched_alias' => $cand,
                    'method'        => 'exact',
                    'score'         => 1.0,
                ];
            }
        }

        // Pass 2: fuzzy — find closest alias for each non-stopword token.
        $best = null;
        foreach ($tokens as $token) {
            if (strlen($token) < 3 || in_array($token, self::STOPWORDS, true)) {
                continue;
            }
            foreach ($map as $alias => $info) {
                if (str_contains($alias, ' ')) {
                    continue; // skip multiword in fuzzy pass
                }
                $dist = levenshtein($token, $alias);
                $maxLen = max(strlen($token), strlen($alias));
                $similarity = $maxLen > 0 ? 1 - ($dist / $maxLen) : 0;
                if ($dist <= 2 && $similarity >= 0.6) {
                    if ($best === null || $similarity > $best['score']) {
                        $best = [
                            'merchant_id'   => $info['merchant_id'],
                            'matched_alias' => $token,
                            'method'        => 'fuzzy',
                            'score'         => round($similarity, 3),
                        ];
                    }
                }
            }
        }
        return $best;
    }

    /** Alias map cached in Redis for 5 minutes (DB is source of truth). */
    private function aliasMap(): array
    {
        $redis = RedisClient::instance();
        $cached = $redis->get('alias_map');
        if ($cached !== null) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $map = $this->merchants->aliasMap();
        $redis->set('alias_map', json_encode($map), 300);
        return $map;
    }
}
