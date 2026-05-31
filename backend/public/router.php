<?php

declare(strict_types=1);

/**
 * Dev router for PHP's built-in server. Run from the repository root:
 *
 *   php -S 127.0.0.1:8080 backend/public/router.php
 *
 * - Requests to /api/* are handled by the PHP application (index.php).
 * - Everything else is served from the frontend/ directory as static files,
 *   with clean-URL fallbacks (/login -> login.html, dir -> index.html, SPA
 *   fallback -> index.html). Files are streamed by this script (with correct
 *   MIME types) so it works regardless of the server's document root.
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$uri = '/' . ltrim(rawurldecode($uri), '/');

// ---- API -> application kernel ----
if ($uri === '/api' || str_starts_with($uri, '/api/')) {
    require __DIR__ . '/index.php';
    return true;
}

$frontend = realpath(dirname(__DIR__, 2) . '/frontend');
if ($frontend === false) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'frontend directory missing';
    return true;
}

/** Stream a file with an appropriate Content-Type, guarding against traversal. */
$serve = static function (string $absPath) use ($frontend): bool {
    $real = realpath($absPath);
    if ($real === false || !is_file($real) || !str_starts_with($real, $frontend)) {
        return false;
    }
    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    $types = [
        'html' => 'text/html; charset=utf-8',
        'css'  => 'text/css; charset=utf-8',
        'js'   => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif', 'ico' => 'image/x-icon', 'webp' => 'image/webp',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'map' => 'application/json',
        'txt'  => 'text/plain; charset=utf-8',
    ];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    readfile($real);
    return true;
};

// 1. Exact static file (e.g. /assets/css/app.css).
if ($uri !== '/' && $serve($frontend . $uri)) {
    return true;
}
// 2. Directory index (e.g. /app -> /app/index.html, / -> /index.html).
if ($serve($frontend . rtrim($uri, '/') . '/index.html')) {
    return true;
}
// 3. Clean URL (e.g. /login -> /login.html).
if ($serve($frontend . rtrim($uri, '/') . '.html')) {
    return true;
}
// 4. SPA fallback to the landing page.
$serve($frontend . '/index.html');
return true;
