<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\MiddlewareInterface;
use Closure;
use Throwable;

/**
 * Application kernel: boots environment + services, dispatches the request
 * through route middleware to the matched handler, and converts uncaught
 * exceptions into safe error responses.
 */
final class App
{
    private Container $container;
    private Router $router;

    public function __construct(private readonly string $basePath)
    {
        Env::load($this->basePath . '/.env');

        $this->container = new Container();
        $this->container->instance(self::class, $this);
        $this->container->instance(Container::class, $this->container);

        // Service registrations (explicit, greppable).
        $services = require $this->basePath . '/app/Config/services.php';
        $services($this->container, $this->basePath);

        $this->router = new Router();
        $routes = require $this->basePath . '/routes/web.php';
        $routes($this->router);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /** Handle the current HTTP request and emit the response. */
    public function run(): void
    {
        $request = Request::capture();
        try {
            $response = $this->handle($request);
        } catch (Throwable $e) {
            $response = $this->errorResponse($e);
        }
        $response->send();
    }

    public function handle(Request $request): Response
    {
        $match = $this->router->match($request->method(), $request->path());
        if ($match === null) {
            return Response::notFound('No route for ' . $request->method() . ' ' . $request->path());
        }
        $request = $request->withRouteParams($match['params']);

        // Compose the middleware chain around the handler, outermost first.
        $core = function (Request $req) use ($match): Response {
            return $this->invoke($match['handler'], $req);
        };
        foreach (array_reverse($match['middleware']) as $middlewareClass) {
            $next = $core;
            $core = function (Request $req) use ($middlewareClass, $next): Response {
                /** @var MiddlewareInterface $mw */
                $mw = $this->container->get($middlewareClass);
                return $mw->handle($req, Closure::fromCallable($next));
            };
        }
        return $core($request);
    }

    /**
     * @param array|Closure $handler [Controller::class,'method'] or closure.
     */
    private function invoke(array|Closure $handler, Request $request): Response
    {
        if ($handler instanceof Closure) {
            $result = $handler($request, $this->container);
        } else {
            [$class, $method] = $handler;
            $controller = new $class($this->container);
            $result = $controller->$method($request);
        }
        if ($result instanceof Response) {
            return $result;
        }
        return Response::html((string) $result);
    }

    private function errorResponse(Throwable $e): Response
    {
        // Always log; only display details in debug mode.
        error_log('[ConnectWise] ' . $e::class . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (Env::bool('APP_DEBUG')) {
            $body = '<h1>Application Error</h1><pre>'
                . htmlspecialchars($e::class . ': ' . $e->getMessage()
                    . "\n\n" . $e->getTraceAsString(), ENT_QUOTES) . '</pre>';
            return Response::html($body, 500);
        }
        return Response::html('<h1>500</h1><p>An internal error occurred. Check the logs.</p>', 500);
    }
}
