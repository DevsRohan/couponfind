<?php

declare(strict_types=1);

use CouponFind\Core\Container;
use CouponFind\Core\Router;

/**
 * API route table. Returned as a closure invoked by the kernel.
 *
 * Middleware aliases (resolved via the container):
 *   $auth     -> AuthMiddleware (requires a valid token)
 *   $optional -> OptionalAuthMiddleware (attaches user if present)
 *   $admin    -> AdminMiddleware (requires admin / super_admin)
 *
 * CSRF + global rate limiting are applied as global middleware. CSRF only
 * actually enforces on cookie-authenticated mutating requests.
 */
return function (Router $router, Container $container): void {
    $auth     = \CouponFind\Middleware\AuthMiddleware::class;
    $optional = \CouponFind\Middleware\OptionalAuthMiddleware::class;
    $admin    = \CouponFind\Middleware\AdminMiddleware::class;

    // Controller fully-qualified name helper.
    $c = static fn (string $handler): string => 'CouponFind\\Controllers\\' . $handler;

    // Global middleware.
    $router->useMiddleware(\CouponFind\Middleware\RateLimitMiddleware::class);
    $router->useMiddleware(\CouponFind\Middleware\CsrfMiddleware::class);

    $router->group('/api', [], function (Router $r) use ($c, $auth, $optional, $admin): void {
        // ---- Health ----
        $r->get('/health', $c('HealthController@index'));

        // ---- Auth (public) ----
        $r->group('/auth', [], function (Router $r) use ($c, $auth): void {
            $r->get('/csrf', $c('AuthController@csrf'));
            $r->post('/register', $c('AuthController@register'));
            $r->post('/login', $c('AuthController@login'));
            $r->post('/refresh', $c('AuthController@refresh'));
            $r->post('/logout', $c('AuthController@logout'));
            $r->post('/forgot-password', $c('AuthController@forgotPassword'));
            $r->post('/reset-password', $c('AuthController@resetPassword'));
            $r->get('/me', $c('AuthController@me'), [$auth]);
        });

        // ---- Catalog (public) ----
        $r->get('/plans', $c('PlanController@index'));
        $r->get('/merchants', $c('MerchantController@index'));
        $r->get('/merchants/{slug}', $c('MerchantController@show'));
        $r->get('/coupons/featured', $c('CouponController@featured'));
        $r->get('/coupons/{id}', $c('CouponController@show'));
        $r->get('/merchants/{id}/coupons', $c('CouponController@byMerchant'));
        $r->post('/coupons/{id}/use', $c('CouponController@use'));
        $r->post('/coupons/{id}/feedback', $c('CouponController@feedback'));

        // ---- Search (optional auth -> quota differs for guests vs members) ----
        $r->post('/search', $c('SearchController@search'), [$optional]);
        $r->get('/search/suggest', $c('SearchController@suggest'));

        // ---- Billing webhooks (public, signature-verified) ----
        $r->post('/webhooks/stripe', $c('WebhookController@stripe'));
        $r->post('/webhooks/razorpay', $c('WebhookController@razorpay'));

        // ---- Authenticated user area ----
        $r->group('/me', [$auth], function (Router $r) use ($c): void {
            $r->get('/dashboard', $c('UserController@dashboard'));
            $r->get('/saved', $c('UserController@saved'));
            $r->post('/saved', $c('UserController@save'));
            $r->delete('/saved/{id}', $c('UserController@unsave'));

            $r->get('/watchlist', $c('UserController@watchlist'));
            $r->post('/watchlist', $c('UserController@addWatch'));
            $r->delete('/watchlist/{id}', $c('UserController@removeWatch'));

            $r->get('/alerts', $c('UserController@alerts'));
            $r->post('/alerts', $c('UserController@addAlert'));
            $r->delete('/alerts/{id}', $c('UserController@removeAlert'));

            $r->get('/notifications', $c('UserController@notifications'));
            $r->post('/notifications/{id}/read', $c('UserController@readNotification'));
            $r->post('/notifications/read-all', $c('UserController@readAllNotifications'));

            $r->get('/search-history', $c('UserController@searchHistory'));
            $r->get('/invoices', $c('UserController@invoices'));

            $r->get('/profile', $c('UserController@profile'));
            $r->put('/profile', $c('UserController@updateProfile'));
            $r->post('/change-password', $c('UserController@changePassword'));
            $r->get('/referrals', $c('UserController@referrals'));
        });

        // ---- Subscriptions / billing (authenticated) ----
        $r->group('/subscription', [$auth], function (Router $r) use ($c): void {
            $r->get('', $c('SubscriptionController@current'));
            $r->post('/checkout', $c('SubscriptionController@checkout'));
            $r->post('/cancel', $c('SubscriptionController@cancel'));
        });

        // ---- Super Admin mission control ----
        $r->group('/admin', [$auth, $admin], function (Router $r) use ($c): void {
            $r->get('/dashboard', $c('AdminController@dashboard'));

            $r->get('/users', $c('AdminController@users'));
            $r->post('/users/{id}/status', $c('AdminController@setUserStatus'));
            $r->post('/users/{id}/role', $c('AdminController@setUserRole'));

            $r->get('/plans', $c('AdminController@plans'));
            $r->post('/plans', $c('AdminController@createPlan'));
            $r->put('/plans/{id}', $c('AdminController@updatePlan'));
            $r->delete('/plans/{id}', $c('AdminController@deletePlan'));

            $r->get('/subscriptions', $c('AdminController@subscriptions'));
            $r->post('/subscriptions/assign', $c('AdminController@assignSubscription'));
            $r->post('/subscriptions/{id}/override', $c('AdminController@overrideSubscription'));

            $r->get('/merchants', $c('AdminController@merchants'));
            $r->post('/merchants', $c('AdminController@createMerchant'));
            $r->put('/merchants/{id}', $c('AdminController@updateMerchant'));
            $r->delete('/merchants/{id}', $c('AdminController@deleteMerchant'));

            $r->get('/coupons', $c('AdminController@coupons'));
            $r->post('/coupons/{id}/status', $c('AdminController@setCouponStatus'));
            $r->post('/coupons/{id}/expire', $c('AdminController@expireCoupon'));

            $r->get('/sources', $c('AdminController@sources'));
            $r->post('/sources', $c('AdminController@createSource'));
            $r->delete('/sources/{id}', $c('AdminController@deleteSource'));

            $r->get('/analytics/search', $c('AdminController@searchAnalytics'));
            $r->get('/analytics/revenue', $c('AdminController@revenueAnalytics'));

            $r->get('/ai/providers', $c('AdminController@aiProviders'));
            $r->put('/ai/providers/{id}', $c('AdminController@updateAiProvider'));

            $r->get('/engine/jobs', $c('AdminController@jobs'));
            $r->post('/engine/dispatch', $c('AdminController@dispatchJob'));
            $r->post('/engine/reindex', $c('AdminController@reindex'));

            $r->get('/flags', $c('AdminController@flags'));
            $r->put('/flags/{key}', $c('AdminController@updateFlag'));

            $r->get('/settings', $c('AdminController@settings'));
            $r->put('/settings/{key}', $c('AdminController@updateSetting'));

            $r->get('/logs/audit', $c('AdminController@auditLogs'));
            $r->get('/logs/api', $c('AdminController@apiLogs'));
            $r->get('/health', $c('AdminController@health'));
        });
    });
};
