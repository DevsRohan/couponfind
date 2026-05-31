<?php

declare(strict_types=1);

namespace CouponFind\Controllers;

use CouponFind\Core\Env;
use CouponFind\Core\Request;
use CouponFind\Core\Response;
use CouponFind\Repositories\PlanRepository;
use CouponFind\Repositories\SubscriptionRepository;
use CouponFind\Services\Billing\BillingService;
use CouponFind\Services\UsageService;
use CouponFind\Support\Audit;
use CouponFind\Support\HttpException;
use CouponFind\Support\Validator;

final class SubscriptionController
{
    private SubscriptionRepository $subs;
    private PlanRepository $plans;
    private BillingService $billing;
    private UsageService $usage;

    public function __construct()
    {
        $this->subs = new SubscriptionRepository();
        $this->plans = new PlanRepository();
        $this->billing = new BillingService();
        $this->usage = new UsageService($this->subs);
    }

    public function current(Request $request): Response
    {
        $userId = (int) $request->userId();
        $sub = $this->subs->activeForUser($userId);
        return Response::ok([
            'subscription' => $sub,
            'quota'        => $this->usage->status($userId),
            'history'      => $this->subs->recentForUser($userId),
        ]);
    }

    public function checkout(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'plan_id' => 'required|int',
            'gateway' => 'required|in:stripe,razorpay',
        ]);
        $user = $request->user();
        $appUrl = rtrim(Env::string('APP_URL', 'http://localhost:8080'), '/');

        $result = $this->billing->startCheckout(
            $user,
            (int) $data['plan_id'],
            (string) $data['gateway'],
            $appUrl . '/app/billing?status=success',
            $appUrl . '/app/billing?status=cancelled'
        );

        Audit::log((int) $user['id'], 'billing.checkout_started', 'plan', (string) $data['plan_id'], ['gateway' => $data['gateway']], $request->ip());
        return Response::ok($result, 'Checkout created');
    }

    public function cancel(Request $request): Response
    {
        $userId = (int) $request->userId();
        $sub = $this->subs->activeForUser($userId);
        if ($sub === null) {
            throw HttpException::notFound('No active subscription');
        }

        if ($sub['gateway'] === 'stripe' && $sub['gateway_subscription_id']) {
            $this->billing->stripe()->cancelSubscription($sub['gateway_subscription_id']);
        } elseif ($sub['gateway'] === 'razorpay' && $sub['gateway_subscription_id']) {
            $this->billing->razorpay()->cancelSubscription($sub['gateway_subscription_id']);
        }

        $this->subs->cancelAtPeriodEnd((int) $sub['id'], true);
        Audit::log($userId, 'billing.cancel', 'subscription', (string) $sub['id'], [], $request->ip());
        return Response::ok(null, 'Subscription will cancel at period end');
    }
}
