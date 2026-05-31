<?php

declare(strict_types=1);

namespace CouponFind\Services\Billing;

use CouponFind\Core\Env;
use CouponFind\Support\Http;

/**
 * Razorpay gateway via the REST API. Creates subscriptions (recurring) and
 * verifies webhook signatures (HMAC-SHA256 of the raw body).
 */
final class RazorpayGateway
{
    private string $keyId;
    private string $keySecret;

    public function __construct()
    {
        $this->keyId = Env::string('RAZORPAY_KEY_ID', '');
        $this->keySecret = Env::string('RAZORPAY_KEY_SECRET', '');
    }

    public function isConfigured(): bool
    {
        return $this->keyId !== '' && $this->keySecret !== '';
    }

    private function authHeader(): string
    {
        return 'Basic ' . base64_encode($this->keyId . ':' . $this->keySecret);
    }

    /**
     * Create a recurring subscription against a Razorpay plan id.
     * @return array{id:string,short_url:string}|null
     */
    public function createSubscription(array $plan, array $user, int $totalCount = 12): ?array
    {
        if (!$this->isConfigured() || empty($plan['razorpay_plan_id'])) {
            return null;
        }
        $res = Http::postJson(
            'https://api.razorpay.com/v1/subscriptions',
            [
                'plan_id'         => $plan['razorpay_plan_id'],
                'total_count'     => $totalCount,
                'customer_notify' => 1,
                'notes'           => ['user_id' => (string) $user['id'], 'plan_id' => (string) $plan['id']],
            ],
            ['Authorization' => $this->authHeader()]
        );
        if (!$res['ok'] || empty($res['json']['id'])) {
            return null;
        }
        return ['id' => $res['json']['id'], 'short_url' => $res['json']['short_url'] ?? ''];
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        $res = Http::postJson(
            'https://api.razorpay.com/v1/subscriptions/' . urlencode($subscriptionId) . '/cancel',
            ['cancel_at_cycle_end' => 0],
            ['Authorization' => $this->authHeader()]
        );
        return $res['ok'];
    }

    public function refund(string $paymentId, ?int $amountPaise = null): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        $payload = $amountPaise !== null ? ['amount' => $amountPaise] : [];
        $res = Http::postJson(
            'https://api.razorpay.com/v1/payments/' . urlencode($paymentId) . '/refund',
            $payload,
            ['Authorization' => $this->authHeader()]
        );
        return $res['ok'];
    }

    /** Verify X-Razorpay-Signature = HMAC-SHA256(body, webhook_secret). */
    public function verifyWebhook(string $payload, ?string $signature): bool
    {
        $secret = Env::string('RAZORPAY_WEBHOOK_SECRET', '');
        if ($secret === '' || $signature === null) {
            return false;
        }
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }
}
