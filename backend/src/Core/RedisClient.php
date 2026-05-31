<?php

declare(strict_types=1);

namespace CouponFind\Core;

/**
 * Redis client used for cache, rate-limit counters, and the engine job queue.
 *
 * Uses the native phpredis extension when present; otherwise falls back to a
 * tiny RESP (REdis Serialization Protocol) client over a raw socket so the app
 * works even without the extension installed. All public methods degrade
 * gracefully to no-ops/null if Redis is unreachable (cache is never required
 * for correctness — only for speed).
 */
final class RedisClient
{
    private static ?RedisClient $instance = null;

    private mixed $native = null;          // \Redis instance when available
    /** @var resource|null */
    private $socket = null;
    private bool $available = true;
    private bool $usePhpRedis = false;

    private function __construct()
    {
        $host = Env::string('REDIS_HOST', '127.0.0.1');
        $port = Env::int('REDIS_PORT', 6379);
        $pass = Env::string('REDIS_PASSWORD', '');

        if (class_exists(\Redis::class)) {
            try {
                $r = new \Redis();
                if (@$r->connect($host, $port, 1.5)) {
                    if ($pass !== '') {
                        @$r->auth($pass);
                    }
                    $this->native = $r;
                    $this->usePhpRedis = true;
                    return;
                }
            } catch (\Throwable) {
                // fall through to socket
            }
        }

        // Socket fallback.
        $sock = @fsockopen($host, $port, $errno, $errstr, 1.5);
        if ($sock === false) {
            $this->available = false;
            return;
        }
        stream_set_timeout($sock, 2);
        $this->socket = $sock;
        if ($pass !== '') {
            $this->command(['AUTH', $pass]);
        }
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    // ---- High-level helpers ------------------------------------------------

    public function get(string $key): ?string
    {
        if (!$this->available) {
            return null;
        }
        if ($this->usePhpRedis) {
            $v = $this->native->get($key);
            return $v === false ? null : (string) $v;
        }
        $v = $this->command(['GET', $key]);
        return is_string($v) ? $v : null;
    }

    public function set(string $key, string $value, int $ttlSeconds = 0): void
    {
        if (!$this->available) {
            return;
        }
        if ($this->usePhpRedis) {
            $ttlSeconds > 0 ? $this->native->setex($key, $ttlSeconds, $value) : $this->native->set($key, $value);
            return;
        }
        $ttlSeconds > 0
            ? $this->command(['SET', $key, $value, 'EX', (string) $ttlSeconds])
            : $this->command(['SET', $key, $value]);
    }

    public function del(string $key): void
    {
        if (!$this->available) {
            return;
        }
        $this->usePhpRedis ? $this->native->del($key) : $this->command(['DEL', $key]);
    }

    public function incr(string $key): int
    {
        if (!$this->available) {
            return 0;
        }
        $v = $this->usePhpRedis ? $this->native->incr($key) : $this->command(['INCR', $key]);
        return (int) $v;
    }

    public function expire(string $key, int $ttlSeconds): void
    {
        if (!$this->available) {
            return;
        }
        $this->usePhpRedis ? $this->native->expire($key, $ttlSeconds) : $this->command(['EXPIRE', $key, (string) $ttlSeconds]);
    }

    public function ttl(string $key): int
    {
        if (!$this->available) {
            return -2;
        }
        $v = $this->usePhpRedis ? $this->native->ttl($key) : $this->command(['TTL', $key]);
        return (int) $v;
    }

    /**
     * Fixed-window counter increment that also sets the TTL on first hit.
     * Returns the current count within the window.
     */
    public function incrementWindow(string $key, int $windowSeconds): int
    {
        $count = $this->incr($key);
        if ($count === 1) {
            $this->expire($key, $windowSeconds);
        }
        return $count;
    }

    public function rpush(string $key, string $value): void
    {
        if (!$this->available) {
            return;
        }
        $this->usePhpRedis ? $this->native->rPush($key, $value) : $this->command(['RPUSH', $key, $value]);
    }

    public function lpop(string $key): ?string
    {
        if (!$this->available) {
            return null;
        }
        $v = $this->usePhpRedis ? $this->native->lPop($key) : $this->command(['LPOP', $key]);
        return is_string($v) ? $v : null;
    }

    // ---- RESP socket implementation ---------------------------------------

    /**
     * Send a command using RESP and parse a single reply.
     * @param array<int,string> $args
     */
    private function command(array $args): mixed
    {
        if ($this->socket === null) {
            return null;
        }
        $buf = '*' . count($args) . "\r\n";
        foreach ($args as $a) {
            $buf .= '$' . strlen($a) . "\r\n" . $a . "\r\n";
        }
        if (@fwrite($this->socket, $buf) === false) {
            $this->available = false;
            return null;
        }
        return $this->readReply();
    }

    private function readReply(): mixed
    {
        $line = $this->readLine();
        if ($line === null || $line === '') {
            return null;
        }
        $type = $line[0];
        $payload = substr($line, 1);

        return match ($type) {
            '+' => $payload,                       // simple string
            '-' => null,                            // error
            ':' => (int) $payload,                  // integer
            '$' => $this->readBulk((int) $payload), // bulk string
            '*' => $this->readArray((int) $payload),// array
            default => null,
        };
    }

    private function readBulk(int $len): ?string
    {
        if ($len < 0) {
            return null;
        }
        $data = '';
        $remaining = $len + 2; // include trailing CRLF
        while (strlen($data) < $remaining) {
            $chunk = fread($this->socket, $remaining - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        return substr($data, 0, $len);
    }

    /** @return array<int,mixed> */
    private function readArray(int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $this->readReply();
        }
        return $out;
    }

    private function readLine(): ?string
    {
        $line = fgets($this->socket);
        if ($line === false) {
            $this->available = false;
            return null;
        }
        return rtrim($line, "\r\n");
    }
}
