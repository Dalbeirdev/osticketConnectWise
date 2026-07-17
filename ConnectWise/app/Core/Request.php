<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable-ish HTTP request wrapper over PHP superglobals.
 */
final class Request
{
    /** @var array<string,string> Route parameters ({id} segments). */
    private array $routeParams = [];

    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $post,
        private readonly array $server,
        private readonly array $cookies,
    ) {
    }

    /** Build from the current PHP globals. */
    public static function capture(): self
    {
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            '/' . trim($path, '/'),
            $_GET,
            $_POST,
            $_SERVER,
            $_COOKIE,
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    /** Normalized path with a single leading slash ('/' for the root). */
    public function path(): string
    {
        return $this->path === '/' ? '/' : rtrim($this->path, '/');
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $v = $this->query[$key] ?? null;
        return is_scalar($v) ? (string) $v : $default;
    }

    public function post(string $key, ?string $default = null): ?string
    {
        $v = $this->post[$key] ?? null;
        return is_scalar($v) ? (string) $v : $default;
    }

    /** @return array<string,mixed> Raw POST body (for bulk validation). */
    public function allPost(): array
    {
        return $this->post;
    }

    public function server(string $key, ?string $default = null): ?string
    {
        $v = $this->server[$key] ?? null;
        return is_scalar($v) ? (string) $v : $default;
    }

    public function cookie(string $key, ?string $default = null): ?string
    {
        $v = $this->cookies[$key] ?? null;
        return is_scalar($v) ? (string) $v : $default;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '');
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /** Decoded JSON request body (webhooks), or null when not JSON. */
    public function json(): ?array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,string> $params */
    public function withRouteParams(array $params): self
    {
        $clone = clone $this;
        $clone->routeParams = $params;
        return $clone;
    }

    public function route(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }
}
