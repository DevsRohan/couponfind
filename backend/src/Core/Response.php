<?php

declare(strict_types=1);

namespace CouponFind\Core;

/**
 * JSON-first HTTP response. Sent once at the end of the request lifecycle.
 */
final class Response
{
    private int $status = 200;
    private array $headers = [];
    private mixed $body = null;
    private array $cookies = [];

    public static function json(mixed $data, int $status = 200): self
    {
        $r = new self();
        $r->status = $status;
        $r->headers['Content-Type'] = 'application/json; charset=utf-8';
        $r->body = $data;
        return $r;
    }

    public static function ok(mixed $data = null, string $message = 'OK'): self
    {
        return self::json(['success' => true, 'message' => $message, 'data' => $data], 200);
    }

    public static function created(mixed $data = null, string $message = 'Created'): self
    {
        return self::json(['success' => true, 'message' => $message, 'data' => $data], 201);
    }

    public static function error(string $message, int $status = 400, array $errors = []): self
    {
        $payload = ['success' => false, 'message' => $message];
        if ($errors) {
            $payload['errors'] = $errors;
        }
        return self::json($payload, $status);
    }

    public static function noContent(): self
    {
        $r = new self();
        $r->status = 204;
        return $r;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function withCookie(
        string $name,
        string $value,
        int $expires = 0,
        bool $httpOnly = true,
        ?bool $secure = null,
        string $sameSite = 'Lax',
        string $path = '/'
    ): self {
        $this->cookies[] = [
            'name' => $name,
            'value' => $value,
            'options' => [
                'expires'  => $expires,
                'path'     => $path,
                'httponly' => $httpOnly,
                'secure'   => $secure ?? Env::bool('COOKIE_SECURE', false),
                'samesite' => $sameSite,
            ],
        ];
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            // Baseline security headers for every API response.
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
            foreach ($this->cookies as $c) {
                setcookie($c['name'], $c['value'], $c['options']);
            }
        }

        if ($this->status === 204 || $this->body === null) {
            return;
        }

        echo json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
