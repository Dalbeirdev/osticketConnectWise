# Configuration Guide

The integration is **multi-tenant**: one osTicket instance serves any number of
client ConnectWise tenants. Each client is registered on the **Clients** page and
gets its own credentials, department routing, import filters and options —
fully isolated from every other client.

- **Global engine settings** — Admin Panel → Manage → Plugins → ConnectWise
  Integration (one page for the whole engine).
- **Per-client settings** — Admin Panel → ConnectWise → **Clients** → Add/Edit.

---

## 1. Global engine settings (plugin config)

| Setting | Meaning |
|---|---|
| Enabled | Master switch for the whole engine. |
| Scheduled Sync Interval | Minimum minutes between inbound pulls per client. |
| Batch Size / Max Retries | Queue worker tuning; defaults are fine. |
| Capture time on reply/note | Reads the reply form's Time Spent fields into ConnectWise time entries. |
| Time field names | Form field names (`time_spent`, `time_type`, `time_bill`) — match the core time-tracking mod. |
| Time Spent unit | `minutes` (default) or `hours` — how the Time Spent number is interpreted. |
| Log Level / Retention | `info` for production, `debug` when diagnosing (payloads are secret-redacted). |

## 2. Registering a client (Clients page)

Each client row holds:

| Field | Meaning |
|---|---|
| Name / Code | Display name and the short badge shown on the blue ticket panel. |
| Company ID + Public Key | The tenant's login company id and API public key, joined with `+` (e.g. `mycompany+AbCdEfGh123`). Create keys under *System » Members » API Members*. |
| Private Key | The API member's private key. Encrypted at rest and never redisplayed. |
| API Client ID | Your integration's clientId from developer.connectwise.com. |
| Site URL | Required — the tenant's regional site, e.g. `https://na.myconnectwise.net`. |
| Department | **Fallback routing:** imported tickets land here unless a board rule (below) says otherwise. |
| Department routing by Board | Optional per-board rules (`+ Add queue rule`): tickets from a mapped ConnectWise board land in the mapped osTicket department (e.g. Level I Support → Support/NOC). Unmapped boards use the fallback. Stored as `boardId=deptId` lines (`dept_map`); applied at import time. |
| Enabled | Per-client switch. When exactly ONE client is enabled, new osTicket tickets auto-push to it. |

### Import filters
Tickets are imported **only** when they match the configured filters — this is
how work is scoped per client (boards/companies/members are ConnectWise-side
concepts, per the system-of-record model):

- Board IDs, Company IDs, Member IDs (comma-separated; blank = all)
- Import open / closed toggles, Import window (days)

The **ID Reference** panel on the edit form lists the tenant's live board,
member and company IDs (↻ Refresh re-pulls them).

### Defaults used when pushing to ConnectWise
Company, Board, Type/Subtype, Priority, Status, **Work Type**, **Member +
Work Role**, and the board's "Closed" status ID. Note: ConnectWise statuses
are **per board** — pick IDs that belong to the default board. The default
member and work role are validated by `selftest.php`.

### Options
| Option | Effect |
|---|---|
| Two-way sync | Outbound pushes (osTicket → ConnectWise). |
| Auto-import | Inbound scheduled import. |
| Inbound notes | Import replies/notes/time entries from ConnectWise. |
| Sync attachments | Files both directions (see §5). |
| Import system notes | Off by default — hides ConnectWise workflow noise. |
| Require time before close | Closures without time are flagged in the audit log. |
| Close osTicket on Complete | ConnectWise Complete closes the osTicket copy. |
| Status Map | **Leave empty.** Name parity (§4) handles everything; map lines (`Name=ID`) exist only for deliberate overrides. |

---

## 3. The attribution model (who is who)

**ConnectWise is the client-facing system of record.** Tickets are created,
queued and assigned there. **osTicket is the internal workshop** where any
number of technicians process the work.

- **Outbound time entries** are attributed to the **ticket's assigned
  ConnectWise resource** (fallbacks: an ConnectWise resource matching the agent's
  email, then the client's default resource). The client never sees the
  internal team structure.
- **The real worker is preserved internally**: thread entries carry the
  agent's name, and the ⏱ panel popup shows a *Technician* column plus a
  *Source* chip (osTicket / ConnectWise) per entry.
- **No agent↔resource mapping is required or performed.** Inbound tickets are
  scoped by import filters, not by technician matching.

## 4. Field parity (same on both sides)

| Field | Behaviour |
|---|---|
| Status | Mirrored **by name**, both directions. osTicket statuses are auto-created from the tenant's status list (Complete = closed state). The reply-form dropdown shows the ConnectWise statuses (plus osTicket's system Open/Closed, which cannot be removed). |
| Priority | Mirrored by name with vocabulary bridge: Medium→Normal, Critical→Emergency, Information→Low. |
| Time Type | The Time Type list mirrors the tenant's Work Types; non-ConnectWise leftovers are disabled. The reply form pre-selects the **ticket's ConnectWise Work Type**. If an agent picks a type with no ConnectWise equivalent, the entry shows the actually-pushed type on both sides. |
| Timestamps | Imported messages, notes, time entries and files carry their **original ConnectWise times** (timezone-safe via the DB session zone). |
| Authors | Imported items show the real ConnectWise resource/contact name; contact-authored notes arrive as customer messages. |
| Hours | Sent with 4-decimal precision (20 min = 0.3333 h). On service tickets ConnectWise derives hours from the start/stop window. |

## 5. Attachments

- **osTicket → ConnectWise**: real file upload (≤ ~6 MB, ConnectWise's API cap).
- **ConnectWise → osTicket**: real file import (the binary comes from the
  by-id attachment endpoint). Oversize/withheld files fall back to a link
  note. Duplicate prevention is ID-based per tenant.

## 6. Loop prevention & dedupe

All imported/exported notes, time entries and attachments are tracked by ID in
`connectwise_note_map`, scoped **per client instance and record type** (schema
v2.1.2+). No visible markers are placed in any client-facing text.
