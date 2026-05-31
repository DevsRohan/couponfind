<?php

declare(strict_types=1);

namespace CouponFind\Controllers;

use CouponFind\Core\Request;
use CouponFind\Core\Response;
use CouponFind\Services\Billing\BillingService;

/**
 * Gateway webhook receivers. Signature is verified against the RAW request
 * body before any processing. These routes are exempt from CSRF/auth.
 */
final class WebhookController
{
    private BillingService $billing;

    public function __construct()
    {
        $this->billing = new BillingService();
    }

    public function stripe(Request $request): Response
    {
        $raw = file_get_contents('php://input') ?: '';
        $sig = $request->header('Stripe-Signature');

        if (!$this->billing->stripe()->verifyWebhook($raw, $sig)) {
            return Response::error('Invalid signature', 400);
        }
        $event = json_decode($raw, true);
        if (!is_array($event)) {
            return Response::error('Invalid payload', 400);
        }
        $this->billing->processWebhook('stripe', $event);
        return Response::ok(['received' => true]);
    }

    public function razorpay(Request $request): Response
    {
        $raw = file_get_contents('php://input') ?: '';
        $sig = $request->header('X-Razorpay-Signature');

        if (!$this->billing->razorpay()->verifyWebhook($raw, $sig)) {
            return Response::error('Invalid signature', 400);
        }
        $event = json_decode($raw, true);
        if (!is_array($event)) {
            return Response::error('Invalid payload', 400);
        }
        $this->billing->processWebhook('razorpay', $event);
        return Response::ok(['received' => true]);
    }
}
