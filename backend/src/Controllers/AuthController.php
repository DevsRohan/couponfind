<?php

declare(strict_types=1);

namespace CouponFind\Controllers;

use CouponFind\Core\Database;
use CouponFind\Core\Env;
use CouponFind\Core\Request;
use CouponFind\Core\Response;
use CouponFind\Repositories\PlanRepository;
use CouponFind\Repositories\UserRepository;
use CouponFind\Security\Csrf;
use CouponFind\Security\Jwt;
use CouponFind\Security\Password;
use CouponFind\Services\Billing\BillingService;
use CouponFind\Support\Audit;
use CouponFind\Support\HttpException;
use CouponFind\Support\Validator;

final class AuthController
{
    private UserRepository $users;
    private Database $db;

    public function __construct()
    {
        $this->users = new UserRepository();
        $this->db = Database::instance();
    }

    public function register(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'name'     => 'required|string|min:2|max:120',
            'email'    => 'required|email|max:190',
            'password' => 'required|string|min:8|max:100',
        ]);

        if ($this->users->emailExists($data['email'])) {
            throw new HttpException('An account with this email already exists.', 409);
        }

        $referredBy = null;
        $refCode = $request->input('referral_code');
        if ($refCode) {
            $referrer = $this->users->findByReferralCode((string) $refCode);
            $referredBy = $referrer['id'] ?? null;
        }

        $userId = $this->users->create($data['name'], $data['email'], $data['password'], 3, $referredBy);

        // Assign default (free) plan.
        $plan = (new PlanRepository())->findBySlug(Env::string('SIGNUP_DEFAULT_PLAN', 'free'));
        if ($plan) {
            (new BillingService())->assignPlan($userId, $plan, 'manual', null, 'active');
        }

        Audit::log($userId, 'auth.register', 'user', (string) $userId, [], $request->ip());

        $user = $this->users->findById($userId);
        return $this->issueTokens($user, 'Account created', 201);
    }

    public function login(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = $this->users->findByEmail($data['email']);
        if ($user === null || !Password::verify($data['password'], $user['password_hash'])) {
            // Uniform error to avoid user enumeration.
            throw new HttpException('Invalid email or password.', 401);
        }
        if ($user['status'] === 'suspended') {
            throw new HttpException('This account has been suspended.', 403);
        }

        // Transparent rehash if parameters changed.
        if (Password::needsRehash($user['password_hash'])) {
            $this->users->updatePassword((int) $user['id'], $data['password']);
        }

        $this->users->touchLogin((int) $user['id'], $request->ip());
        Audit::log((int) $user['id'], 'auth.login', 'user', (string) $user['id'], [], $request->ip());

        return $this->issueTokens($user, 'Logged in');
    }

    public function refresh(Request $request): Response
    {
        $token = $request->input('refresh_token') ?? $request->cookie('cf_refresh');
        if (!$token) {
            throw HttpException::unauthorized('Missing refresh token');
        }
        $hash = Password::hashToken((string) $token);
        $row = $this->db->first(
            'SELECT rt.*, u.status FROM refresh_tokens rt JOIN users u ON u.id = rt.user_id
             WHERE rt.token_hash = ? AND rt.revoked_at IS NULL AND rt.expires_at > NOW() LIMIT 1',
            [$hash]
        );
        if ($row === null) {
            throw HttpException::unauthorized('Invalid or expired refresh token');
        }

        // Rotate: revoke old, issue new.
        $this->db->execute('UPDATE refresh_tokens SET revoked_at = NOW() WHERE id = ?', [$row['id']]);
        $user = $this->users->findById((int) $row['user_id']);
        return $this->issueTokens($user, 'Token refreshed');
    }

    public function logout(Request $request): Response
    {
        $token = $request->input('refresh_token') ?? $request->cookie('cf_refresh');
        if ($token) {
            $this->db->execute('UPDATE refresh_tokens SET revoked_at = NOW() WHERE token_hash = ?', [Password::hashToken((string) $token)]);
        }
        return Response::ok(null, 'Logged out')
            ->withCookie('cf_session', '', time() - 3600)
            ->withCookie('cf_refresh', '', time() - 3600)
            ->withCookie(Csrf::COOKIE, '', time() - 3600, false);
    }

    public function me(Request $request): Response
    {
        $user = $request->user();
        if ($user === null) {
            throw HttpException::unauthorized();
        }
        return Response::ok($this->publicUser($user));
    }

    public function forgotPassword(Request $request): Response
    {
        $data = Validator::make($request->all(), ['email' => 'required|email']);
        $user = $this->users->findByEmail($data['email']);

        // Always respond success to prevent enumeration.
        if ($user) {
            $token = Password::randomToken(32);
            $this->db->execute(
                'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 1 HOUR))',
                [$user['id'], Password::hashToken($token)]
            );
            // In production this is emailed. For dev/demo we return it when APP_DEBUG.
            $payload = ['message' => 'If the email exists, a reset link has been sent.'];
            if (Env::bool('APP_DEBUG', false)) {
                $payload['reset_token'] = $token;
            }
            return Response::ok($payload, 'Reset requested');
        }
        return Response::ok(['message' => 'If the email exists, a reset link has been sent.'], 'Reset requested');
    }

    public function resetPassword(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'token'    => 'required|string',
            'password' => 'required|string|min:8|max:100',
        ]);
        $row = $this->db->first(
            'SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1',
            [Password::hashToken($data['token'])]
        );
        if ($row === null) {
            throw new HttpException('Invalid or expired reset token.', 422);
        }
        $this->users->updatePassword((int) $row['user_id'], $data['password']);
        $this->db->execute('UPDATE password_resets SET used_at = NOW() WHERE id = ?', [$row['id']]);
        // Revoke all refresh tokens for safety.
        $this->db->execute('UPDATE refresh_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$row['user_id']]);
        Audit::log((int) $row['user_id'], 'auth.password_reset', 'user', (string) $row['user_id'], [], $request->ip());

        return Response::ok(null, 'Password updated. Please log in.');
    }

    public function csrf(Request $request): Response
    {
        $token = Csrf::generate();
        return Response::ok(['csrf_token' => $token])
            ->withCookie(Csrf::COOKIE, $token, time() + 7200, false);
    }

    // ---- helpers ----

    private function issueTokens(array $user, string $message, int $status = 200): Response
    {
        $accessTtl = Env::int('JWT_ACCESS_TTL', 900);
        $refreshTtl = Env::int('JWT_REFRESH_TTL', 2592000);

        $access = Jwt::issue([
            'sub'  => (int) $user['id'],
            'typ'  => 'access',
            'role' => $user['role_slug'] ?? 'user',
        ], $accessTtl);

        $refresh = Password::randomToken(32);
        $this->db->execute(
            'INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (?,?,?)',
            [(int) $user['id'], Password::hashToken($refresh), date('Y-m-d H:i:s', time() + $refreshTtl)]
        );

        $csrf = Csrf::generate();

        $body = [
            'user'          => $this->publicUser($user),
            'access_token'  => $access,
            'refresh_token' => $refresh,
            'token_type'    => 'Bearer',
            'expires_in'    => $accessTtl,
            'csrf_token'    => $csrf,
        ];

        $resp = $status === 201 ? Response::created($body, $message) : Response::ok($body, $message);
        return $resp
            ->withCookie('cf_session', $access, time() + $accessTtl)
            ->withCookie('cf_refresh', $refresh, time() + $refreshTtl)
            ->withCookie(Csrf::COOKIE, $csrf, time() + $accessTtl, false);
    }

    private function publicUser(array $user): array
    {
        return [
            'id'            => (int) $user['id'],
            'uuid'          => $user['uuid'] ?? null,
            'name'          => $user['name'],
            'email'         => $user['email'],
            'role'          => $user['role_slug'] ?? 'user',
            'role_name'     => $user['role_name'] ?? null,
            'avatar_url'    => $user['avatar_url'] ?? null,
            'referral_code' => $user['referral_code'] ?? null,
            'is_admin'      => in_array($user['role_slug'] ?? '', ['admin', 'super_admin'], true),
        ];
    }
}
