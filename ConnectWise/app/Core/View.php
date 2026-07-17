<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/**
 * PHP-template view renderer. Templates live in resources/views and render
 * inside the shared layout unless $layout is null.
 *
 * Escaping contract: templates escape at the point of echo via e().
 */
final class View
{
    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @param array<string,mixed> $data Variables extracted into the template.
     */
    public function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = $this->renderFile($template, $data);
        if ($layout === null) {
            return $content;
        }
        return $this->renderFile($layout, $data + ['content' => $content]);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function renderFile(string $template, array $data): string
    {
        $file = $this->basePath . '/' . str_replace(['..', '\\'], ['', '/'], $template) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View not found: $template");
        }
        $render = static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                require $__file;
            } catch (Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return (string) ob_get_clean();
        };
        return $render($file, $data);
    }
}
