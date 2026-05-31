<?php

declare(strict_types=1);

namespace CouponFind\Core;

/**
 * Express-style router supporting path params ({id}), per-route middleware,
 * and a controller@method or closure handler.
 *
 * A middleware is `callable(Request $req, callable $next): Response`.
 * A handler is `callable(Request $req, array $params): Response` OR a string
 * "Controller@method" resolved through the container.
 */
final class Router
{
    /** @var array<int, array{method:string,regex:string,params:array,handler:mixed,middleware:array}> */
    private array $routes = [];
    /** @var array<int, callable|string> */
    private array $globalMiddleware = [];
    private string $prefix = '';
    private array $groupMiddleware = [];

    public function __construct(private Container $container)
    {
    }

    public function useMiddleware(callable|string $mw): void
    {
        $this->globalMiddleware[] = $mw;
    }

    /** Group routes under a path prefix + shared middleware. */
    public function group(string $prefix, array $middleware, callable $fn): void
    {
        $prevPrefix = $this->prefix;
        $prevMw = $this->groupMiddleware;
        $this->prefix .= $prefix;
        $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);
        $fn($this);
        $this->prefix = $prevPrefix;
        $this->groupMiddleware = $prevMw;
    }

    public function get(string $path, mixed $handler, array $mw = []): void
    {
        $this->add('GET', $path, $handler, $mw);
    }

    public function post(string $path, mixed $handler, array $mw = []): void
    {
        $this->add('POST', $path, $handler, $mw);
    }

    public function put(string $path, mixed $handler, array $mw = []): void
    {
        $this->add('PUT', $path, $handler, $mw);
    }

    public function patch(string $path, mixed $handler, array $mw = []): void
    {
        $this->add('PATCH', $path, $handler, $mw);
    }

    public function delete(string $path, mixed $handler, array $mw = []): void
    {
        $this->add('DELETE', $path, $handler, $mw);
    }

    private function add(string $method, string $path, mixed $handler, array $mw): void
    {
        $full = $this->prefix . $path;
        $params = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function ($m) use (&$params) {
            $params[] = $m[1];
            return '([^/]+)';
        }, $full);
        $regex = '#^' . rtrim($regex, '/') . '$#';

        $this->routes[] = [
            'method'     => $method,
            'regex'      => $regex,
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => array_merge($this->groupMiddleware, $mw),
        ];
    }

    public function dispatch(Request $request): Response
    {
        $path = $request->path();
        $method = $request->method();
        $methodAllowedButNoMatch = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $methodAllowedButNoMatch = true;
                continue;
            }

            array_shift($matches);
            $params = array_combine($route['params'], $matches) ?: [];

            $core = function (Request $req) use ($route, $params): Response {
                return $this->invokeHandler($route['handler'], $req, $params);
            };

            $chain = array_merge($this->globalMiddleware, $route['middleware']);
            $pipeline = $this->buildPipeline($chain, $core);
            return $pipeline($request);
        }

        if ($methodAllowedButNoMatch) {
            return Response::error('Method not allowed', 405);
        }
        return Response::error('Not found', 404);
    }

    private function buildPipeline(array $middleware, callable $core): callable
    {
        $pipeline = $core;
        foreach (array_reverse($middleware) as $mw) {
            $resolved = is_string($mw) ? $this->container->get($mw) : $mw;
            $next = $pipeline;
            $pipeline = function (Request $req) use ($resolved, $next): Response {
                return $resolved($req, $next);
            };
        }
        return $pipeline;
    }

    private function invokeHandler(mixed $handler, Request $req, array $params): Response
    {
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            $controller = $this->container->get($class);
            return $controller->$method($req, $params);
        }
        if (is_callable($handler)) {
            return $handler($req, $params);
        }
        throw new \RuntimeException('Invalid route handler');
    }
}
