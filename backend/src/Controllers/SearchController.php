<?php

declare(strict_types=1);

namespace CouponFind\Controllers;

use CouponFind\Core\Request;
use CouponFind\Core\Response;
use CouponFind\Repositories\SubscriptionRepository;
use CouponFind\Security\RateLimiter;
use CouponFind\Services\SearchService;
use CouponFind\Services\UsageService;
use CouponFind\Support\HttpException;
use CouponFind\Support\Validator;

/**
 * Public/authenticated search. Enforces quota:
 *  - logged-in users -> plan/subscription quota (UsageService)
 *  - guests          -> IP-based free allowance (10/day) via the rate limiter
 */
final class SearchController
{
    private SearchService $search;
    private UsageService $usage;

    public function __construct()
    {
        $this->search = new SearchService();
        $this->usage = new UsageService(new SubscriptionRepository());
    }

    public function search(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'q' => 'required|string|min:1|max:200',
        ]);
        $query = trim((string) $data['q']);
        $userId = $request->userId();

        // Quota enforcement.
        if ($userId !== null) {
            if (!$this->usage->canSearch($userId)) {
                $status = $this->usage->status($userId);
                throw HttpException::paymentRequired(
                    sprintf('Search limit reached (%d per %s). Upgrade your plan for more.', $status['limit'], $status['window'])
                );
            }
        } else {
            // Guest allowance: 10 searches/day per IP.
            $rl = RateLimiter::hit('guest_search:' . $request->ip(), 10, 86400);
            if (!$rl['allowed']) {
                throw HttpException::paymentRequired('Free guest limit reached. Please sign up to continue searching.');
            }
        }

        $result = $this->search->search($query, $userId, $request->ip(), (int) ($request->input('limit', 40)));

        // Record usage after a successful (non-cache) search counts toward quota.
        $quota = null;
        if ($userId !== null) {
            $this->usage->record($userId);
            $quota = $this->usage->status($userId);
        }

        return Response::ok([
            'query'     => $result['query'],
            'intent'    => $this->publicIntent($result['intent']),
            'source'    => $result['source'],
            'cache_hit' => $result['cache_hit'],
            'took_ms'   => $result['took_ms'],
            'count'     => $result['count'],
            'results'   => $result['results'],
            'quota'     => $quota,
        ], 'Search complete');
    }

    public function suggest(Request $request): Response
    {
        $prefix = (string) $request->query('q', '');
        return Response::ok(['suggestions' => $this->search->suggestions($prefix)]);
    }

    /** Expose a trimmed intent object to the client (hide internal fields). */
    private function publicIntent(array $intent): array
    {
        return [
            'normalized'   => $intent['normalized'] ?? '',
            'keywords'     => $intent['keywords'] ?? '',
            'merchant_id'  => $intent['merchant_id'] ?? null,
            'merchant'     => $intent['merchant_match']['method'] ?? null,
            'discount'     => $intent['discount'] ?? null,
            'time'         => $intent['time'] ?? null,
            'confidence'   => $intent['confidence'] ?? 0,
            'ai_assisted'  => isset($intent['ai_rewrite']),
        ];
    }
}
