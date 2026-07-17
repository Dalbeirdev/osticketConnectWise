<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use RuntimeException;

/**
 * Minimal service container: explicit factories, lazy singletons.
 *
 * Deliberately no reflection auto-wiring — every service is registered in
 * app/Config/services.php so the dependency graph stays visible and greppable.
 */
final class Container
{
    /** @var array<string,Closure> Service id => factory. */
    private array $factories = [];

    /** @var array<string,mixed> Resolved singleton instances. */
    private array $instances = [];

    /**
     * Register a lazy singleton factory.
     *
     * @param string  $id      Service id (usually the class FQCN).
     * @param Closure $factory fn(Container $c): object
     */
    public function singleton(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
    }

    /** Register an existing instance. */
    public function instance(string $id, mixed $service): void
    {
        $this->instances[$id] = $service;
    }

    /**
     * Resolve a service (constructing it on first use).
     *
     * @template T
     * @param class-string<T>|string $id
     * @return T|mixed
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new RuntimeException("Service not registered: $id");
        }
        return $this->instances[$id] = ($this->factories[$id])($this);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->instances);
    }
}
