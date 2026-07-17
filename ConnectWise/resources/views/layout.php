<?php
/**
 * Shared Bootstrap 5 layout. Variables: $title, $appName, $content.
 * All dynamic output is escaped via e() at the point of echo.
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'ConnectWise Integration') ?> · <?= e($appName ?? 'ConnectWise Integration') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="/assets/css/app.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand fw-semibold" href="/">
            <span class="text-info">CW</span>&nbsp;<?= e($appName ?? 'ConnectWise Integration') ?>
        </a>
        <ul class="navbar-nav me-auto">
            <li class="nav-item"><a class="nav-link" href="/">Dashboard</a></li>
            <!-- Settings / Queue / Logs / Mappings nav items land with their modules -->
        </ul>
        <span class="navbar-text small">ConnectWise PSA ↔ osTicket</span>
    </div>
</nav>
<main class="container-fluid px-4">
    <?= $content ?? '' ?>
</main>
<footer class="container-fluid px-4 py-4 text-muted small">
    ConnectWise Integration Platform
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
