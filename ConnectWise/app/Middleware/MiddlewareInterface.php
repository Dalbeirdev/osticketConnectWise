<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use Closure;

/**
 * Route middleware contract. Return a Response to short-circuit (e.g. auth
 * redirect, CSRF failure) or call $next($request) to continue the chain.
 */
interface MiddlewareInterface
{
    /**
     * @param Closure(Request):Response $next
     */
    public function handle(Request $request, Closure $next): Response;
}
