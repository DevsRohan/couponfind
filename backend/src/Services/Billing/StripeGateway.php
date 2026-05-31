<?php

declare(strict_types=1);

namespace CouponFind\Services\Billing;

use CouponFind\Core\Env;
use CouponFind\Support\Http;

/**
 * Stripe gateway via the REST API (no SDK dependency). Handles Checkout
 * Session creation for subscriptions and webhook signature verification.
 */
final class StripeGateway
{
    private string $secret;

    public function __construct()
    {
        $this->secret = Env::string('STRIPE_SECRET_KEY', '');
    }

    public function isConfigured(): bool
    {
        return $this->secret !== '';
    }

    /**
     * Create a Checkout Session for a recurring subscription.
     * @return array{id:string,url:string}|null
     */
    public function createCheckoutSession(array $plan, array $user, string $successUrl, string $cancelUrl): ?array
    {
        if (!$this->isConfigured() || empty($plan['stripe_price_id'])) {
            return null;
        }
        $fields = [
            'mode'                                 => 'subscription',
            'success_url'                          => $successUrl,
            'cancel_url'                           => $cancelUrl,
            'customer_email'                       => $user['email'],
            'client_reference_id'                  => (string) $user['id'],
            'line_items[0][price]'                 => $plan['stripe_price_id'],
            'line_items[0][quantity]'              => '1',
            'metadata[user_id]'                    => (string) $user['id'],
            'metadata[plan_id]'                    => (string) $plan['id'],
            'subscription_data[metadata][user_id]' => (string) $user['id'],
            'subscription_data[metadata][plan_id]' => (string) $plan['id'],
        ];

        $res = Http::postForm(
            'https://api.stripe.com/v1/checkout/sessions',
            $fields,
            ['Authorization' => 'Bearer ' . $this->secret]
        );
        if (!$res['ok'] || empty($res['json']['id'])) {
            return null;
        }
        return ['id' => $res['json']['id'], 'url' => $res['json']['url'] ?? ''];
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        $res = Http::request(
            'DELETE',
            'https://api.stripe.com/v1/subscriptions/' . urlencode($subscriptionId),
            ['Authorization' => 'Bearer ' . $this->secret]
        );
        return $res['ok'];
    }

    public function refund(string $paymentIntentId, ?int $amountCents = null): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        $fields = ['payment_intent' => $paymentIntentId];
        if ($amountCents !== null) {
            $fields['amount'] = (string) $amountCents;
        }
        $res = Http::postForm('https://api.stripe.com/v1/refunds', $fields, ['Authorization' => 'Bearer ' . $this->secret]);
        return $res['ok'];
    }

    /**
     * Verify the Stripe-Signature header (scheme v1, HMAC-SHA256 over
     * "{timestamp}.{payload}"), with a 5-minute tolerance.
     */
    public function verifyWebhook(string $payload, ?string $signatureHeader): bool
    {
        $secret = Env::string('STRIPE_WEBHOOK_SECRET', '');
        if ($secret === '' || $signatureHeader === null) {
            return false;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $timestamp = $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }
        if ($timestamp === null || $signatures === []) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }
        return false;
    }
}
