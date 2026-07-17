<?php

declare(strict_types=1);

/**
 * HTTP routes. Later modules add settings, queue, logs, mappings, webhooks.
 *
 * @return callable(App\Core\Router):void
 */

use App\Controllers\DashboardController;
use App\Controllers\HealthController;
use App\Core\Router;

return static function (Router $router): void {
    $router->get('/', [DashboardController::class, 'index']);
    $router->get('/health', [HealthController::class, 'index']);
};
