<?php
/**
 * ConnectWise Integration — automated self-test harness (CLI).
 *
 * Layered like a professional QA suite:
 *   L1 Environment   — runtime, schema, migrations (read-only)
 *   L2 Configuration — per-client credentials, API, picklists, mappings (read-only)
 *   L3 Data integrity — orphans, duplicates, queue health (read-only)
 *   L4 Live round-trip — OPT-IN (--live=<instanceId>): creates a ticket in that
 *      tenant, imports, replies, logs time, closes, verifies, and labels all
 *      artifacts "SELFTEST" for easy cleanup.
 *
 * Usage:
 *   php selftest.php                 # L1-L3 (safe, read-only)
 *   php selftest.php --live=2       # + L4 against instance 2
 *
 * Exit code 0 = all pass; 1 = failures found.
 *
 * @package ConnectWise Integration
 */

if (PHP_SAPI !== 'cli') { die("CLI only.\n"); }

$root = realpath(__DIR__ . '/../../../');
require_once $root . '/main.inc.php';
require_once __DIR__ . '/bootstrap.php';

/* ----- micro test-runner -------------------------------------------------- */
$RESULTS = array('pass' => 0, 'fail' => 0, 'skip' => 0);
function t_section(string $s): void { echo "\n== $s ==\n"; }
function t_pass(string $m): void { global $RESULTS; $RESULTS['pass']++; echo "  [PASS] $m\n"; }
function t_fail(string $m): void { global $RESULTS; $RESULTS['fail']++; echo "  [FAIL] $m\n"; }
function t_skip(string $m): void { global $RESULTS; $RESULTS['skip']++; echo "  [SKIP] $m\n"; }
function t_check(bool $ok, string $m, string $why = ''): void { $ok ? t_pass($m) : t_fail($m . ($why !== '' ? " — $why" : '')); }

$liveId = 0;
foreach ($argv as $a) { if (preg_match('/^--live=(\d+)$/', $a, $m)) { $liveId = (int) $m[1]; } }

/* ----- L1 Environment ------------------------------------------------------ */
t_section('L1 Environment');
t_check(version_compare(PHP_VERSION, '8.0', '>='), 'PHP >= 8.0 (' . PHP_VERSION . ')');
t_check(function_exists('curl_init'), 'cURL extension present');
$plugin = null;
foreach (PluginManager::allInstalled() as $p) { if ($p instanceof \ConnectWise\ConnectWisePlugin) { $plugin = $p; break; } }
t_check($plugin !== null, 'Plugin installed & bootstrapped');
if (!$plugin) { goto summary; }
$gcfg = $plugin->globalConfig();
t_check((string) $gcfg->get('schema_version') !== '', 'Config namespace resolved (schema_version=' . $gcfg->get('schema_version') . ')');
foreach (array('connectwise_instance', 'connectwise_ticket_map', 'connectwise_sync_queue', 'connectwise_note_map', 'connectwise_time_entry',
               'connectwise_company_map', 'connectwise_contact_map', 'connectwise_member_map', 'connectwise_webhook_log') as $tbl) {
    t_check(\ConnectWise\Installer::tableExists($tbl), "Table $tbl exists");
}
$res = db_query('SELECT COUNT(*) c FROM ' . TABLE_PREFIX . 'connectwise_migrations');
$mig = ($res && ($r = db_fetch_array($res))) ? (int) $r['c'] : 0;
t_check($mig >= 8, "Migrations applied ($mig >= 8)");

/* ----- L2 Configuration (per client) --------------------------------------- */
$repo = new \ConnectWise\InstanceRepository();
$instances = $repo->all();
t_section('L2 Configuration — ' . count($instances) . ' client(s)');
t_check(count($instances) > 0, 'At least one client registered');
foreach ($instances as $inst) {
    $tag = '[' . $inst->code() . ']';
    if (!$inst->enabled()) { t_skip("$tag disabled — connection checks skipped"); continue; }
    $creds = $inst->credentials();
    t_check($creds['secret'] !== '', "$tag secret decrypts (not empty)");
    $c = $plugin->getContainerFor($inst->id());
    $conn = $c->api()->testConnection();
    t_check((bool) $conn['ok'], "$tag live API connection", (string) $conn['message']);
    if (!$conn['ok']) { continue; }
    t_check((bool) $c->api()->webBase(), "$tag web base resolved (" . $c->api()->webBase() . ')');
    $c->picklists()->ensureFresh();
    t_check(count($c->picklists()->options('status', 'Tickets')) > 0, "$tag status picklist cached");
    t_check(count($c->picklists()->options('billingCodeID')) > 0, "$tag work types cached");
    // Defaults sanity.
    $s = $c->settings();
    t_check($s->defaults()['company_id'] > 0, "$tag default company set");
    t_check((int) $s->defaultWorkTypeId() > 0, "$tag default work type set");
    $rid = (int) $s->defaultResourceId(); $rol = (int) $s->defaultRoleId();
    if ($rid && $rol) {
        try {
            $memberOk = false;
            foreach ($c->api()->getResources() as $m0) {
                if ((int) ($m0['id'] ?? 0) === $rid) { $memberOk = true; break; }
            }
            $roleOk = false;
            foreach ($c->api()->getRoles() as $r0) {
                if ((int) ($r0['id'] ?? 0) === $rol) { $roleOk = true; break; }
            }
            t_check($memberOk && $roleOk, "$tag default member + work role exist in tenant",
                (!$memberOk ? "member #$rid missing " : '') . (!$roleOk ? "work role #$rol missing" : ''));
        } catch (\Throwable $e) { t_fail("$tag member/role check errored — " . $e->getMessage()); }
    } else {
        t_fail("$tag default member/work role missing (time entries may fail)");
    }
    // Status map lines resolve to real osTicket statuses.
    $bad = array();
    foreach (array_keys($s->statusMap()) as $name) {
        $found = false;
        foreach (\TicketStatus::objects() as $st) {
            if (mb_strtolower(trim((string) $st->getName())) === $name) { $found = true; break; }
        }
        if (!$found) { $bad[] = $name; }
    }
    t_check(!$bad, "$tag status map names all exist in osTicket", implode(', ', $bad));
    // Department exists when set.
    $dep = $inst->departmentId();
    if ($dep > 0) {
        $rd = db_query('SELECT 1 FROM ' . TABLE_PREFIX . 'department WHERE id=' . $dep);
        t_check((bool) ($rd && db_num_rows($rd)), "$tag fallback department #$dep exists");
    } else { t_skip("$tag no fallback department (system default will be used)"); }
}

/* ----- L3 Data integrity ---------------------------------------------------- */
t_section('L3 Data integrity');
$q = function (string $sql): int {
    $r = db_query($sql); $x = $r ? db_fetch_array($r) : null; return (int) ($x['c'] ?? 0);
};
$orphans = $q('SELECT COUNT(*) c FROM ' . TABLE_PREFIX . 'connectwise_ticket_map m LEFT JOIN '
    . TABLE_PREFIX . 'ticket t ON t.ticket_id=m.osticket_ticket_id WHERE t.ticket_id IS NULL');
t_check($orphans === 0, "No orphan mappings (map -> missing osTicket ticket)", "$orphans found");
$dupes = $q('SELECT COUNT(*) c FROM (SELECT instance_id, connectwise_ticket_id FROM ' . TABLE_PREFIX
    . 'connectwise_ticket_map WHERE connectwise_ticket_id IS NOT NULL GROUP BY instance_id, connectwise_ticket_id HAVING COUNT(*)>1) d');
t_check($dupes === 0, 'No duplicate CW-ticket mappings within a tenant', "$dupes found");
$unknownInst = $q('SELECT COUNT(*) c FROM ' . TABLE_PREFIX . 'connectwise_ticket_map m LEFT JOIN '
    . TABLE_PREFIX . 'connectwise_instance i ON i.id=m.instance_id WHERE i.id IS NULL');
t_check($unknownInst === 0, 'Every mapping points to a registered client', "$unknownInst stray");
$dead = $q("SELECT COUNT(*) c FROM " . TABLE_PREFIX . "connectwise_sync_queue WHERE status='dead'");
$dead === 0 ? t_pass('Queue has no dead jobs') : t_fail("Queue has $dead dead job(s) — inspect dashboard Failed & Dead");
$stuck = $q("SELECT COUNT(*) c FROM " . TABLE_PREFIX . "connectwise_sync_queue WHERE status='processing' AND updated < (NOW() - INTERVAL 30 MINUTE)");
t_check($stuck === 0, 'No jobs stuck in processing >30min', "$stuck stuck");
$teFail = $q("SELECT COUNT(*) c FROM " . TABLE_PREFIX . "connectwise_time_entry WHERE status='failed'");
$teFail === 0 ? t_pass('No failed time entries') : t_fail("$teFail failed time entrie(s) — see history popup / logs");

/* ----- L4 Live round-trip (opt-in) ------------------------------------------ */
if ($liveId > 0) {
    t_section("L4 Live round-trip — instance #$liveId (creates SELFTEST artifacts)");
    $inst = $repo->find($liveId);
    if (!$inst || !$inst->enabled()) { t_fail('instance missing/disabled'); goto summary; }
    $c = $plugin->getContainerFor($liveId);
    $api = $c->api(); $s = $c->settings();
    try {
        $fields = array(
            'companyID' => (int) $s->defaults()['company_id'],
            'title' => 'SELFTEST ' . date('His') . ' - automated round-trip (safe to delete)',
            'description' => 'Created by the integration self-test.',
        );
        // Board/status/priority ids are tenant-specific in ConnectWise — use
        // the configured defaults; absent ones fall back to board defaults.
        foreach (array('queue_id' => 'queueID', 'status' => 'status', 'priority' => 'priority',
                       'issue_type' => 'issueType') as $k => $f) {
            if (!empty($s->defaults()[$k])) { $fields[$f] = $s->defaults()[$k]; }
        }
        $atId = $api->createTicket($fields);
        t_pass("created ConnectWise ticket #$atId");
        $imp = $c->sync()->importSingle($atId);
        t_check(!empty($imp['ok']) && !empty($imp['osticket_id']), 'imported into osTicket', (string) ($imp['message'] ?? ''));
        $ostId = (int) $imp['osticket_id'];
        $t = Ticket::lookup($ostId);
        $dep = $inst->departmentId();
        if ($dep > 0) { t_check((int) $t->getDeptId() === $dep, 'landed in registered department'); }
        // Reply -> AT note.
        $errors = array();
        $entry = $t->postReply(array('response' => 'SELFTEST reply body', 'title' => 'SELFTEST'), $errors, false, false);
        t_check((bool) $entry, 'osTicket reply posted');
        if ($entry) { $c->sync()->onOsticketThreadEntry($entry); }
        $c->scheduler()->processQueue(10);
        $found = false;
        foreach ($api->getTicketNotesSince($atId, gmdate('Y-m-d\TH:i:s\Z', time() - 3600)) as $n) {
            if (strpos((string) ($n['description'] ?? ''), 'SELFTEST reply body') !== false) { $found = true; break; }
        }
        t_check($found, 'reply arrived in ConnectWise as note');
        // Close -> Complete + resolution.
        $closed = null;
        foreach (TicketStatus::objects()->filter(array('state' => 'closed'))->limit(1) as $st) { $closed = $st; break; }
        $t->setStatus($closed);
        $c->sync()->onOsticketTicketUpdated(Ticket::lookup($ostId), array('dirty' => array('status_id' => true)));
        $c->scheduler()->processQueue(10);
        $at = $api->getTicket($atId);
        t_check((int) ($at['status'] ?? 0) === (int) $s->completeStatus(), 'ConnectWise reached the closed status');
        // ConnectWise stores the resolution as a resolutionFlag note.
        $resNotes = $api->request('GET', "service/tickets/$atId/notes?" . http_build_query(array(
            'conditions' => 'resolutionFlag = true', 'pageSize' => 1,
        )));
        t_check(!empty($resNotes[0]['id']), 'resolution note auto-filled');
        echo "  [INFO] artifacts: CW #$atId / osTicket #$ostId (titled SELFTEST — delete at will)\n";
    } catch (\Throwable $e) {
        t_fail('round-trip aborted — ' . $e->getMessage());
    }
} else {
    t_section('L4 Live round-trip');
    t_skip('not requested (run with --live=<instanceId> to enable)');
}

summary:
echo "\n================ SUMMARY ================\n";
printf("PASS: %d   FAIL: %d   SKIP: %d\n", $RESULTS['pass'], $RESULTS['fail'], $RESULTS['skip']);
echo $RESULTS['fail'] === 0 ? "RESULT: ALL CHECKS PASSED\n" : "RESULT: FAILURES FOUND — see [FAIL] lines above\n";
exit($RESULTS['fail'] === 0 ? 0 : 1);
