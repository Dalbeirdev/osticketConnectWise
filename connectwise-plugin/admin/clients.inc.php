<?php
/**
 * ConnectWise Integration — Clients (Instance Manager) controller.
 *
 * Included from admin/dashboard.inc.php when ?view=clients. At that point the
 * caller has already verified: staff context, admin privileges, and built
 * $facade (plugin service container). This file handles the client register:
 * list / add / edit / enable-disable / delete / per-client connection test.
 *
 * Variables available from the caller: $thisstaff, $ost, $facade.
 *
 * @package ConnectWise Integration
 */

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

$repo = $facade->container()->instanceRepository();

$notice = null;
$error  = null;

/**
 * Ensure every osTicket status named in a client's Status Map exists, so the
 * single-dropdown translation works out of the box. Only map-referenced names
 * are created (never the tenant's whole status list). Names mapping to the
 * client's "Complete" value are created with state=closed, others state=open.
 *
 * @param string $mapRaw         Status Map lines "Name=AT id".
 * @param int    $completeStatus The client's ConnectWise Complete value.
 * @return string[] Names created.
 */
$ensureStatuses = static function (string $mapRaw, int $completeStatus): array {
    if (!class_exists('TicketStatus')) {
        return array();
    }
    $existing = array();
    foreach (\TicketStatus::objects() as $s) {
        if (method_exists($s, 'getName')) {
            $existing[mb_strtolower(trim((string) $s->getName()))] = true;
        }
    }
    $created = array();
    foreach (preg_split('/\r\n|\r|\n/', $mapRaw) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '=') === false) {
            continue;
        }
        list($name, $atId) = array_map('trim', explode('=', $line, 2));
        if ($name === '' || !is_numeric($atId) || isset($existing[mb_strtolower($name)])) {
            continue;
        }
        $state = ((int) $atId === $completeStatus) ? 'closed' : 'open';
        $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'ost_';
        $n = db_input(mb_substr($name, 0, 60));
        $st = db_input($state);
        $props = db_input(json_encode(array('description' => 'Created by ConnectWise integration')));
        if (db_query("INSERT INTO `{$prefix}ticket_status` (name, state, mode, flags, sort, properties, created, updated) "
            . "VALUES ($n, $st, 1, 0, 0, $props, NOW(), NOW())", false)) {
            $created[] = $name;
            $existing[mb_strtolower($name)] = true;
        }
    }
    return $created;
};

/**
 * Per-client option keys stored in config_json, with their input type.
 * bool = checkbox, int = numeric, csv = comma-separated id list, str = text.
 */
$optionTypes = array(
    'two_way_sync'               => 'bool',
    'auto_import_enabled'        => 'bool',
    'inbound_notes_enabled'      => 'bool',
    'default_company_id'         => 'int',
    'default_queue_id'           => 'int',
    'default_priority'           => 'int',
    'default_status'             => 'int',
    'default_ticket_type'        => 'int',
    'default_issue_type_id'      => 'int',
    'default_sub_issue_type_id'  => 'int',
    'import_include_open'        => 'bool',
    'import_include_closed'      => 'bool',
    'import_status_ids'          => 'csv',
    'import_company_ids'         => 'csv',
    'import_queue_ids'           => 'csv',
    'import_resource_ids'        => 'csv',
    'import_since_days'          => 'int',
    'default_work_type_id'       => 'int',
    'default_resource_id'        => 'int',
    'default_role_id'            => 'int',
    'complete_status'            => 'int',
    'require_time_before_close'  => 'bool',
    'close_osticket_on_complete' => 'bool',
    'status_map'                 => 'str',
    'priority_map'               => 'str',
    'sync_attachments'           => 'bool',
    'import_system_notes'        => 'bool',
    'dept_map'                   => 'deptmap',
);

/**
 * Collect + normalise the per-client options from POST into an array
 * suitable for config_json.
 *
 * @param array<string,string> $types Option key => type map.
 * @return array<string,mixed>
 */
$collectOptions = static function (array $types): array {
    $out = array();
    foreach ($types as $key => $type) {
        switch ($type) {
            case 'bool':
                $out[$key] = !empty($_POST['o_' . $key]) ? 1 : 0;
                break;
            case 'int':
                $v = trim((string) ($_POST['o_' . $key] ?? ''));
                $out[$key] = ($v === '' ? null : (int) $v);
                break;
            case 'csv':
                // Keep as entered (validated later by the engine); strip spaces.
                $out[$key] = preg_replace('/\s+/', '', (string) ($_POST['o_' . $key] ?? ''));
                break;
            case 'deptmap':
                // Queue->department routing rows (parallel arrays from the
                // dynamic form) -> "queueId=deptId" lines. Incomplete rows
                // are dropped silently.
                $qs = (array) ($_POST['o_' . $key . '_queue'] ?? array());
                $ds = (array) ($_POST['o_' . $key . '_dept'] ?? array());
                $lines = array();
                foreach ($qs as $i0 => $q0) {
                    $q0 = trim((string) $q0);
                    $d0 = trim((string) ($ds[$i0] ?? ''));
                    if ($q0 !== '' && ctype_digit($q0) && $d0 !== '' && ctype_digit($d0)) {
                        $lines[(int) $q0] = (int) $q0 . '=' . (int) $d0; // last rule per queue wins
                    }
                }
                $out[$key] = implode("\n", $lines);
                break;
            default:
                $out[$key] = trim((string) ($_POST['o_' . $key] ?? ''));
        }
    }
    return $out;
};

/**
 * Field parity on registration: create osTicket Time Types matching the
 * client's ConnectWise WORK TYPES (billing codes, useType=1) by name, so time
 * entries map 1-to-1 in both directions with zero manual configuration.
 *
 * @param int $instanceId Saved client instance id.
 * @return string[] Names created.
 */
$ensureTimeTypes = static function (int $instanceId) use ($facade): array {
    $created = array();
    try {
        $pluginRef = $facade->container()->plugin();
        $api = $pluginRef->getContainerFor($instanceId)->api();
        $listId = 0;
        $r = db_query("SELECT id FROM " . TABLE_PREFIX . "list WHERE type='time-type' LIMIT 1");
        if ($r && ($x = db_fetch_array($r))) { $listId = (int) $x['id']; }
        if (!$listId) { return array(); }
        $existing = array();
        $r = db_query('SELECT value FROM ' . TABLE_PREFIX . 'list_items WHERE list_id=' . $listId);
        while ($r && ($x = db_fetch_array($r))) { $existing[mb_strtolower(trim($x['value']))] = true; }
        foreach ($api->getBillingCodes() as $b) {
            // Only true work types; skip expense/material codes when typed.
            if (isset($b['useType']) && (int) $b['useType'] !== 1) { continue; }
            $name = trim((string) ($b['name'] ?? ''));
            if ($name === '' || isset($existing[mb_strtolower($name)])) { continue; }
            if (db_query('INSERT INTO ' . TABLE_PREFIX . 'list_items (list_id, status, value, sort) VALUES ('
                . $listId . ', 1, ' . db_input(mb_substr($name, 0, 120)) . ', 1)', false)) {
                $created[] = $name;
                $existing[mb_strtolower($name)] = true;
            }
        }
    } catch (\Throwable $e) {
        // best-effort; connection may not be testable yet
    }
    return $created;
};

/**
 * Auto-map ALL PSA-related fields on save: match osTicket Work Types,
 * Priorities and Statuses to the client's live ConnectWise picklists by name
 * and FILL ONLY THE GAPS (never overwrite a value the admin typed). Anything
 * that can't be matched is returned so the admin can map it manually.
 *
 * @param int $instanceId Saved client instance id.
 * @return array{added:int,unmapped:string[]}
 */
$autoMapFields = static function (int $instanceId) use ($facade, $repo): array {
    $added = 0; $unmapped = array();
    try {
        $inst = $repo->find($instanceId);
        if (!$inst) { return array('added' => 0, 'unmapped' => array()); }
        $api  = $facade->container()->plugin()->getContainerFor($instanceId)->api();
        $opts = $inst->configAll();
        $ci   = static function ($s) { return mb_strtolower(trim((string) $s)); };

        // Parse "Label=id" lines into [lc-label => "Label=id"], preserving the
        // admin's exact text so their manual mappings always win.
        $parse = static function ($raw) use ($ci) {
            $out = array();
            foreach (preg_split('/\r\n|\r|\n/', (string) $raw) as $ln) {
                if (strpos($ln, '=') === false) { continue; }
                list($l, $v) = array_map('trim', explode('=', $ln, 2));
                if ($l !== '') { $out[$ci($l)] = $l . '=' . $v; }
            }
            return $out;
        };

        // One field-info call feeds both status + priority (getFieldInfo walks
        // boards, so avoid calling it twice).
        $cwStatus = array(); $cwPrio = array();
        foreach ($api->getFieldInfo('Tickets') as $f) {
            if (($f['name'] ?? '') === 'status') {
                foreach ($f['picklistValues'] as $v) { $cwStatus[$ci($v['label'])] = (int) $v['value']; }
            } elseif (($f['name'] ?? '') === 'priority') {
                foreach ($f['picklistValues'] as $v) { $cwPrio[$ci($v['label'])] = (int) $v['value']; }
            }
        }

        /* ---- Work Types: osTicket Time Type -> CW work type (exact name) ---- */
        $cwWt = array();
        foreach ($api->getBillingCodes() as $w) { $cwWt[$ci($w['name'])] = (int) $w['id']; }
        $wtMap = $parse($opts['timetype_map'] ?? '');
        $wtBad = array();
        $rw = db_query('SELECT li.value FROM ' . TABLE_PREFIX . 'list_items li JOIN '
            . TABLE_PREFIX . "list l ON l.id=li.list_id WHERE l.type='time-type' AND li.status=1", false);
        while ($rw && ($x = db_fetch_array($rw))) {
            $label = (string) $x['value']; $k = $ci($label);
            if ($label === '' || isset($wtMap[$k])) { continue; }
            if (isset($cwWt[$k])) { $wtMap[$k] = $label . '=' . $cwWt[$k]; $added++; }
            else { $wtBad[] = $label; }
        }
        $opts['timetype_map'] = implode("\n", array_values($wtMap));
        if ($wtBad) { $unmapped[] = 'Time Types → default work type: ' . implode(', ', $wtBad); }

        /* ---- Priorities: exact name, else CW name contains the osTicket word ---- */
        $prMap = $parse($opts['priority_map'] ?? '');
        $prBad = array();
        $rp = db_query('SELECT priority_desc FROM ' . TABLE_PREFIX . 'ticket_priority', false);
        while ($rp && ($x = db_fetch_array($rp))) {
            $label = (string) $x['priority_desc']; $k = $ci($label);
            if ($label === '' || isset($prMap[$k])) { continue; }
            $hit = $cwPrio[$k] ?? null;
            if ($hit === null) {
                foreach ($cwPrio as $cn => $cid) { if (strpos($cn, $k) !== false) { $hit = $cid; break; } }
            }
            if ($hit !== null) { $prMap[$k] = $label . '=' . $hit; $added++; }
            else { $prBad[] = $label; }
        }
        $opts['priority_map'] = implode("\n", array_values($prMap));
        if ($prBad) { $unmapped[] = 'Priorities (map manually): ' . implode(', ', $prBad); }

        /* ---- Statuses: exact name match only (names are board-specific) ---- */
        $stMap = $parse($opts['status_map'] ?? '');
        $stBad = array();
        if (class_exists('TicketStatus')) {
            foreach (\TicketStatus::objects() as $st) {
                $label = method_exists($st, 'getName') ? (string) $st->getName() : '';
                $k = $ci($label);
                if ($label === '' || isset($stMap[$k])) { continue; }
                if (isset($cwStatus[$k])) { $stMap[$k] = $label . '=' . $cwStatus[$k]; $added++; }
                else { $stBad[] = $label; }
            }
        }
        $opts['status_map'] = implode("\n", array_values($stMap));
        if ($stBad) { $unmapped[] = 'Statuses (Status Map / open-closed fallback): ' . implode(', ', $stBad); }

        $repo->update($instanceId, array('config_json' => $opts));
    } catch (\Throwable $e) {
        // Auto-map is best-effort; a bad/untestable connection must not block save.
        $unmapped[] = 'auto-map skipped (' . $e->getMessage() . ')';
    }
    return array('added' => $added, 'unmapped' => $unmapped);
};

/* ---------------------------------------------------------------------------
 * POST actions (CSRF-checked).
 * ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$ost->checkCSRFToken()) {
        $error = 'Invalid CSRF token. Please reload and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $id     = (int) ($_POST['client_id'] ?? 0);
        try {
            switch ($action) {

                case 'save_client':
                    $fields = array(
                        'name'                 => trim((string) ($_POST['c_name'] ?? '')),
                        'code'                 => trim((string) ($_POST['c_code'] ?? '')),
                        'api_username'         => trim((string) ($_POST['c_api_username'] ?? '')),
                        'api_secret'           => (string) ($_POST['c_api_secret'] ?? ''),
                        'api_integration_code' => trim((string) ($_POST['c_api_integration_code'] ?? '')),
                        'zone_url'             => trim((string) ($_POST['c_zone_url'] ?? '')),
                        'department_id'        => (int) ($_POST['c_department_id'] ?? 0),
                        'enabled'              => !empty($_POST['c_enabled']) ? 1 : 0,
                        'config_json'          => $collectOptions($optionTypes),
                    );

                    // Server-side validation.
                    if ($fields['name'] === '' || $fields['code'] === '') {
                        $error = 'Client name and code are required.';
                    } elseif ($fields['api_username'] === '' || strpos($fields['api_username'], '+') === false) {
                        $error = 'Company ID + Public Key is required, joined with "+" (e.g. mycompany+AbCdEfGh123).';
                    } elseif ($fields['api_integration_code'] === '') {
                        $error = 'API Client ID is required (register at developer.connectwise.com).';
                    } elseif ($fields['zone_url'] === '' || !filter_var($fields['zone_url'], FILTER_VALIDATE_URL)) {
                        $error = 'A valid Site URL is required (e.g. https://na.myconnectwise.net).';
                    } elseif ($id === 0 && $fields['api_secret'] === '') {
                        $error = 'Private key is required for a new client.';
                    } else {
                        // Code must be unique (other than this row).
                        $existing = $repo->findByCode($fields['code']);
                        if ($existing && $existing->id() !== $id) {
                            $error = 'Client code "' . htmlspecialchars($fields['code'])
                                . '" is already used by ' . htmlspecialchars($existing->name()) . '.';
                        } elseif ($id > 0) {
                            $repo->update($id, $fields);
                            $notice = 'Client updated.';
                        } else {
                            $newId = $repo->create($fields);
                            $notice = $newId
                                ? 'Client registered. Use Test Connection to verify, then import a small date range first.'
                                : 'Could not create the client (see logs).';
                        }
                        // Auto-create any osTicket statuses the Status Map references.
                        if (!$error) {
                            $made = $ensureStatuses(
                                (string) ($fields['config_json']['status_map'] ?? ''),
                                (int) ($fields['config_json']['complete_status'] ?? 5)
                            );
                            if ($made) {
                                $notice .= ' Created osTicket status(es): ' . htmlspecialchars(implode(', ', $made)) . '.';
                            }
                            // Mirror the client's ConnectWise work types as Time Types.
                            $savedId = $id > 0 ? $id : (int) ($newId ?? 0);
                            $tt = $ensureTimeTypes($savedId);
                            if ($tt) {
                                $notice .= ' Created Time Type(s): ' . htmlspecialchars(implode(', ', $tt)) . '.';
                            }
                            // Auto-map ALL PSA fields (work type / priority / status)
                            // against the live ConnectWise picklists — fills gaps only.
                            $am = $autoMapFields($savedId);
                            if (!empty($am['added'])) {
                                $notice .= ' Auto-mapped ' . (int) $am['added'] . ' field value(s) to ConnectWise.';
                            }
                            if (!empty($am['unmapped'])) {
                                $notice .= ' &#9888; Review unmapped — ' . htmlspecialchars(implode('; ', $am['unmapped'])) . '.';
                            }
                        }
                    }
                    break;

                case 'toggle_client':
                    if ($id && ($inst = $repo->find($id))) {
                        $repo->setEnabled($id, !$inst->enabled());
                        $notice = $inst->enabled()
                            ? 'Client "' . htmlspecialchars($inst->name()) . '" disabled (sync paused, data kept).'
                            : 'Client "' . htmlspecialchars($inst->name()) . '" enabled.';
                    }
                    break;

                case 'delete_client':
                    if ($id && ($inst = $repo->find($id))) {
                        if ($repo->delete($id)) {
                            $notice = 'Client "' . htmlspecialchars($inst->name()) . '" deleted.';
                        } else {
                            $error = 'This client has synced tickets and cannot be deleted — disable it instead.';
                        }
                    }
                    break;

                case 'refresh_ref':
                    // Force-refresh the picklist cache behind the ID reference.
                    if ($id && $repo->find($id)) {
                        $facade->container()->plugin()->getContainerFor($id)->picklists()->ensureFresh(true);
                        $notice = 'ID reference refreshed from ConnectWise.';
                    }
                    break;

                case 'sync_client':
                    // Per-client "Sync Now": incremental run for this tenant only.
                    if ($id && ($inst = $repo->find($id))) {
                        $pluginRef = $facade->container()->plugin();
                        $c = $pluginRef->getContainerFor($id);
                        $r = $c->scheduler()->runIncremental();
                        $repo->touchSync($id, ((int) $r['queue']['failed']) === 0);
                        $notice = sprintf('Sync for %s: processed %d, failed %d, pulled %d.',
                            htmlspecialchars($inst->code()),
                            $r['queue']['processed'], $r['queue']['failed'], $r['pulled']);
                    }
                    break;

                case 'test_client':
                    // Test with the posted credentials; blank secret on an
                    // existing client falls back to the stored one.
                    $creds = array(
                        'username'         => trim((string) ($_POST['c_api_username'] ?? '')),
                        'secret'           => (string) ($_POST['c_api_secret'] ?? ''),
                        'integration_code' => trim((string) ($_POST['c_api_integration_code'] ?? '')),
                        'zone_url'         => trim((string) ($_POST['c_zone_url'] ?? '')),
                    );
                    if ($creds['secret'] === '' && $id > 0 && ($inst = $repo->find($id))) {
                        $stored = $inst->credentials();
                        $creds['secret'] = $stored['secret'];
                    }
                    $api = new \ConnectWise\ConnectWiseApi($creds);
                    $result = $api->testConnection();
                    if ($result['ok']) {
                        $notice = 'Connection successful. Site: ' . htmlspecialchars($result['zone_url']);
                        if ($id > 0) {
                            $repo->touchSync($id, true);
                            $web = $api->webBase();
                            if ($web) {
                                $repo->setWebBase($id, $web);
                            }
                        }
                    } else {
                        $error = 'Connection failed: ' . htmlspecialchars($result['message']);
                        if ($id > 0) {
                            $repo->touchSync($id, false);
                        }
                    }
                    break;

                default:
                    $error = 'Unknown action.';
            }
        } catch (\Throwable $e) {
            $error = 'Action failed: ' . htmlspecialchars($e->getMessage());
        }
    }
}

/* ---------------------------------------------------------------------------
 * Gather view data + render inside the osTicket staff chrome.
 * ------------------------------------------------------------------------- */
$mode    = (string) ($_GET['mode'] ?? 'list');           // list | add | edit | tickets
$editing = null;                                          // Instance|null for the form
if ($mode === 'edit') {
    $editing = $repo->find((int) ($_GET['id'] ?? 0));
    if (!$editing) {
        $mode = 'list';
        $error = $error ?: 'Client not found.';
    }
}

// "Tickets" sub-view: the client's mapped tickets, newest activity first, with
// admin filters (company/organization, board via department routing, open/closed
// state, osTicket status, ConnectWise status).
$clientTickets = array();
$ticketFilters = array('state' => '', 'status' => 0, 'dept' => 0, 'org' => 0, 'cw' => 0);
$filterOptions = array('statuses' => array(), 'depts' => array(), 'orgs' => array(), 'cw' => array());
if ($mode === 'tickets') {
    $editing = $repo->find((int) ($_GET['id'] ?? 0));
    if (!$editing) {
        $mode = 'list';
        $error = $error ?: 'Client not found.';
    } else {
        $prefix = \ConnectWise\Installer::prefix();
        $iid    = (int) $editing->id();

        $ticketFilters = array(
            'state'  => in_array($_GET['f_state'] ?? '', array('open', 'closed'), true) ? $_GET['f_state'] : '',
            'status' => (int) ($_GET['f_status'] ?? 0),
            'dept'   => (int) ($_GET['f_dept'] ?? 0),
            'org'    => (int) ($_GET['f_org'] ?? 0),
            'cw'     => (int) ($_GET['f_cw'] ?? 0),
        );

        // One join instead of a per-row Ticket::lookup; every filter is applied
        // in SQL so the LIMIT stays meaningful.
        $joins =
            "FROM `{$prefix}connectwise_ticket_map` m "
            . "JOIN `{$prefix}ticket` t ON t.ticket_id = m.osticket_ticket_id "
            . "LEFT JOIN `{$prefix}ticket_status` s ON s.id = t.status_id "
            . "LEFT JOIN `{$prefix}department` d ON d.id = t.dept_id "
            . "LEFT JOIN `{$prefix}user` u ON u.id = t.user_id "
            . "LEFT JOIN `{$prefix}organization` o ON o.id = u.org_id "
            . "LEFT JOIN `{$prefix}ticket__cdata` c ON c.ticket_id = t.ticket_id ";
        $where = "WHERE m.instance_id=$iid";
        if ($ticketFilters['state'] !== '') { $where .= " AND s.state='" . $ticketFilters['state'] . "'"; }
        if ($ticketFilters['status'])       { $where .= ' AND t.status_id=' . $ticketFilters['status']; }
        if ($ticketFilters['dept'])         { $where .= ' AND t.dept_id=' . $ticketFilters['dept']; }
        if ($ticketFilters['org'])          { $where .= ' AND u.org_id=' . $ticketFilters['org']; }
        if ($ticketFilters['cw'])           { $where .= ' AND m.connectwise_status=' . $ticketFilters['cw']; }

        $res = db_query(
            'SELECT m.osticket_ticket_id, m.connectwise_ticket_number, m.connectwise_status, m.last_sync_time, '
            . 't.number, c.subject, s.name AS status_name, s.state, d.name AS dept_name, o.name AS org_name '
            . $joins . $where . ' ORDER BY m.updated DESC LIMIT 50', false);
        while ($res && ($row = db_fetch_array($res))) {
            $row['status'] = (string) ($row['status_name'] ?? '');
            $clientTickets[] = $row;
        }

        // Dropdown options: only values that actually occur in THIS client's
        // mapped tickets, so the filters never offer dead choices.
        $optQ = function (string $select, string $group) use ($joins, $iid) {
            $out = array();
            $r = db_query("SELECT DISTINCT $select $joins WHERE m.instance_id=$iid $group", false);
            while ($r && ($x = db_fetch_row($r))) {
                if ($x[0] !== null && $x[0] !== '') { $out[(string) $x[0]] = (string) ($x[1] ?? $x[0]); }
            }
            return $out;
        };
        $filterOptions['statuses'] = $optQ('t.status_id, s.name', 'ORDER BY s.name');
        $filterOptions['depts']    = $optQ('t.dept_id, d.name', 'ORDER BY d.name');
        $filterOptions['orgs']     = $optQ('u.org_id, o.name', 'AND u.org_id IS NOT NULL ORDER BY o.name');
        $filterOptions['cw']       = $optQ('m.connectwise_status, m.connectwise_status', 'ORDER BY 1');
        // ConnectWise status ids -> names via the picklist cache when available.
        try {
            $pk = $facade->container()->plugin()->getContainerFor($iid)->picklists();
            foreach ($filterOptions['cw'] as $idv => $lbl) {
                $name = $pk->labelByValue('status', (string) $idv);
                if ($name) { $filterOptions['cw'][$idv] = $name . " ($idv)"; }
            }
        } catch (\Throwable $e) { /* ids are fine */ }
        // Display prep: statuses on both sides embed the board name ("New (NOC)")
        // and the CW label carries the id — strip that noise for the table (the
        // Board column already says where it lives) and flag only DISAGREEMENT
        // between the two sides, which is the case an admin actually cares about.
        $base = function (string $s): string {
            do { $s2 = preg_replace('/\s*\([^()]*\)\s*$/', '', $s); $done = ($s2 === $s); $s = $s2; } while (!$done);
            return trim($s);
        };
        foreach ($clientTickets as &$ct) {
            $cid = (string) $ct['connectwise_status'];
            $cwLabel = isset($filterOptions['cw'][$cid]) ? $filterOptions['cw'][$cid] : $cid;
            $ct['status_disp'] = $base((string) $ct['status']);
            $cwBase            = $base($cwLabel);
            // Mismatch only when we KNOW the CW status name (numeric = unknown label),
            // and never for the closure-synonym family: osTicket "Resolved"/"Closed"
            // <-> ConnectWise "Completed" is the CONFIGURED closure mapping, not drift.
            $canon = function (string $s): string {
                $s = strtolower($s);
                return in_array($s, array('resolved', 'completed', 'closed'), true) ? '~closed' : $s;
            };
            $ct['cw_mismatch'] = (!ctype_digit($cwBase) && $ct['status_disp'] !== ''
                && $canon($ct['status_disp']) !== $canon($cwBase)) ? $cwBase : '';
            $ct['connectwise_status'] = $cwLabel;
            $ts = strtotime((string) $ct['last_sync_time']);
            $ct['last_sync_disp'] = $ts ? date('M j, H:i', $ts) : (string) $ct['last_sync_time'];
        }
        unset($ct);
    }
}

$clients    = $repo->all();
$csrfToken  = $ost->getCSRF()->getToken();

// Edit mode: live ID reference lists from THIS tenant (cache + API) so admins
// can fill the numeric filter/default fields without hunting inside ConnectWise.
$refQueues = $refResources = $refCompanies = array();
if ($mode === 'edit' && $editing) {
    try {
        $c = $facade->container()->plugin()->getContainerFor($editing->id());
        $c->picklists()->ensureFresh();
        $refQueues    = $c->picklists()->options('queueID', 'Tickets');
        $refResources = $c->picklists()->options('resourceID');
        foreach ($c->api()->listCompanies(30) as $co) {
            $refCompanies[] = array('value' => (string) $co['id'], 'label' => (string) ($co['companyName'] ?? $co['id']));
        }
    } catch (\Throwable $e) {
        // reference lists are a convenience; the form works without them
    }
}

// osTicket departments for the routing dropdown (id => name).
$departments = array();
if (class_exists('Dept') && method_exists('Dept', 'getDepartments')) {
    $departments = \Dept::getDepartments();
}

// Mapped-ticket counts per instance for the list cards.
$mappedCounts = array();
foreach ($clients as $c) {
    $mappedCounts[$c->id()] = $repo->mappedTickets($c->id());
}

if (method_exists($ost, 'setPageTitle')) {
    $ost->setPageTitle('ConnectWise Clients');
}
if (isset($nav) && is_object($nav) && method_exists($nav, 'setTabActive')) {
    $nav->setTabActive('dashboard');
}
if (defined('STAFFINC_DIR') && is_file(STAFFINC_DIR . 'header.inc.php')) {
    require STAFFINC_DIR . 'header.inc.php';
    require __DIR__ . '/../templates/clients.tmpl.php';
    require STAFFINC_DIR . 'footer.inc.php';
} else {
    require __DIR__ . '/../templates/clients.tmpl.php';
}
