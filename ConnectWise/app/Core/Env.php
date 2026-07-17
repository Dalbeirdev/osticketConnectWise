<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env loader — no external dependency.
 *
 * Supports KEY=VALUE lines, optional single/double quotes, `#` comments and
 * blank lines. Real environment variables always win over file values so
 * containerized deployments can override without editing files.
 */
final class Env
{
    /** @var array<string,string> Values loaded from the .env file. */
    private static array $values = [];

    private static bool $loaded = false;

    /**
     * Load a .env file once. Missing file is not an error (pure-env deploys).
     */
    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!is_file($path) || !is_readable($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // Strip surrounding quotes (either style), preserve inner content.
            if (strlen($value) >= 2
                && (($value[0] === '"' && str_ends_with($value, '"'))
                    || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }
            if ($key !== '') {
                self::$values[$key] = $value;
            }
        }
    }

    /**
     * Read a variable: real environment first, then .env file, then default.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $real = getenv($key);
        if ($real !== false && $real !== '') {
            return $real;
        }
        return self::$values[$key] ?? $default;
    }

    /** Boolean-typed accessor ("1", "true", "yes", "on" are true). */
    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    /** Integer-typed accessor. */
    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return ($v === null || !is_numeric($v)) ? $default : (int) $v;
    }

    /** @internal test support */
    public static function reset(): void
    {
        self::$values = [];
        self::$loaded = false;
    }
}
