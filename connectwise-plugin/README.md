# ConnectWise PSA Integration for osTicket

Production-ready, **native osTicket plugin** providing two-way ticket
synchronization with **ConnectWise PSA (Manage)** via the ConnectWise REST API.

> Built with plain PHP 8.x, MySQL and the osTicket Plugin Framework.
> No Composer/Laravel/Symfony/React/Vue/Node dependencies.

---

## ✨ Features

| # | Capability |
|---|------------|
| 1 | Native plugin install / enable / disable / clean uninstall with **DB migrations** |
| 2 | **Multi-tenant Clients register** — one row per client ConnectWise tenant (credentials encrypted) |
| 3 | **Connection testing** — config saved only after successful validation |
| 4 | **Outbound sync**: osTicket ticket → ConnectWise service ticket (summary, description, priority, status, contact, company, board) |
| 5 | **Ticket updates**: replies, internal notes, status, priority, closure, reopen |
| 6 | **Two-way scheduled sync**: ConnectWise → osTicket (status, notes, closure) with loop prevention |
| 7 | **Ticket mapping** table (IDs, last sync time, last updated by, direction) |
| 8 | **Time entries**: inline capture on the reply form → ConnectWise time entries (work type, member, work role, billable) |
| 9 | **Attachments** both ways (ConnectWise documents ↔ osTicket files) |
| 10 | **Admin dashboard**: connection status, totals, failures, queue stats, logs |
| 11 | **Retry queue** with exponential backoff + manual retry |
| 12 | **Security**: CSRF, escaped queries, input validation, output escaping, encrypted creds, admin-only |
| 13 | **Scheduler**: 5-min interval, manual, full and incremental sync |

---

## 🔑 ConnectWise credentials

Each client tenant needs an **API Member** with keys
(ConnectWise: *System » Members » API Members*), plus a **clientId** for your
integration (register once at <https://developer.connectwise.com>):

| Field in this plugin | ConnectWise value |
|---|---|
| Company ID + Public Key | login company id and public key, joined with `+` — e.g. `mycompany+AbCdEfGh123` |
| Private Key | the API member's private key |
| API Client ID | your integration's clientId |
| Site URL | regional site, e.g. `https://na.myconnectwise.net` |

The plugin authenticates with Basic auth
(`companyId+publicKey:privateKey`) and the `clientId` header, and resolves the
API codebase automatically via `/login/companyinfo`.

### ConnectWise ↔ osTicket concept map

| Plugin term | ConnectWise entity |
|---|---|
| Board ID (queue) | Service **Board** |
| Status ID | Board **Status** (per board!) |
| Member ID (resource) | **Member** |
| Work Type | Time **Work Type** |
| Work Role | Time **Work Role** |
| Contract | **Agreement** |
| Attachment | **Document** |

Use the **ID reference** panel on the client edit page — it lists boards,
statuses, members and companies live from the tenant so you can copy the
numeric IDs.

---

## 📁 Structure

```
connectwise-plugin/
├── plugin.php               # osTicket manifest
├── bootstrap.php            # Plugin class, signal wiring, autoloader
├── config.php               # PluginConfig (admin form + validation)
├── cron.php                 # Optional standalone CLI cron runner
├── class.connectwise.php    # Facade + dashboard stats aggregation
├── class.api.php            # ConnectWise REST client (cURL) + normalization
├── class.apiexception.php   # Typed API exception
├── class.sync.php           # Synchronization engine (in/outbound)
├── class.queue.php          # Retry queue (exponential backoff)
├── class.ticket.php         # Ticket mapping repo + field translation
├── class.timeentry.php      # Inline time capture -> CW time entries
├── class.picklist.php       # Boards/statuses/priorities/members cache
├── class.scheduler.php      # Cron-driven coordinator
├── class.settings.php       # Config accessors + KV runtime state
├── class.instance*.php      # Multi-tenant client register
├── class.installer.php      # Migration runner
├── services/                # Lazy DI container
├── admin/                   # Dashboard + Clients controllers
├── templates/               # Views
├── assets/                  # CSS/JS
├── migrations/              # Numbered schema migrations
├── sql/                     # Full schema snapshot + teardown
└── docs/                    # Guides
```

---

## 🚀 Quick Start

1. Copy the `connectwise-plugin/` folder into your helpdesk's
   `include/plugins/` directory.
2. Admin Panel → **Manage → Plugins → Add New Plugin** → install
   **ConnectWise Integration**, then enable it.
3. Open the plugin dashboard → **Clients → + Add Client** and enter the
   tenant's Company ID + Public Key, Private Key, API Client ID and Site URL.
   **Test Connection**, then Save.
4. Fill the ticket defaults (board, status, priority, company) using the ID
   reference panel, and set the board's "Closed" status ID.
5. Set up cron (see [docs/CRON_SETUP.md](docs/CRON_SETUP.md)).
6. Import a small date range first (Import Filters → last 7 days), verify,
   then enable auto-import.

---

## 🔐 Security model

- Credentials are stored encrypted (osTicket `Crypto`, `SECRET_SALT`).
- Every DB write uses `db_input()` escaping (osTicket's prepared-input layer).
- All admin actions require an authenticated **Admin** and a valid **CSRF token**.
- API request/response logging redacts secrets.
- Output in the dashboard is HTML-escaped at the point of echo.

---

## ⚙️ Requirements

- osTicket **1.17 / 1.18** (latest stable)
- PHP **8.0 – 8.3** with `curl` and `json` extensions
- MySQL 5.7+ / MariaDB 10.3+
- A ConnectWise PSA API Member (public/private key) + integration clientId

---

## 📝 License

GPLv2 — same license as osTicket.

See the `docs/` directory for the Installation, Configuration, API,
Troubleshooting, Database Schema, Cron and Upgrade guides.
