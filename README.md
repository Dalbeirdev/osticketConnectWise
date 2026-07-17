# ConnectWise PSA integration with osTicket

Two-way synchronization between **osTicket** and **ConnectWise PSA (Manage)**:
tickets, replies, notes, status/priority, attachments and billable time
entries — multi-tenant (one register entry per client ConnectWise), with an
admin dashboard, retry queue, scheduler and full logging.

➡️ **The plugin lives in [`connectwise-plugin/`](connectwise-plugin/)** — see
its [README](connectwise-plugin/README.md) for features, credential setup and
installation.

Quick version:

1. Copy `connectwise-plugin/` into osTicket's `include/plugins/`.
2. Install + enable **ConnectWise Integration** in Admin » Manage » Plugins.
3. Register each client tenant (Company ID + Public Key, Private Key, API
   Client ID, Site URL) on the Clients page and Test Connection.
4. Configure cron ([docs/CRON_SETUP.md](connectwise-plugin/docs/CRON_SETUP.md)).

Requirements: osTicket 1.17/1.18 · PHP 8.0–8.3 (curl, json) · MySQL/MariaDB ·
ConnectWise API Member keys + integration clientId.

License: GPLv2 (same as osTicket).
