<?php

declare(strict_types=1);

/**
 * Service registrations. Explicit factories keep the dependency graph
 * visible — no reflection magic. Later modules append here (Database,
 * ApiClient, Logger, repositories, sync services, ...).
 *
 * @return callable(App\Core\Container,string):void
 */

use App\Core\Container;
use App\Core\View;

return static function (Container $c, string $basePath): void {
    $c->singleton(View::class, static fn (): View => new View($basePath . '/resources/views'));
};
