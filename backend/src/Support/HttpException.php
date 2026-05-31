<?php

declare(strict_types=1);

namespace CouponFind\Support;

/**
 * Throwable that maps directly to an HTTP error response. Controllers and
 * services throw these for expected error conditions; the kernel renders them.
 */
class HttpException extends \RuntimeException
{
    public function __construct(
        string $message,
        private int $statusCode = 400,
        private array $errors = []
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self($message, 403);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self($message, 404);
    }

    public static function tooManyRequests(string $message = 'Too many requests'): self
    {
        return new self($message, 429);
    }

    public static function validation(array $errors, string $message = 'Validation failed'): self
    {
        return new self($message, 422, $errors);
    }

    public static function paymentRequired(string $message = 'Quota exceeded'): self
    {
        return new self($message, 402);
    }
}
