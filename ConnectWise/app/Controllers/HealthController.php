<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;

/**
 * Liveness/health endpoint (JSON) — used by monitoring and the installer's
 * environment check. Database/API health probes are appended by later modules.
 */
final class HealthController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->json([
            'status'  => 'ok',
            'app'     => Env::get('APP_NAME', 'ConnectWise Integration'),
            'env'     => Env::get('APP_ENV', 'production'),
            'php'     => PHP_VERSION,
            'time'    => gmdate('c'),
            'checks'  => [
                'php_version' => version_compare(PHP_VERSION, '8.2.0', '>='),
                'curl'        => function_exists('curl_init'),
                'pdo_mysql'   => extension_loaded('pdo_mysql'),
                'json'        => extension_loaded('json'),
                'openssl'     => extension_loaded('openssl'),
                'storage_writable' => is_writable(dirname(__DIR__, 2) . '/storage/logs'),
            ],
        ]);
    }
}
