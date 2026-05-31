<?php

declare(strict_types=1);

namespace CouponFind\Core;

/**
 * Minimal, dependency-free .env loader + typed accessor.
 * Values already present in the real environment (getenv / $_SERVER)
 * take precedence over the .env file, which matches container behaviour.
 */
final class Env
{
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(?string $path = null): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        // Seed from real environment first.
        foreach ($_SERVER as $k => $v) {
            if (is_string($v)) {
                self::$vars[$k] = $v;
            }
        }
        foreach ($_ENV as $k => $v) {
            if (is_string($v)) {
                self::$vars[$k] = $v;
            }
        }

        $path ??= dirname(__DIR__, 2) . '/.env';
        if (!is_file($path)) {
            // Fall back to repository-root .env (when backend is a subdir).
            $alt = dirname(__DIR__, 3) . '/.env';
            $path = is_file($alt) ? $alt : $path;
        }
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip surrounding quotes, drop inline comments for unquoted values.
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'")) {
                $value = substr($value, 1, -1);
            } elseif (str_contains($value, ' #')) {
                $value = trim(substr($value, 0, strpos($value, ' #')));
            }

            // Real environment wins; only fill if absent.
            if (!array_key_exists($key, self::$vars) || self::$vars[$key] === '') {
                self::$vars[$key] = $value;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded) {
            self::load();
        }
        $val = self::$vars[$key] ?? getenv($key);
        if ($val === false || $val === null) {
            return $default;
        }
        return $val;
    }

    public static function string(string $key, string $default = ''): string
    {
        $v = self::get($key, $default);
        return is_string($v) ? $v : (string) $v;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key, null);
        return $v === null ? $default : (int) $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key, null);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }
}
