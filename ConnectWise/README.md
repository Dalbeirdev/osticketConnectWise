# ConnectWise — PSA ↔ osTicket Integration Platform

Standalone, self-hosted integration platform synchronizing **ConnectWise PSA**
with **osTicket** — modular MVC architecture designed for additional PSA
connectors (Autotask, HaloPSA, Syncro, SuperOps) behind a common interface.

> Companion to the osTicket-native plugin in
> [`../connectwise-plugin/`](../connectwise-plugin/) — same repo, different
> deployment model: the plugin runs *inside* osTicket; this platform runs
> *beside* it.

## Stack

PHP 8.2+ (8.3-ready) · Composer (PSR-4) · MySQL · Bootstrap 5 · no framework
dependency — small explicit core (`app/Core`): container, router, middleware
chain, PHP-template views.

## Layout

```
ConnectWise/
├── app/
│   ├── Config/         # services.php (DI registrations), configuration
│   ├── Controllers/    # HTTP controllers
│   ├── Core/           # App kernel, Container, Router, Request/Response, View, Env
│   ├── Helpers/        # e(), env() — tiny, everything else is classes
│   ├── Middleware/     # MiddlewareInterface (+ CSRF/Auth in later modules)
│   ├── Models/         # Domain models
│   ├── Repositories/   # Data access
│   └── Services/       # ConnectWise/ OSTicket/ Sync/
├── public/             # Front controller + assets (the ONLY web root)
├── routes/web.php
├── resources/views/    # PHP templates (Bootstrap 5 layout)
├── database/           # Migrations (Database module)
├── storage/logs|cache/
├── tests/              # PHPUnit (Testing module)
└── docs/
```

## Quick start (development)

```bash
cd ConnectWise
composer install            # or: php composer.phar install
copy .env.example .env      # fill in at least APP_KEY + DB_*
php -S 127.0.0.1:8085 -t public public/index.php
```

- `http://127.0.0.1:8085/` — dashboard shell
- `http://127.0.0.1:8085/health` — JSON health/environment check

## Module status

| Module | Status |
|---|---|
| 1. Skeleton: Composer/PSR-4, Env, DI container, router, middleware chain, views, health | ✅ |
| 2. Database: migrations, connection, repositories | ⏳ next |
| 3. ConnectWise API client (auth, retry, pagination) | planned |
| 4. osTicket services (API + DB adapter) | planned |
| 5. Sync engine, queue, scheduler | planned |
| 6+. Mappings, webhooks, dashboard data, installer wizard, tests | planned |

License: GPL-2.0
