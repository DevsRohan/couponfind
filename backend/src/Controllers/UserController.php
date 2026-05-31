<?php

declare(strict_types=1);

namespace CouponFind\Controllers;

use CouponFind\Core\Database;
use CouponFind\Core\Env;
use CouponFind\Core\Request;
use CouponFind\Core\Response;
use CouponFind\Repositories\EngagementRepository;
use CouponFind\Repositories\SearchLogRepository;
use CouponFind\Repositories\SubscriptionRepository;
use CouponFind\Repositories\UserRepository;
use CouponFind\Security\Password;
use CouponFind\Services\Billing\BillingService;
use CouponFind\Services\UsageService;
use CouponFind\Support\HttpException;
use CouponFind\Support\Validator;

final class UserController
{
    private EngagementRepository $engagement;
    private SearchLogRepository $searchLogs;
    private SubscriptionRepository $subs;
    private UserRepository $users;
    private UsageService $usage;
    private BillingService $billing;
    private Database $db;

    public function __construct()
    {
        $this->engagement = new EngagementRepository();
        $this->searchLogs = new SearchLogRepository();
        $this->subs = new SubscriptionRepository();
        $this->users = new UserRepository();
        $this->usage = new UsageService($this->subs);
        $this->billing = new BillingService();
        $this->db = Database::instance();
    }

    public function dashboard(Request $request): Response
    {
        $userId = (int) $request->userId();
        return Response::ok([
            'quota'         => $this->usage->status($userId),
            'subscription'  => $this->subs->activeForUser($userId),
            'saved_count'   => count($this->engagement->savedCoupons($userId)),
            'watch_count'   => count($this->engagement->watchlist($userId)),
            'unread'        => $this->engagement->unreadCount($userId),
            'recent_search' => $this->searchLogs->recentForUser($userId, 8),
            'saved'         => array_slice($this->engagement->savedCoupons($userId), 0, 6),
        ]);
    }

    // ---- Saved coupons ----
    public function saved(Request $request): Response
    {
        return Response::ok(['coupons' => $this->engagement->savedCoupons((int) $request->userId())]);
    }

    public function save(Request $request): Response
    {
        $data = Validator::make($request->all(), ['coupon_id' => 'required|int']);
        $this->engagement->saveCoupon((int) $request->userId(), (int) $data['coupon_id'], $request->input('note'));
        return Response::ok(null, 'Coupon saved');
    }

    public function unsave(Request $request, array $params): Response
    {
        $this->engagement->unsaveCoupon((int) $request->userId(), (int) $params['id']);
        return Response::ok(null, 'Removed');
    }

    // ---- Watchlist ----
    public function watchlist(Request $request): Response
    {
        return Response::ok(['watchlist' => $this->engagement->watchlist((int) $request->userId())]);
    }

    public function addWatch(Request $request): Response
    {
        $merchantId = $request->input('merchant_id');
        $keyword = $request->input('keyword');
        if (!$merchantId && !$keyword) {
            throw HttpException::validation(['watch' => ['Provide a merchant or keyword.']]);
        }
        $id = $this->engagement->addWatch((int) $request->userId(), $merchantId ? (int) $merchantId : null, $keyword ?: null);
        return Response::created(['id' => $id], 'Added to watchlist');
    }

    public function removeWatch(Request $request, array $params): Response
    {
        $this->engagement->removeWatch((int) $request->userId(), (int) $params['id']);
        return Response::ok(null, 'Removed');
    }

    // ---- Deal alerts ----
    public function alerts(Request $request): Response
    {
        return Response::ok(['alerts' => $this->engagement->alerts((int) $request->userId())]);
    }

    public function addAlert(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'channel'      => 'in:email,in_app',
            'min_discount' => 'numeric',
        ]);
        $id = $this->engagement->addAlert((int) $request->userId(), [
            'merchant_id'  => $request->input('merchant_id') ? (int) $request->input('merchant_id') : null,
            'keyword'      => $request->input('keyword'),
            'min_discount' => $data['min_discount'] ?? null,
            'channel'      => $data['channel'] ?? 'in_app',
        ]);
        return Response::created(['id' => $id], 'Alert created');
    }

    public function removeAlert(Request $request, array $params): Response
    {
        $this->engagement->removeAlert((int) $request->userId(), (int) $params['id']);
        return Response::ok(null, 'Removed');
    }

    // ---- Notifications ----
    public function notifications(Request $request): Response
    {
        $userId = (int) $request->userId();
        return Response::ok([
            'notifications' => $this->engagement->notifications($userId),
            'unread'        => $this->engagement->unreadCount($userId),
        ]);
    }

    public function readNotification(Request $request, array $params): Response
    {
        $this->engagement->markRead((int) $request->userId(), (int) $params['id']);
        return Response::ok(null, 'Marked read');
    }

    public function readAllNotifications(Request $request): Response
    {
        $this->engagement->markAllRead((int) $request->userId());
        return Response::ok(null, 'All marked read');
    }

    // ---- History ----
    public function searchHistory(Request $request): Response
    {
        return Response::ok(['history' => $this->searchLogs->recentForUser((int) $request->userId(), 100)]);
    }

    // ---- Billing ----
    public function invoices(Request $request): Response
    {
        $userId = (int) $request->userId();
        return Response::ok([
            'invoices' => $this->billing->invoicesForUser($userId),
            'payments' => $this->billing->paymentsForUser($userId),
        ]);
    }

    // ---- Profile ----
    public function profile(Request $request): Response
    {
        $user = $this->users->findById((int) $request->userId());
        return Response::ok(['profile' => [
            'id'    => (int) $user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $user['role_slug'],
            'created_at' => $user['created_at'],
        ]]);
    }

    public function updateProfile(Request $request): Response
    {
        $data = Validator::make($request->all(), ['name' => 'required|string|min:2|max:120']);
        $this->db->execute('UPDATE users SET name = ? WHERE id = ?', [$data['name'], (int) $request->userId()]);
        return Response::ok(null, 'Profile updated');
    }

    public function changePassword(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|max:100',
        ]);
        $user = $this->users->findById((int) $request->userId());
        if (!Password::verify($data['current_password'], $user['password_hash'])) {
            throw new HttpException('Current password is incorrect.', 422);
        }
        $this->users->updatePassword((int) $user['id'], $data['password']);
        return Response::ok(null, 'Password changed');
    }

    // ---- Referrals ----
    public function referrals(Request $request): Response
    {
        $userId = (int) $request->userId();
        $user = $this->users->findById($userId);
        $referred = $this->db->all('SELECT name, created_at FROM users WHERE referred_by = ? ORDER BY id DESC', [$userId]);
        $appUrl = rtrim(Env::string('APP_URL', 'http://localhost:8080'), '/');
        return Response::ok([
            'code'      => $user['referral_code'],
            'link'      => $appUrl . '/register?ref=' . $user['referral_code'],
            'referred'  => $referred,
            'count'     => count($referred),
        ]);
    }
}
