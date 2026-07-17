<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;

/**
 * Dashboard. Module 1 renders the shell with placeholder widgets; the
 * Sync/Queue/Logs modules replace the placeholders with live data.
 */
final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('dashboard/index', [
            'title'      => 'Dashboard',
            'appName'    => Env::get('APP_NAME', 'ConnectWise Integration'),
            'configured' => Env::get('CW_COMPANY_ID') !== null && Env::get('CW_COMPANY_ID') !== '',
            // Placeholder metrics until the Database + Sync modules land.
            'widgets'    => [
                ['label' => 'Total Tickets',    'value' => '—', 'tone' => 'primary'],
                ['label' => 'Successful Syncs', 'value' => '—', 'tone' => 'success'],
                ['label' => 'Failed Syncs',     'value' => '—', 'tone' => 'danger'],
                ['label' => 'Pending Queue',    'value' => '—', 'tone' => 'warning'],
                ['label' => 'API Health',       'value' => '—', 'tone' => 'info'],
                ['label' => 'Last Sync',        'value' => '—', 'tone' => 'secondary'],
            ],
        ]);
    }
}
