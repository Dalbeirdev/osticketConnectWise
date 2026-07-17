<?php
/**
 * Dashboard view. Variables: $widgets (label/value/tone), $configured.
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Dashboard</h1>
    <?php if (empty($configured)): ?>
        <span class="badge text-bg-warning">Not configured — add ConnectWise credentials in Settings</span>
    <?php else: ?>
        <span class="badge text-bg-success">Configured</span>
    <?php endif; ?>
</div>

<div class="row g-3 mb-4">
    <?php foreach (($widgets ?? []) as $w): ?>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="fs-3 fw-bold text-<?= e($w['tone'] ?? 'primary') ?>"><?= e($w['value']) ?></div>
                    <div class="text-muted small"><?= e($w['label']) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <h2 class="h5">Getting started</h2>
        <ol class="mb-0">
            <li>Copy <code>.env.example</code> to <code>.env</code> and fill in the platform database.</li>
            <li>Run the installer (Database module) to create the schema.</li>
            <li>Enter ConnectWise + osTicket credentials on the Settings page.</li>
            <li>Test the connection, then run the initial sync.</li>
        </ol>
    </div>
</div>
