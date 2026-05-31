<?php

declare(strict_types=1);

namespace CouponFind\Core;

/**
 * Application kernel: bootstraps environment, wires the router, dispatches
 * the request, logs it, and emits the response. Any uncaught throwable is
 * converted into a clean JSON error (with detail only when APP_DEBUG=true).
 */
final class App
{
    private Container $container;
    private Router $router;

    public function __construct()
    {
        Env::load();
        date_default_timezone_set('UTC');

        $this->container = Container::instance();
        $this->router = new Router($this->container);
        $this->container->instanceSet(Router::class, $this->router);

        $this->registerErrorHandling();
        $this->loadRoutes();
    }

    private function registerErrorHandling(): void
    {
        $debug = Env::bool('APP_DEBUG', false);

        set_error_handler(function (int $severity, string $message, string $file, int $line) {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        if ($debug) {
            ini_set('display_errors', '1');
            error_reporting(E_ALL);
        } else {
            ini_set('display_errors', '0');
        }
    }

    private function loadRoutes(): void
    {
        $routes = dirname(__DIR__, 2) . '/routes/api.php';
        if (is_file($routes)) {
            (require $routes)($this->router, $this->container);
        }
    }

    public function run(): void
    {
        $start = microtime(true);
        $request = new Request();

        try {
            $response = $this->router->dispatch($request);
        } catch (\CouponFind\Support\HttpException $e) {
            $response = Response::error($e->getMessage(), $e->getStatusCode(), $e->getErrors());
        } catch (\Throwable $e) {
            $debug = Env::bool('APP_DEBUG', false);
            $payload = ['success' => false, 'message' => 'Internal server error'];
            if ($debug) {
                $payload['debug'] = [
                    'error' => $e->getMessage(),
                    'file'  => $e->getFile() . ':' . $e->getLine(),
                ];
            }
            $response = Response::json($payload, 500);
        }

        $tookMs = (int) round((microtime(true) - $start) * 1000);
        $response->header('X-Response-Time', $tookMs . 'ms');
        $response->send();

        $this->logRequest($request, $response, $tookMs);
    }

    private function logRequest(Request $request, Response $response, int $tookMs): void
    {
        // Best-effort, never fatal.
        try {
            Database::instance()->execute(
                'INSERT INTO api_logs (user_id, method, path, status_code, took_ms, ip) VALUES (?,?,?,?,?,?)',
                [
                    $request->userId(),
                    $request->method(),
                    substr($request->path(), 0, 255),
                    $response->status(),
                    $tookMs,
                    @inet_pton($request->ip()) ?: null,
                ]
            );
        } catch (\Throwable) {
            // ignore logging failures
        }
    }
}
