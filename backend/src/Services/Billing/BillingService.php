<?php

declare(strict_types=1);

namespace CouponFind\Services\Billing;

use CouponFind\Core\Database;
use CouponFind\Repositories\EngagementRepository;
use CouponFind\Repositories\PlanRepository;
use CouponFind\Repositories\SubscriptionRepository;

/**
 * Coordinates the billing domain across both gateways: starts checkouts,
 * records subscriptions / invoices / payments, and processes webhooks
 * idempotently (deduped via the webhook_events table).
 */
final class BillingService
{
    private Database $db;
    private PlanRepository $plans;
    private SubscriptionRepository $subs;
    private EngagementRepository $engagement;
    private StripeGateway $stripe;
    private RazorpayGateway $razorpay;

    public function __construct()
    {
        $this->db = Database::instance();
        $this->plans = new PlanRepository();
        $this->subs = new SubscriptionRepository();
        $this->engagement = new EngagementRepository();
        $this->stripe = new StripeGateway();
        $this->razorpay = new RazorpayGateway();
    }

    public function stripe(): StripeGateway
    {
        return $this->stripe;
    }

    public function razorpay(): RazorpayGateway
    {
        return $this->razorpay;
    }

    /**
     * Begin a checkout for a plan via the chosen gateway.
     * @return array{gateway:string,redirect_url:string,reference:string}
     */
    public function startCheckout(array $user, int $planId, string $gateway, string $successUrl, string $cancelUrl): array
    {
        $plan = $this->plans->find($planId);
        if ($plan === null || !$plan['is_active']) {
            throw new \CouponFind\Support\HttpException('Plan not available', 404);
        }
        if ((int) $plan['price_cents'] === 0) {
            // Free plan — assign directly, no gateway.
            $this->assignPlan((int) $user['id'], $plan, 'manual', null, 'active');
            return ['gateway' => 'manual', 'redirect_url' => $successUrl, 'reference' => 'free'];
        }

        if ($gateway === 'stripe') {
            $session = $this->stripe->createCheckoutSession($plan, $user, $successUrl, $cancelUrl);
            if ($session === null) {
                throw new \CouponFind\Support\HttpException('Stripe is not configured for this plan', 422);
            }
            return ['gateway' => 'stripe', 'redirect_url' => $session['url'], 'reference' => $session['id']];
        }

        if ($gateway === 'razorpay') {
            $totalCount = $plan['interval'] === 'year' ? 5 : 12;
            $sub = $this->razorpay->createSubscription($plan, $user, $totalCount);
            if ($sub === null) {
                throw new \CouponFind\Support\HttpException('Razorpay is not configured for this plan', 422);
            }
            return ['gateway' => 'razorpay', 'redirect_url' => $sub['short_url'], 'reference' => $sub['id']];
        }

        throw new \CouponFind\Support\HttpException('Unsupported gateway', 422);
    }

    /** Idempotently record + activate a subscription for a user on a plan. */
    public function assignPlan(int $userId, array $plan, string $gateway, ?string $gatewaySubId, string $status): int
    {
        $existing = $gatewaySubId ? $this->subs->findByGatewayId($gateway, $gatewaySubId) : null;
        if ($existing) {
            $this->subs->updateStatus((int) $existing['id'], $status);
            return (int) $existing['id'];
        }

        $periodEnd = match ($plan['interval']) {
            'year'     => date('Y-m-d H:i:s', strtotime('+1 year')),
            'lifetime' => null,
            'day'      => date('Y-m-d H:i:s', strtotime('+1 day')),
            default    => date('Y-m-d H:i:s', strtotime('+1 month')),
        };

        return $this->subs->create([
            'user_id'                 => $userId,
            'plan_id'                 => (int) $plan['id'],
            'gateway'                 => $gateway,
            'gateway_subscription_id' => $gatewaySubId,
            'status'                  => $status,
            'is_lifetime'             => $plan['interval'] === 'lifetime' ? 1 : 0,
            'current_period_end'      => $periodEnd,
        ]);
    }

    public function recordInvoice(int $userId, ?int $subscriptionId, string $gateway, ?string $gatewayInvoiceId, int $amountCents, string $currency, string $status, ?string $hostedUrl = null): int
    {
        $number = strtoupper('INV-' . date('Ymd') . '-' . bin2hex(random_bytes(3)));
        return $this->db->insert(
            'INSERT INTO invoices (user_id, subscription_id, number, gateway, gateway_invoice_id, amount_cents, currency, status, hosted_url, issued_at, paid_at)
             VALUES (?,?,?,?,?,?,?,?,?,NOW(), ?)',
            [$userId, $subscriptionId, $number, $gateway, $gatewayInvoiceId, $amountCents, $currency, $status, $hostedUrl,
             $status === 'paid' ? date('Y-m-d H:i:s') : null]
        );
    }

    public function recordPayment(int $userId, ?int $invoiceId, string $gateway, string $gatewayPaymentId, int $amountCents, string $currency, string $status, ?string $failureReason = null, array $raw = []): void
    {
        $this->db->execute(
            'INSERT INTO payments (user_id, invoice_id, gateway, gateway_payment_id, amount_cents, currency, status, failure_reason, raw_payload)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), failure_reason = VALUES(failure_reason), retry_count = retry_count + IF(VALUES(status)="failed",1,0)',
            [$userId, $invoiceId, $gateway, $gatewayPaymentId, $amountCents, $currency, $status, $failureReason,
             $raw ? json_encode($raw) : null]
        );
    }

    public function invoicesForUser(int $userId): array
    {
        return $this->db->all(
            'SELECT number, gateway, amount_cents, currency, status, hosted_url, issued_at, paid_at
             FROM invoices WHERE user_id = ? ORDER BY id DESC LIMIT 100',
            [$userId]
        );
    }

    public function paymentsForUser(int $userId): array
    {
        return $this->db->all(
            'SELECT gateway, gateway_payment_id, amount_cents, currency, status, failure_reason, created_at
             FROM payments WHERE user_id = ? ORDER BY id DESC LIMIT 100',
            [$userId]
        );
    }

    // ---- Webhook processing -----------------------------------------------

    /** @return bool whether the event was newly processed */
    public function processWebhook(string $gateway, array $event): bool
    {
        $eventId = (string) ($event['id'] ?? ($event['payload']['subscription']['entity']['id'] ?? bin2hex(random_bytes(8))));
        $type = (string) ($event['type'] ?? ($event['event'] ?? 'unknown'));

        // Idempotency guard.
        try {
            $this->db->insert(
                'INSERT INTO webhook_events (gateway, event_id, type, payload) VALUES (?,?,?,?)',
                [$gateway, $eventId, $type, json_encode($event)]
            );
        } catch (\Throwable) {
            return false; // duplicate -> already processed
        }

        $gateway === 'stripe' ? $this->handleStripe($type, $event) : $this->handleRazorpay($type, $event);

        $this->db->execute('UPDATE webhook_events SET processed_at = NOW() WHERE gateway = ? AND event_id = ?', [$gateway, $eventId]);
        return true;
    }

    private function handleStripe(string $type, array $event): void
    {
        $obj = $event['data']['object'] ?? [];
        $userId = (int) ($obj['metadata']['user_id'] ?? $obj['client_reference_id'] ?? 0);
        $planId = (int) ($obj['metadata']['plan_id'] ?? 0);

        switch ($type) {
            case 'checkout.session.completed':
                $plan = $planId ? $this->plans->find($planId) : null;
                if ($userId && $plan) {
                    $subId = $this->assignPlan($userId, $plan, 'stripe', (string) ($obj['subscription'] ?? ''), 'active');
                    $this->recordInvoice($userId, $subId, 'stripe', (string) ($obj['invoice'] ?? null), (int) ($obj['amount_total'] ?? $plan['price_cents']), strtoupper((string) ($obj['currency'] ?? 'usd')), 'paid');
                    $this->engagement->notify($userId, 'billing', 'Subscription active', 'Your ' . $plan['name'] . ' plan is now active.');
                }
                break;

            case 'invoice.paid':
                if ($userId) {
                    $this->recordInvoice($userId, null, 'stripe', (string) ($obj['id'] ?? null), (int) ($obj['amount_paid'] ?? 0), strtoupper((string) ($obj['currency'] ?? 'usd')), 'paid', $obj['hosted_invoice_url'] ?? null);
                }
                break;

            case 'invoice.payment_failed':
                if ($userId) {
                    $this->recordPayment($userId, null, 'stripe', (string) ($obj['payment_intent'] ?? bin2hex(random_bytes(6))), (int) ($obj['amount_due'] ?? 0), strtoupper((string) ($obj['currency'] ?? 'usd')), 'failed', 'Stripe invoice payment failed');
                    $this->engagement->notify($userId, 'billing', 'Payment failed', 'We could not process your latest payment. Please update your card.');
                }
                break;

            case 'customer.subscription.deleted':
                $sub = $this->subs->findByGatewayId('stripe', (string) ($obj['id'] ?? ''));
                if ($sub) {
                    $this->subs->updateStatus((int) $sub['id'], 'canceled');
                }
                break;
        }
    }

    private function handleRazorpay(string $type, array $event): void
    {
        $subEntity = $event['payload']['subscription']['entity'] ?? [];
        $payEntity = $event['payload']['payment']['entity'] ?? [];
        $userId = (int) ($subEntity['notes']['user_id'] ?? $payEntity['notes']['user_id'] ?? 0);
        $planId = (int) ($subEntity['notes']['plan_id'] ?? 0);

        switch ($type) {
            case 'subscription.activated':
            case 'subscription.charged':
                $plan = $planId ? $this->plans->find($planId) : null;
                if ($userId && $plan) {
                    $subId = $this->assignPlan($userId, $plan, 'razorpay', (string) ($subEntity['id'] ?? ''), 'active');
                    if ($payEntity) {
                        $this->recordPayment($userId, null, 'razorpay', (string) ($payEntity['id'] ?? bin2hex(random_bytes(6))), (int) ($payEntity['amount'] ?? 0), strtoupper((string) ($payEntity['currency'] ?? 'inr')), 'succeeded');
                        $this->recordInvoice($userId, $subId, 'razorpay', null, (int) ($payEntity['amount'] ?? $plan['price_cents']), strtoupper((string) ($payEntity['currency'] ?? 'inr')), 'paid');
                    }
                    $this->engagement->notify($userId, 'billing', 'Subscription active', 'Your ' . $plan['name'] . ' plan is now active.');
                }
                break;

            case 'payment.failed':
                if ($userId) {
                    $this->recordPayment($userId, null, 'razorpay', (string) ($payEntity['id'] ?? bin2hex(random_bytes(6))), (int) ($payEntity['amount'] ?? 0), strtoupper((string) ($payEntity['currency'] ?? 'inr')), 'failed', (string) ($payEntity['error_description'] ?? 'Payment failed'));
                }
                break;

            case 'subscription.cancelled':
                $sub = $this->subs->findByGatewayId('razorpay', (string) ($subEntity['id'] ?? ''));
                if ($sub) {
                    $this->subs->updateStatus((int) $sub['id'], 'canceled');
                }
                break;
        }
    }
}
