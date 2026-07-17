<?php

declare(strict_types=1);

namespace App\Core;

use Closure;

/**
 * Small HTTP router: static paths and `{param}` segments, per-route
 * middleware, controller-class or closure handlers.
 */
final class Router
{
    /**
     * @var array<int,array{method:string,pattern:string,handler:array|Closure,middleware:array<int,class-string>}>
     */
    private array $routes = [];

    /**
     * @param array|Closure           $handler    [Controller::class, 'method'] or closure.
     * @param array<int,class-string> $middleware Middleware class names (run in order).
     */
    public function add(string $method, string $pattern, array|Closure $handler, array $middleware = []): void
    {
        $this->routes[] = [
            'method'     => strtoupper($method),
            'pattern'    => '/' . trim($pattern, '/'),
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    public function get(string $pattern, array|Closure $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, array|Closure $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    /**
     * Match a request; returns the route + extracted params, or null.
     *
     * @return array{handler:array|Closure,middleware:array<int,class-string>,params:array<string,string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $path = '/' . trim($path, '/');
        foreach ($this->routes as $route) {
            if ($route['method'] !== strtoupper($method)) {
                continue;
            }
            $params = $this->matchPattern($route['pattern'], $path);
            if ($params !== null) {
                return [
                    'handler'    => $route['handler'],
                    'middleware' => $route['middleware'],
                    'params'     => $params,
                ];
            }
        }
        return null;
    }

    /**
     * @return array<string,string>|null Params on match (empty array = static match).
     */
    private function matchPattern(string $pattern, string $path): ?array
    {
        if ($pattern === $path) {
            return [];
        }
        if (!str_contains($pattern, '{')) {
            return null;
        }
        $patternParts = explode('/', trim($pattern, '/'));
        $pathParts    = explode('/', trim($path, '/'));
        if (count($patternParts) !== count($pathParts)) {
            return null;
        }
        $params = [];
        foreach ($patternParts as $i => $part) {
            if (preg_match('/^\{(\w+)\}$/', $part, $m)) {
                $params[$m[1]] = rawurldecode($pathParts[$i]);
            } elseif ($part !== $pathParts[$i]) {
                return null;
            }
        }
        return $params;
    }
}
