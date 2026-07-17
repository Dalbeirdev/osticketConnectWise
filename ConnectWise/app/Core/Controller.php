<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base controller: view rendering + JSON/redirect helpers.
 */
abstract class Controller
{
    public function __construct(protected readonly Container $container)
    {
    }

    /**
     * @param array<string,mixed> $data
     */
    protected function view(string $template, array $data = []): Response
    {
        /** @var View $view */
        $view = $this->container->get(View::class);
        return Response::html($view->render($template, $data));
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function redirect(string $to): Response
    {
        return Response::redirect($to);
    }
}
