<?php

declare(strict_types=1);

namespace CouponFind\Support;

/**
 * Minimal cURL-based HTTP client used to talk to Meilisearch, AI providers,
 * Stripe and Razorpay. Returns a normalized result array.
 */
final class Http
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, json:mixed, ok:bool, error:?string}
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 20
    ): array {
        $ch = curl_init();
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch) ?: null;
        curl_close($ch);

        $bodyStr = is_string($raw) ? $raw : '';
        $json = null;
        if ($bodyStr !== '') {
            $decoded = json_decode($bodyStr, true);
            $json = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }

        return [
            'status' => $status,
            'body'   => $bodyStr,
            'json'   => $json,
            'ok'     => $error === null && $status >= 200 && $status < 300,
            'error'  => $error,
        ];
    }

    public static function getJson(string $url, array $headers = [], int $timeout = 20): array
    {
        return self::request('GET', $url, $headers, null, $timeout);
    }

    public static function postJson(string $url, array $payload, array $headers = [], int $timeout = 20): array
    {
        $headers['Content-Type'] = 'application/json';
        return self::request('POST', $url, $headers, json_encode($payload, JSON_UNESCAPED_SLASHES), $timeout);
    }

    public static function postForm(string $url, array $fields, array $headers = [], int $timeout = 20): array
    {
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        return self::request('POST', $url, $headers, http_build_query($fields), $timeout);
    }
}
