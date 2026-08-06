<?php
/**
 * ConnectWise Integration — Clients (Instance Manager) view template.
 *
 * Variables in scope (from admin/clients.inc.php):
 *   $clients      Instance[]           all registered client ConnectWises
 *   $mappedCounts array<int,int>       instance id => synced ticket count
 *   $mode         string               list | add | edit
 *   $editing      \ConnectWise\Instance|null  instance being edited
 *   $departments  array<int,string>    osTicket departments (id => name)
 *   $csrfToken    string
 *   $notice/$error string|null
 *
 * Output is HTML-escaped at the point of echo. No business logic here.
 *
 * @package ConnectWise Integration
 */
if (!defined('INCLUDE_DIR')) { die('Access denied'); }

/** Small escape helper. */
$e = static function ($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
};

/* Inline assets (include/ is web-blocked; see dashboard template). */
$atCss = @file_get_contents(__DIR__ . '/../assets/css/connectwise.css');
$atJs  = @file_get_contents(__DIR__ . '/../assets/js/connectwise.js');

/* Form value helpers: current row values (edit) or sensible defaults (add). */
$row = $editing ? $editing->raw() : array();
$icfg = $editing ? $editing->configAll() : array(
    // Recommended defaults for a NEW client (small first import, safe toggles).
    'two_way_sync' => 1, 'auto_import_enabled' => 0, 'inbound_notes_enabled' => 1,
    'import_include_open' => 1, 'import_include_closed' => 0, 'import_since_days' => 7,
    'require_time_before_close' => 1, 'close_osticket_on_complete' => 1,
);
$val = static function (string $key, $default = '') use ($row) {
    return isset($row[$key]) ? $row[$key] : $default;
};
$opt = static function (string $key, $default = '') use ($icfg) {
    return array_key_exists($key, $icfg) && $icfg[$key] !== null ? $icfg[$key] : $default;
};
$chk = static function (string $key, bool $default = false) use ($icfg): string {
    $v = array_key_exists($key, $icfg) ? (bool) $icfg[$key] : $default;
    return $v ? 'checked' : '';
};
?>
<style><?= $atCss ?></style>
<div class="at-wrap">
    <header class="at-header">
        <h1>ConnectWise Clients <span class="at-sub"><?=
            $mode === 'list' ? 'Instance Manager'
            : ($mode === 'edit' ? 'Edit Client'
            : ($mode === 'tickets' ? ('Tickets — ' . $e($editing ? $editing->name() : ''))
            : ($mode === 'map' ? ('Field Mappings — ' . $e($editing ? $editing->name() : '')) : 'Register Client'))) ?></span></h1>
        <div class="at-badges">
            <a class="at-btn at-btn-ghost" href="connectwise.php">&larr; Dashboard</a>
            <?php if ($mode === 'list'): ?>
                <a class="at-btn" href="connectwise.php?view=clients&amp;mode=add">+ Add Client</a>
            <?php else: ?>
                <a class="at-btn at-btn-ghost" href="connectwise.php?view=clients">&larr; All Clients</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!empty($notice)): ?><div class="at-alert at-success"><?= $notice ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="at-alert at-error"><?= $error ?></div><?php endif; ?>

<?php if ($mode === 'list'): ?>

    <!-- ================= Client list ================= -->
    <?php if (!$clients): ?>
        <section class="at-box">
            <p class="at-muted">No clients registered yet. Click <strong>+ Add Client</strong> to register the first ConnectWise.</p>
        </section>
    <?php endif; ?>

    <?php foreach ($clients as $c): $r = $c->raw(); ?>
        <section class="at-box at-client<?= $c->enabled() ? '' : ' at-client-off' ?>">
            <div class="at-client-row">
                <div class="at-client-id">
                    <span class="at-code"><?= $e($c->code()) ?></span>
                    <div>
                        <div class="at-client-name"><?= $e($c->name()) ?></div>
                        <div class="at-muted" style="font-size:12px">
                            API user: <?= $e($c->credentials()['username'] ?: '—') ?>
                            &middot; Department: <?= $e($departments[$c->departmentId()] ?? ($c->departmentId() ? ('#' . $c->departmentId()) : 'not set')) ?>
                        </div>
                    </div>
                </div>
                <div class="at-client-stats">
                    <span class="at-badge <?= $c->enabled() ? 'on' : 'off' ?>"><?= $c->enabled() ? 'Enabled' : 'Disabled' ?></span>
                    <span class="at-badge <?= $c->lastOk() === true ? 'ok' : ($c->lastOk() === false ? 'bad' : 'unk') ?>">
                        Connection: <?= $c->lastOk() === true ? 'OK' : ($c->lastOk() === false ? 'FAILED' : 'Untested') ?>
                    </span>
                    <span class="at-badge"><?= (int) ($mappedCounts[$c->id()] ?? 0) ?> synced tickets</span>
                    <span class="at-badge unk">Last sync: <?= $e($c->lastSyncAt() ?: 'never') ?></span>
                </div>
                <div class="at-client-actions">
                    <a class="at-btn at-btn-sm at-btn-ghost" href="connectwise.php?view=clients&amp;mode=tickets&amp;id=<?= $c->id() ?>">Tickets</a>
                    <a class="at-btn at-btn-sm at-btn-ghost" href="connectwise.php?view=clients&amp;mode=map&amp;id=<?= $c->id() ?>">Mappings</a>
                    <a class="at-btn at-btn-sm at-btn-ghost" href="connectwise.php?instance=<?= $c->id() ?>">Dashboard</a>
                    <form method="post" class="at-inline">
                        <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="action" value="sync_client">
                        <input type="hidden" name="client_id" value="<?= $c->id() ?>">
                        <button type="submit" class="at-btn at-btn-sm">Sync Now</button>
                    </form>
                    <a class="at-btn at-btn-sm" href="connectwise.php?view=clients&amp;mode=edit&amp;id=<?= $c->id() ?>">Edit</a>
                    <form method="post" class="at-inline">
                        <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="action" value="test_client">
                        <input type="hidden" name="client_id" value="<?= $c->id() ?>">
                        <input type="hidden" name="c_api_username" value="<?= $e($c->credentials()['username']) ?>">
                        <input type="hidden" name="c_api_integration_code" value="<?= $e($r['api_integration_code']) ?>">
                        <input type="hidden" name="c_zone_url" value="<?= $e($r['zone_url']) ?>">
                        <button type="submit" class="at-btn at-btn-sm at-btn-alt">Test Connection</button>
                    </form>
                    <form method="post" class="at-inline">
                        <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="action" value="toggle_client">
                        <input type="hidden" name="client_id" value="<?= $c->id() ?>">
                        <button type="submit" class="at-btn at-btn-sm <?= $c->enabled() ? 'at-btn-warn' : '' ?>"
                            onclick="return confirm('<?= $c->enabled() ? 'Disable this client? Sync pauses; data is kept.' : 'Enable this client?' ?>');">
                            <?= $c->enabled() ? 'Disable' : 'Enable' ?></button>
                    </form>
                    <?php if (($mappedCounts[$c->id()] ?? 0) === 0): ?>
                    <form method="post" class="at-inline">
                        <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="action" value="delete_client">
                        <input type="hidden" name="client_id" value="<?= $c->id() ?>">
                        <button type="submit" class="at-btn at-btn-sm at-btn-ghost"
                            onclick="return confirm('Delete client <?= $e($c->code()) ?>? Only possible because it has no synced tickets.');">Delete</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endforeach; ?>

<?php elseif ($mode === 'tickets'): ?>

    <!-- ================= Client tickets sub-view ================= -->
    <section class="at-box">
        <div class="at-box-h">
            <span class="at-ico" style="background:#1f6feb"><i class="icon-list"></i></span>
            <div><h2><?= $e($editing->name()) ?> <span class="at-code" style="font-size:11px;padding:3px 6px"><?= $e($editing->code()) ?></span></h2>
                <div class="at-box-sub">Last 200 synced tickets, newest activity first — filters apply instantly &mdash; click a number to open it</div></div>
        </div>

        <?php $fo = isset($filterOptions) ? $filterOptions : array('statuses'=>array(),'depts'=>array(),'orgs'=>array(),'cw'=>array()); ?>
        <style>
            .cwf{display:flex;flex-wrap:wrap;gap:10px 14px;align-items:flex-end;
                 background:#f6f8fa;border:1px solid #e3e8ee;border-radius:8px;padding:10px 12px;margin:0 0 12px}
            .cwf label{display:flex;flex-direction:column;gap:3px;font-size:10px;font-weight:600;
                 letter-spacing:.05em;text-transform:uppercase;color:#6a7687}
            .cwf select{min-width:120px;max-width:200px;padding:5px 8px;font-size:13px;color:#24292f;
                 border:1px solid #d0d7de;border-radius:6px;background:#fff}
            .cwf .cwf-end{margin-left:auto;display:flex;align-items:center;gap:10px;font-size:12px;color:#6a7687}
            .cw-chip{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap}
            .cw-chip-open{background:#dcfce7;color:#166534}
            .cw-chip-closed{background:#e5e7eb;color:#4b5563}
            .cw-drift{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:999px;
                 background:#fef3c7;color:#92400e;font-size:11px;font-weight:600;white-space:nowrap}
            .cw-dim{color:#8a97a8;font-size:12px;white-space:nowrap}
        </style>
        <div class="cwf" id="cwFilters">
            <label>Open / Closed
                <select data-key="state">
                    <option value="">All</option>
                    <option value="open">Open</option>
                    <option value="closed">Closed</option>
                </select></label>
            <label>Status
                <select data-key="status">
                    <option value="">All</option>
                    <?php foreach ($fo['statuses'] as $v => $lbl): ?>
                        <option value="<?= (int) $v ?>"><?= $e($lbl) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label>Board
                <select data-key="dept">
                    <option value="">All</option>
                    <?php foreach ($fo['depts'] as $v => $lbl): ?>
                        <option value="<?= (int) $v ?>"><?= $e($lbl) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label>Company
                <select data-key="org">
                    <option value="">All</option>
                    <?php foreach ($fo['orgs'] as $v => $lbl): ?>
                        <option value="<?= (int) $v ?>"><?= $e($lbl) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label>ConnectWise Status
                <select data-key="cw">
                    <option value="">All</option>
                    <?php foreach ($fo['cw'] as $v => $lbl): ?>
                        <option value="<?= (int) $v ?>"><?= $e($lbl) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <span class="cwf-end">
                <span id="cwCount"><?= count($clientTickets) ?> ticket<?= count($clientTickets) === 1 ? '' : 's' ?></span>
                <button type="button" id="cwReset" class="at-btn at-btn-ghost at-btn-sm" style="display:none">Reset</button>
            </span>
        </div>

        <table class="at-table" id="cwTickets">
            <thead><tr><th>osTicket #</th><th>Subject</th><th>Company</th><th>Board</th>
                <th>Status</th><th>CW #</th><th>Last Sync</th></tr></thead>
            <tbody>
            <?php foreach ($clientTickets as $ct): ?>
                <tr data-state="<?= $e((string) ($ct['state'] ?? '')) ?>"
                    data-status="<?= (int) ($ct['status_id'] ?? 0) ?>"
                    data-dept="<?= (int) ($ct['dept_id'] ?? 0) ?>"
                    data-org="<?= (int) ($ct['org_id'] ?? 0) ?>"
                    data-cw="<?= (int) ($ct['cw_id'] ?? 0) ?>">
                    <td><a href="tickets.php?id=<?= (int) $ct['osticket_ticket_id'] ?>">#<?= $e($ct['number']) ?></a></td>
                    <td><?= $e(mb_strimwidth((string) $ct['subject'], 0, 55, '…')) ?></td>
                    <td><?= $e((string) ($ct['org_name'] ?? '')) ?></td>
                    <td><?= $e((string) ($ct['dept_name'] ?? '')) ?></td>
                    <td>
                        <span class="cw-chip cw-chip-<?= ($ct['state'] ?? '') === 'closed' ? 'closed' : 'open' ?>"><?=
                            $e($ct['status_disp'] !== '' ? $ct['status_disp'] : $ct['status']) ?></span>
                        <?php if (!empty($ct['cw_mismatch'])): ?>
                            <span class="cw-drift" title="ConnectWise shows a different status">CW: <?= $e($ct['cw_mismatch']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= $e($ct['connectwise_ticket_number']) ?></td>
                    <td class="cw-dim"><?= $e($ct['last_sync_disp'] ?? $ct['last_sync_time']) ?></td>
                </tr>
            <?php endforeach; ?>
                <tr id="cwEmpty" style="<?= $clientTickets ? 'display:none' : '' ?>">
                    <td colspan="7" class="at-muted">No synced tickets match these filters.</td></tr>
            </tbody>
        </table>
        <script>
        (function () {
            // Instant, no-reload filtering: every row carries its filter keys as
            // data attributes; the selects just show/hide rows and keep the count
            // honest. Reset appears only while a filter is active.
            var box = document.getElementById('cwFilters');
            if (!box) return;
            var selects = box.querySelectorAll('select[data-key]');
            var rows = document.querySelectorAll('#cwTickets tbody tr:not(#cwEmpty)');
            var empty = document.getElementById('cwEmpty');
            var count = document.getElementById('cwCount');
            var reset = document.getElementById('cwReset');

            function apply() {
                var active = {}, any = false;
                selects.forEach(function (s) {
                    if (s.value !== '') { active[s.getAttribute('data-key')] = s.value; any = true; }
                });
                var shown = 0;
                rows.forEach(function (r) {
                    var ok = true;
                    for (var k in active) {
                        if ((r.getAttribute('data-' + k) || '') !== active[k]) { ok = false; break; }
                    }
                    r.style.display = ok ? '' : 'none';
                    if (ok) shown++;
                });
                if (empty) empty.style.display = shown ? 'none' : '';
                if (count) count.textContent = shown + ' ticket' + (shown === 1 ? '' : 's') + (any ? ' (filtered)' : '');
                if (reset) reset.style.display = any ? '' : 'none';
            }
            selects.forEach(function (s) { s.addEventListener('change', apply); });
            if (reset) reset.addEventListener('click', function () {
                selects.forEach(function (s) { s.value = ''; });
                apply();
            });
        })();
        </script>
    </section>

<?php elseif ($mode === 'map' && $editing): ?>

    <!-- ================= Field Mappings sub-view ================= -->
    <style>
        .fm-note{background:#eef4ff;border:1px solid #d3e0f5;border-radius:8px;padding:10px 12px;
                 font-size:13px;color:#31456b;margin:0 0 12px}
        .fm-board{margin:0 0 4px;font-size:13px;font-weight:700;color:#31456b}
        .fm-sub{font-size:11px;color:#8a97a8;margin-left:6px;font-weight:400}
        .fm-tbl select{min-width:220px;padding:4px 8px;border:1px solid #d0d7de;border-radius:6px}
        .fm-closed{display:inline-block;margin-left:6px;padding:1px 6px;border-radius:999px;
                 background:#e5e7eb;color:#4b5563;font-size:10px;font-weight:600}
        .fm-ok{color:#166534;font-weight:600}
        .fm-miss{color:#92400e;font-weight:600}
    </style>

    <?php if (!empty($fm['err'])): ?>
        <section class="at-box"><p class="at-err">Could not fetch this client's ConnectWise fields:
            <?= $e($fm['err']) ?></p></section>
    <?php else: ?>

    <section class="at-box">
        <div class="fm-note">
            Everything below is fetched <strong>live from this client's own ConnectWise tenant</strong> —
            boards, per-board statuses, priorities, work types and custom fields. Map each ConnectWise value
            to its osTicket counterpart; pairs are stored <strong>per client</strong> and drive the sync in
            <strong>both directions</strong>. Unmapped values fall back to automatic name-matching, then open/closed.
        </div>

        <form method="post">
            <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
            <input type="hidden" name="action" value="save_field_map">
            <input type="hidden" name="client_id" value="<?= (int) $editing->id() ?>">

            <h2>Ticket Statuses <span class="fm-sub">per board — ConnectWise status &rarr; osTicket status</span></h2>
            <?php foreach ($fm['boards'] as $b): ?>
                <div class="fm-board"><?= $e($b['name']) ?> <span class="fm-sub">board #<?= (int) $b['id'] ?></span></div>
                <table class="at-table fm-tbl" style="margin-bottom:14px">
                    <thead><tr><th style="width:45%">ConnectWise status</th><th>osTicket status</th></tr></thead>
                    <tbody>
                    <?php foreach ($b['statuses'] as $s): ?>
                        <tr>
                            <td><?= $e($s['name']) ?> <span class="fm-sub">#<?= (int) $s['id'] ?></span>
                                <?= $s['closed'] ? '<span class="fm-closed">closed</span>' : '' ?></td>
                            <td>
                                <select name="fm_status[<?= (int) $s['id'] ?>]">
                                    <option value="0">— automatic (name match / open-closed) —</option>
                                    <?php foreach ($fm['osStatuses'] as $os): ?>
                                        <option value="<?= (int) $os['id'] ?>"
                                            <?= (int) ($fm['curIn'][$s['id']] ?? 0) === (int) $os['id'] ? 'selected' : '' ?>>
                                            <?= $e($os['name']) ?> (<?= $e($os['state']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$b['statuses']): ?>
                        <tr><td colspan="2" class="at-muted">No statuses on this board.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>

            <h2 style="margin-top:18px">Priorities <span class="fm-sub">ConnectWise priority &rarr; osTicket priority</span></h2>
            <table class="at-table fm-tbl" style="margin-bottom:14px;max-width:720px">
                <thead><tr><th style="width:45%">ConnectWise priority</th><th>osTicket priority</th></tr></thead>
                <tbody>
                <?php foreach ($fm['priorities'] as $p): ?>
                    <tr>
                        <td><?= $e($p['name']) ?> <span class="fm-sub">#<?= (int) $p['id'] ?></span></td>
                        <td>
                            <select name="fm_prio[<?= (int) $p['id'] ?>]">
                                <option value="0">— automatic (name / synonym match) —</option>
                                <?php $curName = mb_strtolower((string) ($fm['curPrio'][$p['id']] ?? '')); ?>
                                <?php foreach ($fm['osPriorities'] as $op): ?>
                                    <option value="<?= (int) $op['priority_id'] ?>"
                                        <?= $curName !== '' && $curName === mb_strtolower($op['priority_desc']) ? 'selected' : '' ?>>
                                        <?= $e($op['priority_desc']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2 style="margin-top:18px">Custom Fields <span class="fm-sub">ConnectWise user-defined field &rarr; osTicket form field</span></h2>
            <?php if (!$fm['custom']): ?>
                <p class="at-muted" style="margin-bottom:14px">This ConnectWise tenant exposes no custom (user-defined)
                    fields via its API — nothing to map. Fields added later appear here automatically.</p>
            <?php else: ?>
            <table class="at-table fm-tbl" style="margin-bottom:14px;max-width:820px">
                <thead><tr><th style="width:45%">ConnectWise custom field</th><th>osTicket field</th></tr></thead>
                <tbody>
                <?php foreach ($fm['custom'] as $cf): ?>
                    <tr>
                        <td><?= $e($cf['caption']) ?> <span class="fm-sub">#<?= (int) $cf['id'] ?>
                            <?= $cf['type'] ? '· ' . $e($cf['type']) : '' ?><?= $cf['screen'] ? ' · ' . $e($cf['screen']) : '' ?></span></td>
                        <td>
                            <select name="fm_cf[<?= (int) $cf['id'] ?>]">
                                <option value="0">— not synced —</option>
                                <?php foreach ($fm['osFields'] as $of): ?>
                                    <option value="<?= (int) $of['id'] ?>"
                                        <?= (int) ($fm['curCf'][$cf['id']] ?? 0) === (int) $of['id'] ? 'selected' : '' ?>>
                                        <?= $e($of['label']) ?> (<?= $e($of['type']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <h2 style="margin-top:18px">Work Types <span class="fm-sub">matched to osTicket Time Types by name (auto-managed)</span></h2>
            <table class="at-table" style="margin-bottom:14px;max-width:620px">
                <thead><tr><th>ConnectWise work type</th><th>osTicket Time Type</th></tr></thead>
                <tbody>
                <?php foreach ($fm['workTypes'] as $wt): ?>
                    <tr>
                        <td><?= $e($wt['name']) ?> <span class="fm-sub">#<?= (int) $wt['id'] ?></span></td>
                        <td><?= isset($fm['osTimeTypes'][mb_strtolower(trim($wt['name']))])
                            ? '<span class="fm-ok">&#10003; matched</span>'
                            : '<span class="fm-miss">missing — re-save the client to auto-create</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <button type="submit" class="at-btn">Save Field Mappings</button>
            <a class="at-btn at-btn-ghost" href="connectwise.php?view=clients">Cancel</a>
        </form>
    </section>
    <?php endif; ?>

<?php else: ?>

    <!-- Standalone form for the ID-reference refresh (cannot nest forms). -->
    <?php if ($editing): ?>
    <form method="post" id="at-refresh-ref" action="connectwise.php?view=clients&amp;mode=edit&amp;id=<?= $editing->id() ?>">
        <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
        <input type="hidden" name="action" value="refresh_ref">
        <input type="hidden" name="client_id" value="<?= $editing->id() ?>">
    </form>
    <?php endif; ?>

    <!-- ================= Add / Edit form ================= -->
    <form method="post" action="connectwise.php?view=clients<?= $editing ? '&amp;mode=edit&amp;id=' . $editing->id() : '&amp;mode=add' ?>">
        <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
        <input type="hidden" name="client_id" value="<?= $editing ? $editing->id() : 0 ?>">

        <section class="at-box">
            <div class="at-box-h">
                <span class="at-ico" style="background:#1f6feb"><i class="icon-briefcase"></i></span>
                <div><h2>Client Identity</h2>
                    <div class="at-box-sub">Who this client is &mdash; per-ticket team routing is done by osTicket Ticket Filters (Admin &rarr; Manage &rarr; Filters)</div></div>
            </div>
            <div class="at-grid">
                <div><label class="at-lbl">Client Name *</label>
                    <input type="text" name="c_name" required maxlength="120" class="at-fld" value="<?= $e($val('name')) ?>" placeholder="e.g. Satellite Networks"></div>
                <div><label class="at-lbl">Client Code * <span class="at-muted">(3–16 letters/digits, unique — shown as badge)</span></label>
                    <input type="text" name="c_code" required maxlength="16" class="at-fld" value="<?= $e($val('code')) ?>" placeholder="e.g. SAT" style="text-transform:uppercase"></div>
                <div><label class="at-lbl">Fallback Department <span class="at-muted">(optional &mdash; used when no Ticket Filter routes the ticket; empty = system default)</span></label>
                    <select name="c_department_id" class="at-fld">
                        <option value="">&mdash; System default department &mdash;</option>
                        <?php foreach ($departments as $dId => $dName): ?>
                            <option value="<?= (int) $dId ?>" <?= (int) $val('department_id') === (int) $dId ? 'selected' : '' ?>><?= $e($dName) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div style="display:flex;align-items:flex-end">
                    <label><input type="checkbox" name="c_enabled" <?= $editing ? ($editing->enabled() ? 'checked' : '') : 'checked' ?>> Enabled (sync runs)</label></div>
            </div>

            <!-- Per-queue department routing: ConnectWise queue -> osTicket dept.
                 No rows = every ticket uses the Fallback Department above. -->
            <div style="margin-top:14px;padding-top:12px;border-top:1px dashed #dbe3ea">
                <label class="at-lbl">Department routing by ConnectWise Board
                    <span class="at-muted">(optional &mdash; tickets from a mapped board land in that department; all other boards use the Fallback Department)</span></label>
                <div id="at-dept-rows"></div>
                <button type="button" id="at-dept-add" class="at-btn" style="margin-top:6px">+ Add queue rule</button>
                <datalist id="at-queue-list">
                    <?php foreach (($refQueues ?? array()) as $q0): ?>
                        <option value="<?= $e($q0['value']) ?>"><?= $e($q0['label']) ?></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <script>
            (function () {
                var rows = document.getElementById('at-dept-rows');
                var depts = <?php $dj = array(); foreach ($departments as $dId => $dName) { $dj[] = array('id' => (int) $dId, 'name' => (string) $dName); } echo json_encode($dj); ?>;
                function addRow(queue, dept) {
                    var div = document.createElement('div');
                    div.style.cssText = 'display:flex;gap:8px;align-items:center;margin-top:6px';
                    var qi = document.createElement('input');
                    qi.type = 'text'; qi.name = 'o_dept_map_queue[]'; qi.className = 'at-fld';
                    qi.placeholder = 'Board ID (see ID reference below)'; qi.setAttribute('list', 'at-queue-list');
                    qi.style.maxWidth = '260px'; qi.value = queue || '';
                    var arrow = document.createElement('span'); arrow.innerHTML = '&rarr;'; arrow.style.color = '#8a949e';
                    var ds = document.createElement('select');
                    ds.name = 'o_dept_map_dept[]'; ds.className = 'at-fld'; ds.style.maxWidth = '260px';
                    var o0 = document.createElement('option'); o0.value = ''; o0.textContent = '— choose department —'; ds.appendChild(o0);
                    depts.forEach(function (d) {
                        var o = document.createElement('option'); o.value = String(d.id); o.textContent = d.name;
                        if (dept && String(dept) === String(d.id)) { o.selected = true; }
                        ds.appendChild(o);
                    });
                    var x = document.createElement('button');
                    x.type = 'button'; x.textContent = '✕'; x.title = 'Remove rule';
                    x.style.cssText = 'border:1px solid #cfd6dc;background:#fff;border-radius:5px;padding:4px 9px;cursor:pointer;color:#c0392b';
                    x.onclick = function () { div.parentNode.removeChild(div); };
                    div.appendChild(qi); div.appendChild(arrow); div.appendChild(ds); div.appendChild(x);
                    rows.appendChild(div);
                }
                document.getElementById('at-dept-add').onclick = function () { addRow('', ''); };
                // Prefill saved rules ("queueId=deptId" per line).
                <?php foreach (preg_split('/\r\n|\r|\n/', (string) $opt('dept_map')) as $ln0) {
                    if (strpos($ln0, '=') === false) { continue; }
                    list($q1, $d1) = array_map('trim', explode('=', $ln0, 2));
                    if ($q1 !== '' && $d1 !== '') {
                        echo 'addRow(' . json_encode($q1) . ',' . json_encode($d1) . ");\n";
                    }
                } ?>
            })();
            </script>
        </section>

        <section class="at-box">
            <div class="at-box-h">
                <span class="at-ico" style="background:#6f42c1"><i class="icon-key"></i></span>
                <div><h2>ConnectWise API Credentials</h2>
                    <div class="at-box-sub">The API Member keys this client issued for your integration (System &raquo; Members &raquo; API Members) &mdash; stored encrypted</div></div>
            </div>
            <div class="at-grid">
                <div><label class="at-lbl">Company ID + Public Key * <span class="at-muted">(login company id and public key, joined with "+")</span></label>
                    <input type="text" name="c_api_username" required class="at-fld" autocomplete="off" value="<?= $e($val('api_username')) ?>" placeholder="mycompany+AbCdEfGh123"></div>
                <div><label class="at-lbl">Private Key <?= $editing ? '<span class="at-muted">(leave blank to keep stored key)</span>' : '*' ?></label>
                    <input type="password" name="c_api_secret" class="at-fld" autocomplete="new-password" value="" <?= $editing ? '' : 'required' ?>></div>
                <div><label class="at-lbl">API Client ID * <span class="at-muted">(from developer.connectwise.com)</span></label>
                    <input type="text" name="c_api_integration_code" required class="at-fld" autocomplete="off" value="<?= $e($val('api_integration_code')) ?>"></div>
                <div><label class="at-lbl">Site URL *</label>
                    <input type="text" name="c_zone_url" required class="at-fld" value="<?= $e($val('zone_url')) ?>" placeholder="https://na.myconnectwise.net"></div>
            </div>
            <div style="margin-top:10px">
                <button type="submit" name="action" value="test_client" class="at-btn at-btn-alt">Test Connection</button>
                <span class="at-muted" style="font-size:12px">Tests the credentials above without saving.</span>
            </div>
        </section>

        <section class="at-box">
            <div class="at-box-h">
                <span class="at-ico" style="background:#1f8f4d"><i class="icon-refresh"></i></span>
                <div><h2>Synchronisation</h2>
                    <div class="at-box-sub">What flows automatically between osTicket and this client's ConnectWise</div></div>
            </div>
            <div class="at-checks at-checks-desc">
                <label><input type="checkbox" name="o_two_way_sync" <?= $chk('two_way_sync', true) ?>>
                    <span><strong>Two-way sync</strong>
                    <small>Pull changes made in the client's ConnectWise back into osTicket: status changes move your ticket, their notes/time entries appear in the thread. Off = push-only (osTicket &rarr; ConnectWise still works, nothing comes back).</small></span></label>
                <label><input type="checkbox" name="o_auto_import_enabled" <?= $chk('auto_import_enabled') ?>>
                    <span><strong>Auto-import new tickets</strong>
                    <small>Every sync cycle, brand-new ConnectWise tickets that pass the Import Filters below are created in osTicket automatically &mdash; hands-free intake. Keep OFF during onboarding; enable after a verified manual import.</small></span></label>
                <label><input type="checkbox" name="o_inbound_notes_enabled" <?= $chk('inbound_notes_enabled', true) ?>>
                    <span><strong>Import ConnectWise notes</strong>
                    <small>Notes written on the ConnectWise side show in the osTicket thread (client-visible notes as replies, their internal notes as internal). Off = you would only see status changes, never their words. Needs Two-way sync.</small></span></label>
                <label><input type="checkbox" name="o_sync_attachments" <?= $chk('sync_attachments', true) ?>>
                    <span><strong>Sync attachments</strong>
                    <small>Files and pasted screenshots on osTicket replies upload to the ConnectWise ticket's Attachments tab (up to ~6&nbsp;MB per file; larger are logged and skipped). Off = text syncs, files stay in osTicket only.</small></span></label>
                <label><input type="checkbox" name="o_import_system_notes" <?= $chk('import_system_notes') ?>>
                    <span><strong>Import ConnectWise system notes</strong>
                    <small>Machine-generated notes (workflow rules, "Forwarded Ticket", assignment/SLA noise &mdash; anything with no human author). OFF recommended: keeps your threads human-only; a runaway ConnectWise workflow can't flood osTicket.</small></span></label>
            </div>
            <div style="margin-top:12px">
                <label class="at-lbl">Status Map &mdash; one per line: <code>osTicket Status Name=ConnectWise status ID</code>
                    <span class="at-muted">(drives the single Ticket Status dropdown; status IDs are per board &mdash; see the ID reference; unmapped statuses fall back to open/closed)</span></label>
                <textarea name="o_status_map" class="at-fld" rows="4"
                    placeholder="Open=201&#10;In Progress=202&#10;Resolved=205&#10;Closed=205"><?= $e($opt('status_map')) ?></textarea>
            </div>
            <div style="margin-top:12px">
                <label class="at-lbl">Priority Map &mdash; one per line: <code>osTicket Priority Name=ConnectWise priority ID</code>
                    <span class="at-muted">(optional; empty = automatic name matching with Medium&harr;Normal, Critical&harr;Emergency synonyms. See the ID reference for this tenant's priority IDs.)</span></label>
                <textarea name="o_priority_map" class="at-fld" rows="4"
                    placeholder="Emergency=1&#10;High=2&#10;Normal=4&#10;Low=6"><?= $e($opt('priority_map')) ?></textarea>
            </div>
            <!-- Field Mappings screen data: round-tripped so a plain client save
                 never wipes it. Edit visually via the Mappings button instead. -->
            <input type="hidden" name="o_status_map_inbound" value="<?= $e($opt('status_map_inbound')) ?>">
            <input type="hidden" name="o_custom_field_map" value="<?= $e($opt('custom_field_map')) ?>">
        </section>

        <section class="at-box">
            <div class="at-box-h">
                <span class="at-ico" style="background:#e67e22"><i class="icon-filter"></i></span>
                <div><h2>Import Filters</h2>
                    <div class="at-box-sub">Which of this client's ConnectWise tickets are yours to import &mdash; queue and resource lists combine as OR</div></div>
            </div>
            <div class="at-checks" style="margin-bottom:12px">
                <label><input type="checkbox" name="o_import_include_open" <?= $chk('import_include_open', true) ?>> Open / active tickets</label>
                <label><input type="checkbox" name="o_import_include_closed" <?= $chk('import_include_closed') ?>> Completed / closed tickets</label>
            </div>
            <div class="at-grid">
                <div><label class="at-lbl">Only these status values <span class="at-muted">(comma-separated IDs; empty = by checkboxes above)</span></label>
                    <input type="text" name="o_import_status_ids" class="at-fld" value="<?= $e($opt('import_status_ids')) ?>"></div>
                <div><label class="at-lbl">Limit to Company IDs</label>
                    <input type="text" name="o_import_company_ids" class="at-fld" value="<?= $e($opt('import_company_ids')) ?>"></div>
                <div><label class="at-lbl">Limit to Board IDs</label>
                    <input type="text" name="o_import_queue_ids" class="at-fld" value="<?= $e($opt('import_queue_ids')) ?>"></div>
                <div><label class="at-lbl">Limit to assigned Member IDs</label>
                    <input type="text" name="o_import_resource_ids" class="at-fld" value="<?= $e($opt('import_resource_ids')) ?>"></div>
                <div><label class="at-lbl">Only tickets active in the last N days <span class="at-muted">(recommend 7 for a new client)</span></label>
                    <input type="number" name="o_import_since_days" min="0" max="3650" class="at-fld" value="<?= $e($opt('import_since_days', 7)) ?>"></div>
            </div>
            <?php if (!empty($refQueues) || !empty($refResources) || !empty($refCompanies)): ?>
            <div style="margin-top:14px;padding-top:12px;border-top:1px dashed #e4e8ec">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">
                    <span class="at-lbl" style="margin:0">&#128269; ID reference &mdash; live from this client's ConnectWise (copy the number you need)</span>
                    <button type="submit" form="at-refresh-ref" class="at-btn at-btn-sm at-btn-ghost">&#8635; Refresh now</button>
                </div>
                <div class="at-grid">
                    <?php $refBlocks = array('Boards' => $refQueues, 'Members (your techs)' => $refResources, 'Companies (first 30)' => $refCompanies);
                    foreach ($refBlocks as $refTitle => $refList): if (!$refList) continue; ?>
                    <div>
                        <div style="font-size:11px;font-weight:700;color:#8a949e;text-transform:uppercase;margin-bottom:4px"><?= $e($refTitle) ?></div>
                        <div style="max-height:150px;overflow:auto;border:1px solid #e8edf2;border-radius:8px;background:#fbfcfe;font-size:12px">
                            <?php foreach ($refList as $ri): ?>
                                <div style="padding:4px 10px;border-bottom:1px solid #f1f4f8;display:flex;gap:8px">
                                    <code style="color:#1f6feb;min-width:80px"><?= $e($ri['value']) ?></code>
                                    <span><?= $e($ri['label']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </section>

        <section class="at-box">
            <div class="at-box-h">
                <span class="at-ico" style="background:#51606e"><i class="icon-cog"></i></span>
                <div><h2>ConnectWise Ticket Defaults</h2>
                    <div class="at-box-sub">Numeric IDs from THIS client's ConnectWise &mdash; used when creating service tickets there</div></div>
            </div>
            <div class="at-grid">
                <div><label class="at-lbl">Default Company ID</label>
                    <input type="number" name="o_default_company_id" class="at-fld" value="<?= $e($opt('default_company_id')) ?>"></div>
                <div><label class="at-lbl">Default Board ID</label>
                    <input type="number" name="o_default_queue_id" class="at-fld" value="<?= $e($opt('default_queue_id')) ?>"></div>
                <div><label class="at-lbl">Default Priority ID</label>
                    <input type="number" name="o_default_priority" class="at-fld" value="<?= $e($opt('default_priority')) ?>"></div>
                <div><label class="at-lbl">Default Status ID <span class="at-muted">(statuses are per board)</span></label>
                    <input type="number" name="o_default_status" class="at-fld" value="<?= $e($opt('default_status')) ?>"></div>
                <div><label class="at-lbl">Default Type ID <span class="at-muted">(board Type, optional)</span></label>
                    <input type="number" name="o_default_issue_type_id" class="at-fld" value="<?= $e($opt('default_issue_type_id')) ?>"></div>
                <div><label class="at-lbl">Default Subtype ID</label>
                    <input type="number" name="o_default_sub_issue_type_id" class="at-fld" value="<?= $e($opt('default_sub_issue_type_id')) ?>"></div>
            </div>
        </section>

        <section class="at-box">
            <div class="at-box-h">
                <span class="at-ico" style="background:#b8860b"><i class="icon-time"></i></span>
                <div><h2>Time Entry Defaults</h2>
                    <div class="at-box-sub">Fallbacks for billable time entries when a tech's email has no ConnectWise resource match</div></div>
            </div>
            <div class="at-grid">
                <div><label class="at-lbl">Default Work Type ID</label>
                    <input type="number" name="o_default_work_type_id" class="at-fld" value="<?= $e($opt('default_work_type_id')) ?>"></div>
                <div><label class="at-lbl">Default Member ID</label>
                    <input type="number" name="o_default_resource_id" class="at-fld" value="<?= $e($opt('default_resource_id')) ?>"></div>
                <div><label class="at-lbl">Default Work Role ID</label>
                    <input type="number" name="o_default_role_id" class="at-fld" value="<?= $e($opt('default_role_id')) ?>"></div>
            </div>
        </section>

        <section class="at-box">
            <div class="at-box-h">
                <span class="at-ico" style="background:#c0392b"><i class="icon-ok"></i></span>
                <div><h2>Ticket Closure</h2>
                    <div class="at-box-sub">What "finished" means in this client's ConnectWise when a ticket is closed here</div></div>
            </div>
            <div class="at-grid">
                <div><label class="at-lbl">ConnectWise "Closed" status ID <span class="at-muted">(the board status that means done)</span></label>
                    <input type="number" name="o_complete_status" class="at-fld" value="<?= $e($opt('complete_status')) ?>"></div>
                <div style="display:flex;flex-direction:column;justify-content:flex-end;gap:6px">
                    <label><input type="checkbox" name="o_require_time_before_close" <?= $chk('require_time_before_close', true) ?>> Require a time entry before completing</label>
                    <label><input type="checkbox" name="o_close_osticket_on_complete" <?= $chk('close_osticket_on_complete', true) ?>> Allow closing the osTicket ticket on complete</label>
                </div>
            </div>
        </section>

        <div class="at-savebar">
            <button type="submit" name="action" value="save_client" class="at-btn"><i class="icon-save"></i> <?= $editing ? 'Save Changes' : 'Register Client' ?></button>
            <a class="at-btn at-btn-ghost" href="connectwise.php?view=clients">Cancel</a>
            <span class="at-muted" style="font-size:12px;margin-left:auto"><?= $editing ? 'Changes apply from the next sync cycle.' : 'After registering: Test Connection, import a small date range, verify, then enable auto-import.' ?></span>
        </div>
    </form>

<?php endif; ?>

    <footer class="at-footer">ConnectWise Integration &middot; Clients</footer>
</div>
<script><?= $atJs ?></script>
