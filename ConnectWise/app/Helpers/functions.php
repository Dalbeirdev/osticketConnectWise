<?php

declare(strict_types=1);

/**
 * Global helper functions (loaded via composer "files" autoload).
 * Kept deliberately tiny — everything substantial lives in classes.
 */

use App\Core\Env;

if (!function_exists('e')) {
    /** HTML-escape for output at the point of echo. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('env')) {
    /** Environment accessor (real env > .env file > default). */
    function env(string $key, ?string $default = null): ?string
    {
        return Env::get($key, $default);
    }
}
