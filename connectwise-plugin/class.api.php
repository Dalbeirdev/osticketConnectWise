<?php
/**
 * ConnectWise Integration — REST API client (native cURL, no dependencies).
 *
 * Implements the subset of the ConnectWise PSA (Manage) REST API needed for
 * ticket synchronization: service tickets, ticket notes, companies, contacts,
 * members, time entries, boards/statuses/priorities and documents.
 *
 * IMPORTANT — normalization contract: the synchronization engine, time-entry
 * service and picklist cache in this plugin consume Autotask-style entity
 * shapes (this codebase started life as the sibling Autotask plugin). This
 * client therefore NORMALIZES every ConnectWise entity into those shapes:
 *
 *  Ticket:    id, ticketNumber, title, description, status, priority,
 *             companyID, contactID, queueID (board), assignedResourceID,
 *             billingCodeID (work type), contractID (agreement), issueType
 *             (type), subIssueType (subtype), source, ticketType,
 *             createDate, dueDateTime, estimatedHours, lastActivityDate
 *  Contact:   id, firstName, lastName, emailAddress, companyID
 *  Company:   id, companyName
 *  Contract:  id, contractName            (ConnectWise agreement)
 *  Note:      id, title, description, publish (1=public, 2=internal),
 *             creatorResourceID, createdByContactID, createDateTime,
 *             lastActivityDate
 *  TimeEntry: id, hoursWorked, isNonBillable, startDateTime, endDateTime,
 *             dateWorked, createDateTime, summaryNotes, internalNotes,
 *             billingCodeID (work type), roleID (work role), resourceID
 *  Resource:  id, firstName, lastName, email, isActive   (ConnectWise member)
 *
 * Authentication (per client tenant):
 *  - username         "companyId+publicKey"  (ConnectWise Basic-auth username)
 *  - secret           private key
 *  - integration_code API clientId (https://developer.connectwise.com)
 *  - zone_url         site URL, e.g. https://na.myconnectwise.net
 *
 * @see https://developer.connectwise.com/Products/ConnectWise_PSA/REST
 * @package ConnectWise Integration
 */

namespace ConnectWise;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * Thin, defensive wrapper over the ConnectWise PSA REST endpoints.
 */
class ConnectWiseApi
{
    /** Fallback API codebase when /login/companyinfo discovery fails. */
    private const DEFAULT_CODEBASE = 'v4_6_release/';

    /** REST API version path segment appended to the codebase. */
    private const API_PATH = 'apis/3.0/';

    /** @var string ConnectWise login company id (before the '+'). */
    private $companyId = '';
    /** @var string Public key (after the '+'). */
    private $publicKey = '';
    /** @var string Private key. */
    private $privateKey;
    /** @var string API clientId header. */
    private $clientId;
    /** @var string Site base URL, e.g. https://na.myconnectwise.net */
    private $siteUrl;
    /** @var Logger|null */ private $logger;
    /** @var int Default request timeout (seconds). */
    private $timeout = 30;
    /** @var resource|\CurlHandle|null Reused cURL handle (keep-alive). */
    private $ch = null;
    /** @var string|null Resolved codebase, e.g. "v4_6_release/". */
    private $codebase = null;

    /**
     * @param array       $creds  username ("company+publicKey"), secret
     *                            (private key), integration_code (clientId),
     *                            zone_url (site URL).
     * @param Logger|null $logger
     */
    public function __construct(array $creds, ?Logger $logger = null)
    {
        $username = trim((string) ($creds['username'] ?? ''));
        if (strpos($username, '+') !== false) {
            list($this->companyId, $this->publicKey) = array_map('trim', explode('+', $username, 2));
        } else {
            $this->companyId = $username;
        }
        $this->privateKey = (string) ($creds['secret'] ?? '');
        $this->clientId   = trim((string) ($creds['integration_code'] ?? ''));
        $this->siteUrl    = rtrim(trim((string) ($creds['zone_url'] ?? '')), '/');
        $this->logger     = $logger;
    }

    /* ------------------------------------------------------------------ */
    /* Connection / site                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Validate credentials by resolving the codebase and issuing a trivial
     * authenticated call.
     *
     * @return array{ok:bool,message:string,zone_url:string}
     */
    public function testConnection(): array
    {
        try {
            if ($this->siteUrl === '') {
                throw new ApiException('Site URL is required (e.g. https://na.myconnectwise.net).');
            }
            if ($this->companyId === '' || $this->publicKey === '') {
                throw new ApiException('Company ID and public key are required (username form "company+publicKey").');
            }
            $this->request('GET', 'system/info');
            return array('ok' => true, 'message' => 'Connection successful.', 'zone_url' => $this->siteUrl);
        } catch (ApiException $e) {
            return array('ok' => false, 'message' => $e->getMessage(), 'zone_url' => $this->siteUrl);
        } catch (\Throwable $e) {
            return array('ok' => false, 'message' => $e->getMessage(), 'zone_url' => $this->siteUrl);
        }
    }

    /**
     * Resolve and cache the tenant's API codebase (e.g. "v2026_1/") via the
     * unauthenticated /login/companyinfo endpoint. Falls back to the stable
     * default codebase when discovery fails — ConnectWise redirects there.
     */
    private function codebase(): string
    {
        if ($this->codebase !== null) {
            return $this->codebase;
        }
        $this->codebase = self::DEFAULT_CODEBASE;
        try {
            $url  = $this->siteUrl . '/login/companyinfo/' . rawurlencode($this->companyId);
            $resp = $this->rawCurl('GET', $url, null, false);
            $cb   = (string) ($resp['json']['Codebase'] ?? '');
            if ($resp['status'] === 200 && $cb !== '') {
                $this->codebase = rtrim($cb, '/') . '/';
            }
        } catch (\Throwable $e) {
            // keep default codebase
        }
        return $this->codebase;
    }

    /** Full API base with trailing slash. */
    private function apiBase(): string
    {
        return $this->siteUrl . '/' . $this->codebase() . self::API_PATH;
    }

    /**
     * Base URL of the ConnectWise web UI for this tenant (deep links). The web
     * UI lives on the same regional host as the API.
     *
     * @return string|null
     */
    public function webBase(): ?string
    {
        return $this->siteUrl !== '' ? $this->siteUrl : null;
    }

    /**
     * Deep link that opens a service ticket in the ConnectWise web UI
     * (documented system_io openrecord route).
     */
    public function ticketDeepLink(int $ticketId): ?string
    {
        if ($this->siteUrl === '' || $this->companyId === '') {
            return null;
        }
        return $this->siteUrl . '/' . rtrim($this->codebase(), '/')
            . '/services/system_io/Service/fv_sr100_request.rails?service_recid='
            . $ticketId . '&companyName=' . rawurlencode($this->companyId);
    }

    /* ------------------------------------------------------------------ */
    /* Tickets                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Create a service ticket from normalized fields.
     *
     * @param array $fields Normalized ticket fields (see class docblock).
     * @return int New ConnectWise ticket id.
     */
    public function createTicket(array $fields): int
    {
        $body = array(
            'summary' => $this->clip((string) ($fields['title'] ?? 'osTicket'), 100),
        );
        if (!empty($fields['description'])) {
            $body['initialDescription'] = (string) $fields['description'];
        }
        if (!empty($fields['companyID'])) {
            $body['company'] = array('id' => (int) $fields['companyID']);
        }
        if (!empty($fields['contactID'])) {
            $body['contact'] = array('id' => (int) $fields['contactID']);
        }
        if (!empty($fields['queueID'])) {
            $body['board'] = array('id' => (int) $fields['queueID']);
        }
        if (!empty($fields['status'])) {
            $body['status'] = array('id' => (int) $fields['status']);
        }
        if (!empty($fields['priority'])) {
            $body['priority'] = array('id' => (int) $fields['priority']);
        }
        if (!empty($fields['issueType'])) {
            $body['type'] = array('id' => (int) $fields['issueType']);
        }
        if (!empty($fields['subIssueType'])) {
            $body['subType'] = array('id' => (int) $fields['subIssueType']);
        }
        if (!empty($fields['source'])) {
            $body['source'] = array('id' => (int) $fields['source']);
        }

        $resp = $this->request('POST', 'service/tickets', $body);
        if (empty($resp['id'])) {
            throw new ApiException('Ticket create returned no id.');
        }
        return (int) $resp['id'];
    }

    /**
     * Patch an existing ticket. Normalized field names are translated into
     * ConnectWise JSON-Patch operations. A 'resolution' value becomes a
     * resolution note (ConnectWise stores resolutions as flagged notes).
     *
     * @param int   $id
     * @param array $fields
     */
    public function updateTicket(int $id, array $fields): void
    {
        $ops = array();
        $paths = array(
            'status'    => 'status/id',
            'priority'  => 'priority/id',
            'queueID'   => 'board/id',
            'companyID' => 'company/id',
            'contactID' => 'contact/id',
        );
        foreach ($paths as $key => $path) {
            if (array_key_exists($key, $fields) && $fields[$key] !== null && $fields[$key] !== '') {
                $ops[] = array('op' => 'replace', 'path' => $path, 'value' => (int) $fields[$key]);
            }
        }
        if (isset($fields['title']) && $fields['title'] !== '') {
            $ops[] = array('op' => 'replace', 'path' => 'summary',
                'value' => $this->clip((string) $fields['title'], 100));
        }
        if ($ops) {
            $this->request('PATCH', "service/tickets/$id", $ops);
        }
        if (!empty($fields['resolution'])) {
            try {
                $this->request('POST', "service/tickets/$id/notes", array(
                    'text'           => (string) $fields['resolution'],
                    'resolutionFlag' => true,
                ));
            } catch (\Throwable $e) {
                if ($this->logger) {
                    $this->logger->warning("Resolution note failed for CW #$id: " . $e->getMessage(),
                        array('category' => 'api'));
                }
            }
        }
    }

    /**
     * @param int $id
     * @return array|null Normalized ticket, or null if not found.
     */
    public function getTicket(int $id): ?array
    {
        $t = $this->request('GET', "service/tickets/$id");
        if (empty($t['id'])) {
            return null;
        }
        $ticket = $this->normalizeTicket($t);
        $ticket['description'] = $this->initialDescription($id);
        return $ticket;
    }

    /**
     * Query tickets modified since a given UTC timestamp (for inbound sync).
     * No descriptions fetched — the inbound producer only needs identity,
     * status and activity fields.
     *
     * @param string $sinceUtc  e.g. "2026-06-30T00:00:00Z"
     * @param int    $maxRecords
     * @return array<int,array<string,mixed>>
     */
    public function getTicketsModifiedSince(string $sinceUtc, int $maxRecords = 50): array
    {
        $out = array();
        $items = $this->request('GET', 'service/tickets?' . http_build_query(array(
            'conditions' => 'lastUpdated > [' . $this->isoUtc($sinceUtc) . ']',
            'orderBy'    => 'lastUpdated asc',
            'pageSize'   => max(1, min(1000, $maxRecords)),
        )));
        foreach ((array) $items as $t) {
            if (is_array($t) && !empty($t['id'])) {
                $out[] = $this->normalizeTicket($t);
            }
        }
        return $out;
    }

    /**
     * Fetch OPEN service tickets (closedFlag = false) for inbound import.
     *
     * @param int $maxRecords
     * @param int $completeStatus Unused for ConnectWise (closedFlag is
     *                            authoritative); kept for interface parity.
     * @return array<int,array<string,mixed>>
     */
    public function getOpenTickets(int $maxRecords = 50, int $completeStatus = 0): array
    {
        $items = $this->request('GET', 'service/tickets?' . http_build_query(array(
            'conditions' => 'closedFlag = false',
            'pageSize'   => max(1, min(1000, $maxRecords)),
        )));
        $out = array();
        foreach ((array) $items as $t) {
            if (is_array($t) && !empty($t['id'])) {
                $out[] = $this->normalizeTicket($t);
            }
        }
        return $out;
    }

    /**
     * Query tickets for import using an admin-defined filter spec. Descriptions
     * ARE fetched (one extra notes call per ticket) because the import worker
     * uses them as the osTicket message body.
     *
     * @param array $spec From Settings::importFilterSpec().
     * @param int   $max
     * @return array<int,array<string,mixed>>
     */
    public function queryTicketsForImport(array $spec, int $max = 50): array
    {
        $cond = array();
        if (!empty($spec['status_op'])) {
            $v = $spec['status_value'];
            switch ($spec['status_op']) {
                case 'in':
                    $cond[] = 'status/id in (' . implode(',', array_map('intval', (array) $v)) . ')';
                    break;
                case 'noteq':
                    $cond[] = 'status/id != ' . (int) $v;
                    break;
                default:
                    $cond[] = 'status/id = ' . (int) $v;
            }
        }
        if (!empty($spec['company_ids'])) {
            $cond[] = 'company/id in (' . implode(',', array_map('intval', $spec['company_ids'])) . ')';
        }
        // Board + member scoping. When BOTH are set they combine as OR:
        // "on our board OR assigned to one of our members" — the co-managed
        // pattern where the board is the contract and direct assignment is the
        // safety net. A single filter applies as a plain AND condition.
        $boards  = !empty($spec['queue_ids'])
            ? 'board/id in (' . implode(',', array_map('intval', $spec['queue_ids'])) . ')' : '';
        $members = !empty($spec['resource_ids'])
            ? 'owner/id in (' . implode(',', array_map('intval', $spec['resource_ids'])) . ')' : '';
        if ($boards !== '' && $members !== '') {
            $cond[] = "($boards or $members)";
        } elseif ($boards !== '') {
            $cond[] = $boards;
        } elseif ($members !== '') {
            $cond[] = $members;
        }
        if (!empty($spec['since_utc'])) {
            $cond[] = 'lastUpdated > [' . $this->isoUtc((string) $spec['since_utc']) . ']';
        }

        $query = array('pageSize' => max(1, min(1000, $max)), 'orderBy' => 'lastUpdated asc');
        if ($cond) {
            $query['conditions'] = implode(' and ', $cond);
        }
        $items = $this->request('GET', 'service/tickets?' . http_build_query($query));

        $out = array();
        foreach ((array) $items as $t) {
            if (!is_array($t) || empty($t['id'])) {
                continue;
            }
            $ticket = $this->normalizeTicket($t);
            try {
                $ticket['description'] = $this->initialDescription((int) $t['id']);
            } catch (\Throwable $e) {
                $ticket['description'] = '';
            }
            $out[] = $ticket;
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Ticket notes                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * @param int    $ticketId
     * @param string $title
     * @param string $description
     * @param int    $noteType   Unused for ConnectWise (interface parity).
     * @param int    $publish    1 = public (Discussion), 2 = Internal Analysis.
     * @return int   New note id.
     */
    public function createTicketNote(int $ticketId, string $title, string $description, int $noteType = 1, int $publish = 1): int
    {
        // ConnectWise notes have no separate title — prepend a meaningful one.
        $text = $description;
        $title = trim($title);
        if ($title !== '' && !in_array(mb_strtolower($title), array('update', 'osticket update'), true)) {
            $text = $title . "\n\n" . $description;
        }
        $body = array(
            'text'                  => $text,
            'detailDescriptionFlag' => $publish !== 2,
            'internalAnalysisFlag'  => $publish === 2,
        );
        $resp = $this->request('POST', "service/tickets/$ticketId/notes", $body);
        return (int) ($resp['id'] ?? 0);
    }

    /**
     * Notes added to a ticket since a timestamp, normalized. The ticket's
     * FIRST note is always excluded: it is the initial description, which the
     * sync already carries as the ticket body (outbound: our own echo;
     * inbound: the imported message) — importing it again would duplicate it.
     *
     * @param int    $ticketId
     * @param string $sinceUtc
     * @return array<int,array<string,mixed>>
     */
    public function getTicketNotesSince(int $ticketId, string $sinceUtc): array
    {
        $firstId = 0;
        try {
            $first = $this->request('GET', "service/tickets/$ticketId/notes?" . http_build_query(array(
                'orderBy' => 'id asc', 'pageSize' => 1,
            )));
            $firstId = (int) ($first[0]['id'] ?? 0);
        } catch (\Throwable $e) {
            // best effort — worst case the first note is re-examined below
        }

        $items = $this->request('GET', "service/tickets/$ticketId/notes?" . http_build_query(array(
            'conditions' => 'dateCreated > [' . $this->isoUtc($sinceUtc) . ']',
            'orderBy'    => 'dateCreated asc',
            'pageSize'   => 100,
        )));

        $out = array();
        foreach ((array) $items as $n) {
            if (!is_array($n) || empty($n['id']) || (int) $n['id'] === $firstId) {
                continue;
            }
            $out[] = array(
                'id'                 => (int) $n['id'],
                'title'              => 'Note',
                'description'        => (string) ($n['text'] ?? ''),
                'publish'            => !empty($n['internalAnalysisFlag']) ? 2 : 1,
                'creatorResourceID'  => (int) ($n['member']['id'] ?? 0),
                'createdByContactID' => (int) ($n['contact']['id'] ?? 0),
                'createDateTime'     => (string) ($n['dateCreated'] ?? ($n['_info']['dateEntered'] ?? '')),
                'lastActivityDate'   => (string) ($n['_info']['lastUpdated'] ?? ($n['dateCreated'] ?? '')),
            );
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Contacts / companies / agreements                                  */
    /* ------------------------------------------------------------------ */

    /**
     * @param string $email
     * @return array|null First matching contact, normalized.
     */
    public function findContactByEmail(string $email): ?array
    {
        if ($email === '') {
            return null;
        }
        $items = $this->request('GET', 'company/contacts?' . http_build_query(array(
            'childConditions' => 'communicationItems/value = ' . $this->q($email)
                . ' and communicationItems/communicationType = "Email"',
            'pageSize'        => 1,
        )));
        $c = $items[0] ?? null;
        return is_array($c) ? $this->normalizeContact($c, $email) : null;
    }

    /**
     * @param int $id ConnectWise contact id.
     * @return array|null Normalized contact.
     */
    public function getContact(int $id): ?array
    {
        $c = $this->request('GET', "company/contacts/$id");
        return !empty($c['id']) ? $this->normalizeContact($c) : null;
    }

    /**
     * @param int $id ConnectWise company id.
     * @return array|null Normalized company.
     */
    public function getCompany(int $id): ?array
    {
        $c = $this->request('GET', "company/companies/$id");
        if (empty($c['id'])) {
            return null;
        }
        return array(
            'id'          => (int) $c['id'],
            'companyName' => (string) ($c['name'] ?? $c['identifier'] ?? $c['id']),
        );
    }

    /**
     * @param int $max
     * @return array<int,array<string,mixed>> Active companies (id, companyName).
     */
    public function listCompanies(int $max = 30): array
    {
        $items = $this->request('GET', 'company/companies?' . http_build_query(array(
            'conditions' => 'deletedFlag = false',
            'orderBy'    => 'name asc',
            'pageSize'   => max(1, min(1000, $max)),
        )));
        $out = array();
        foreach ((array) $items as $c) {
            if (is_array($c) && !empty($c['id'])) {
                $out[] = array(
                    'id'          => (int) $c['id'],
                    'companyName' => (string) ($c['name'] ?? $c['identifier'] ?? $c['id']),
                );
            }
        }
        return $out;
    }

    /**
     * ConnectWise agreement, normalized to the contract shape.
     *
     * @param int $id
     * @return array|null {id, contractName}
     */
    public function getContract(int $id): ?array
    {
        $a = $this->request('GET', "finance/agreements/$id");
        if (empty($a['id'])) {
            return null;
        }
        return array(
            'id'           => (int) $a['id'],
            'contractName' => (string) ($a['name'] ?? ''),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Time entries                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Create a time entry against a service ticket from normalized fields.
     *
     * @param array $fields Normalized time-entry fields.
     * @return int New time entry id.
     */
    public function createTimeEntry(array $fields): int
    {
        $body = array(
            'chargeToId'     => (int) ($fields['ticketID'] ?? 0),
            'chargeToType'   => 'ServiceTicket',
            'billableOption' => !empty($fields['isNonBillable']) ? 'DoNotBill' : 'Billable',
        );
        if (!empty($fields['resourceID'])) {
            $body['member'] = array('id' => (int) $fields['resourceID']);
        }
        if (!empty($fields['billingCodeID'])) {
            $body['workType'] = array('id' => (int) $fields['billingCodeID']);
        }
        if (!empty($fields['roleID'])) {
            $body['workRole'] = array('id' => (int) $fields['roleID']);
        }
        if (!empty($fields['startDateTime']) && !empty($fields['endDateTime'])) {
            $body['timeStart'] = $this->isoUtc((string) $fields['startDateTime']);
            $body['timeEnd']   = $this->isoUtc((string) $fields['endDateTime']);
        } elseif (!empty($fields['hoursWorked'])) {
            $body['actualHours'] = (float) $fields['hoursWorked'];
        }
        if (!empty($fields['summaryNotes'])) {
            $body['notes'] = (string) $fields['summaryNotes'];
        }
        if (!empty($fields['internalNotes'])) {
            $body['internalNotes'] = (string) $fields['internalNotes'];
        }

        $resp = $this->request('POST', 'time/entries', $body);
        if (empty($resp['id'])) {
            throw new ApiException('Time entry create returned no id.');
        }
        return (int) $resp['id'];
    }

    /**
     * @param int $ticketId
     * @return array<int,array<string,mixed>> Normalized time entries.
     */
    public function getTimeEntries(int $ticketId): array
    {
        $items = $this->request('GET', 'time/entries?' . http_build_query(array(
            'conditions' => 'chargeToType = "ServiceTicket" and chargeToId = ' . (int) $ticketId,
            'pageSize'   => 100,
        )));
        $out = array();
        foreach ((array) $items as $te) {
            if (!is_array($te) || empty($te['id'])) {
                continue;
            }
            $start = (string) ($te['timeStart'] ?? '');
            $out[] = array(
                'id'             => (int) $te['id'],
                'hoursWorked'    => (float) ($te['actualHours'] ?? 0),
                'isNonBillable'  => (($te['billableOption'] ?? 'Billable') !== 'Billable'),
                'startDateTime'  => $start,
                'endDateTime'    => (string) ($te['timeEnd'] ?? ''),
                'dateWorked'     => $start !== '' ? substr($start, 0, 10) : '',
                'createDateTime' => (string) ($te['_info']['dateEntered'] ?? $start),
                'summaryNotes'   => (string) ($te['notes'] ?? ''),
                'internalNotes'  => (string) ($te['internalNotes'] ?? ''),
                'billingCodeID'  => (int) ($te['workType']['id'] ?? 0),
                'roleID'         => (int) ($te['workRole']['id'] ?? 0),
                'resourceID'     => (int) ($te['member']['id'] ?? 0),
            );
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Reference data (picklist sources)                                  */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int,array<string,mixed>> Active work types, normalized to
     *         the billing-code shape (id, name, isActive, useType=1).
     */
    public function getBillingCodes(): array
    {
        $items = $this->request('GET', 'time/workTypes?' . http_build_query(array(
            'conditions' => 'inactiveFlag = false',
            'orderBy'    => 'name asc',
            'pageSize'   => 500,
        )));
        $out = array();
        foreach ((array) $items as $w) {
            if (is_array($w) && !empty($w['id'])) {
                $out[] = array(
                    'id'       => (int) $w['id'],
                    'name'     => (string) ($w['name'] ?? $w['id']),
                    'isActive' => true,
                    'useType'  => 1,
                );
            }
        }
        return $out;
    }

    /**
     * @return array<int,array<string,mixed>> Active work roles.
     */
    public function getRoles(): array
    {
        $items = $this->request('GET', 'time/workRoles?' . http_build_query(array(
            'conditions' => 'inactiveFlag = false',
            'orderBy'    => 'name asc',
            'pageSize'   => 500,
        )));
        $out = array();
        foreach ((array) $items as $r) {
            if (is_array($r) && !empty($r['id'])) {
                $out[] = array(
                    'id'       => (int) $r['id'],
                    'name'     => (string) ($r['name'] ?? $r['id']),
                    'isActive' => true,
                );
            }
        }
        return $out;
    }

    /**
     * @return array<int,array<string,mixed>> Active members (technicians),
     *         normalized to the resource shape.
     */
    public function getResources(): array
    {
        $items = $this->request('GET', 'system/members?' . http_build_query(array(
            'conditions' => 'inactiveFlag = false',
            'pageSize'   => 500,
        )));
        $out = array();
        foreach ((array) $items as $m) {
            if (is_array($m) && !empty($m['id'])) {
                $out[] = $this->normalizeMember($m);
            }
        }
        return $out;
    }

    /**
     * Find a ConnectWise member by email (for agent -> member mapping). Tries
     * an indexed officeEmail condition first, then falls back to scanning the
     * (bounded) member list for any matching email field.
     *
     * @param string $email
     * @return array|null Normalized resource.
     */
    public function getResourceByEmail(string $email): ?array
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }
        try {
            $items = $this->request('GET', 'system/members?' . http_build_query(array(
                'conditions' => 'officeEmail = ' . $this->q($email),
                'pageSize'   => 1,
            )));
            if (!empty($items[0]['id'])) {
                return $this->normalizeMember($items[0]);
            }
        } catch (\Throwable $e) {
            // fall through to the scan
        }
        $needle = mb_strtolower($email);
        foreach ($this->getResources() as $m) {
            if (mb_strtolower((string) ($m['email'] ?? '')) === $needle) {
                return $m;
            }
        }
        return null;
    }

    /**
     * Picklist/field metadata, synthesized into the entityInformation shape
     * the picklist cache consumes: statuses (from all active boards),
     * priorities, and the boards themselves as the queue list.
     *
     * @param string $entity Only 'Tickets' is meaningful.
     * @return array [{name, picklistValues:[{value,label,isActive,sortOrder}]}]
     */
    public function getFieldInfo(string $entity): array
    {
        if (strcasecmp($entity, 'Tickets') !== 0) {
            return array();
        }
        $boards = $this->request('GET', 'service/boards?' . http_build_query(array(
            'conditions' => 'inactiveFlag = false',
            'orderBy'    => 'name asc',
            'pageSize'   => 100,
        )));

        $queueValues  = array();
        $statusValues = array();
        $statusLabels = array(); // lowercased label -> count, to disambiguate
        $boardCount   = 0;
        foreach ((array) $boards as $b) {
            if (!is_array($b) || empty($b['id'])) {
                continue;
            }
            $bid   = (int) $b['id'];
            $bname = (string) ($b['name'] ?? $bid);
            $queueValues[] = array('value' => $bid, 'label' => $bname, 'isActive' => true, 'sortOrder' => 0);

            // Statuses are PER BOARD in ConnectWise; cap the walk defensively.
            if (++$boardCount > 25) {
                continue;
            }
            try {
                $statuses = $this->request('GET', "service/boards/$bid/statuses?" . http_build_query(array(
                    'pageSize' => 200, 'orderBy' => 'sortOrder asc',
                )));
                foreach ((array) $statuses as $s) {
                    if (!is_array($s) || empty($s['id'])) {
                        continue;
                    }
                    $label = (string) ($s['name'] ?? $s['id']);
                    $statusValues[] = array(
                        'value'     => (int) $s['id'],
                        'label'     => $label,
                        '_board'    => $bname,
                        'isActive'  => empty($s['inactiveFlag']),
                        'sortOrder' => (int) ($s['sortOrder'] ?? 0),
                    );
                    $key = mb_strtolower($label);
                    $statusLabels[$key] = ($statusLabels[$key] ?? 0) + 1;
                }
            } catch (\Throwable $e) {
                // board without readable statuses — skip
            }
        }
        // Disambiguate duplicate status names across boards: "Name (Board)".
        foreach ($statusValues as &$sv) {
            if (($statusLabels[mb_strtolower($sv['label'])] ?? 0) > 1) {
                $sv['label'] .= ' (' . $sv['_board'] . ')';
            }
            unset($sv['_board']);
        }
        unset($sv);

        $priorityValues = array();
        try {
            $prio = $this->request('GET', 'service/priorities?' . http_build_query(array(
                'pageSize' => 100, 'orderBy' => 'sortOrder asc',
            )));
            foreach ((array) $prio as $p) {
                if (is_array($p) && !empty($p['id'])) {
                    $priorityValues[] = array(
                        'value'     => (int) $p['id'],
                        'label'     => (string) ($p['name'] ?? $p['id']),
                        'isActive'  => true,
                        'sortOrder' => (int) ($p['sortOrder'] ?? 0),
                    );
                }
            }
        } catch (\Throwable $e) {
            // priorities are a convenience list
        }

        return array(
            array('name' => 'status',   'picklistValues' => $statusValues),
            array('name' => 'priority', 'picklistValues' => $priorityValues),
            array('name' => 'queueID',  'picklistValues' => $queueValues),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Ticket attachments (ConnectWise documents)                         */
    /* ------------------------------------------------------------------ */

    /**
     * Upload a file to a service ticket (multipart document upload).
     *
     * @param string $data Raw binary content.
     * @return int New document id (0 when the API returned none).
     */
    public function uploadTicketAttachment(int $ticketId, string $name, string $data, int $publish = 1): int
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cwdoc');
        if ($tmp === false) {
            throw new ApiException('Cannot create temp file for attachment upload.');
        }
        try {
            file_put_contents($tmp, $data);
            $post = array(
                'recordId'   => (string) $ticketId,
                'recordType' => 'Ticket',
                'title'      => mb_substr($name, 0, 200),
                'file'       => new \CURLFile($tmp, 'application/octet-stream', $name),
            );
            $resp = $this->rawCurl('POST', $this->apiBase() . 'system/documents', $post, true, true);
            if ($resp['status'] < 200 || $resp['status'] >= 300) {
                throw new ApiException(
                    $this->extractError($resp['json'], $resp['raw'] ?? '', $resp['status']),
                    $resp['status'],
                    in_array($resp['status'], array(408, 429, 500, 502, 503, 504), true)
                );
            }
            $json = $resp['json'];
            return (int) ($json['id'] ?? ($json[0]['id'] ?? 0));
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @return array<int,array<string,mixed>> Normalized attachment metadata.
     */
    public function listTicketAttachments(int $ticketId): array
    {
        $items = $this->request('GET', 'system/documents?' . http_build_query(array(
            'recordType' => 'Ticket',
            'recordId'   => $ticketId,
            'pageSize'   => 50,
        )));
        $out = array();
        foreach ((array) $items as $d) {
            if (!is_array($d) || empty($d['id'])) {
                continue;
            }
            $out[] = array(
                'id'                   => (int) $d['id'],
                'title'                => (string) ($d['title'] ?? ''),
                'fullPath'             => (string) ($d['fileName'] ?? ($d['serverFileName'] ?? '')),
                'attachedByResourceID' => 0, // CW reports a text identifier only
                'attachedByContactID'  => 0,
                'attachDate'           => (string) ($d['_info']['lastUpdated'] ?? ''),
                'contentType'          => 'application/octet-stream',
            );
        }
        return $out;
    }

    /**
     * Download one document's binary.
     *
     * @return string|null Raw binary, or null when unavailable.
     */
    public function getTicketAttachmentData(int $ticketId, int $attachmentId): ?string
    {
        $resp = $this->rawCurl('GET', $this->apiBase() . "system/documents/$attachmentId/download", null, true);
        if ($resp['status'] >= 200 && $resp['status'] < 300 && ($resp['raw'] ?? '') !== '') {
            return (string) $resp['raw'];
        }
        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Normalizers                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * ConnectWise service ticket -> normalized shape (no description; that
     * needs a separate notes call — see initialDescription()).
     *
     * @param array $t Raw CW ticket.
     * @return array<string,mixed>
     */
    private function normalizeTicket(array $t): array
    {
        return array(
            'id'                      => (int) $t['id'],
            'ticketNumber'            => (string) $t['id'],
            'title'                   => (string) ($t['summary'] ?? ''),
            'description'             => '',
            'status'                  => (int) ($t['status']['id'] ?? 0),
            'priority'                => (int) ($t['priority']['id'] ?? 0),
            'companyID'               => (int) ($t['company']['id'] ?? 0),
            'contactID'               => (int) ($t['contact']['id'] ?? 0),
            'queueID'                 => (int) ($t['board']['id'] ?? 0),
            'assignedResourceID'      => (int) ($t['owner']['id'] ?? 0),
            'assignedResourceRoleID'  => 0,
            'billingCodeID'           => (int) ($t['workType']['id'] ?? 0),
            'contractID'              => (int) ($t['agreement']['id'] ?? 0),
            'issueType'               => (int) ($t['type']['id'] ?? 0),
            'subIssueType'            => (int) ($t['subType']['id'] ?? 0),
            'source'                  => (int) ($t['source']['id'] ?? 0),
            'serviceLevelAgreementID' => (int) ($t['sla']['id'] ?? 0),
            'ticketType'              => 0,
            'createDate'              => (string) ($t['_info']['dateEntered'] ?? ''),
            'dueDateTime'             => (string) ($t['requiredDate'] ?? ''),
            'estimatedHours'          => (float) ($t['budgetHours'] ?? 0),
            'lastActivityDate'        => (string) ($t['_info']['lastUpdated'] ?? ''),
            'closedFlag'              => !empty($t['closedFlag']),
        );
    }

    /**
     * The ticket's initial description = text of its FIRST note (ConnectWise
     * stores the description as the first Discussion note).
     */
    private function initialDescription(int $ticketId): string
    {
        try {
            $first = $this->request('GET', "service/tickets/$ticketId/notes?" . http_build_query(array(
                'orderBy' => 'id asc', 'pageSize' => 1,
            )));
            return (string) ($first[0]['text'] ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * @param array       $c            Raw CW contact.
     * @param string|null $knownEmail   Email that matched the search, if any.
     * @return array<string,mixed>
     */
    private function normalizeContact(array $c, ?string $knownEmail = null): array
    {
        $email = (string) ($knownEmail ?? '');
        if ($email === '' && !empty($c['communicationItems']) && is_array($c['communicationItems'])) {
            $firstEmail = '';
            foreach ($c['communicationItems'] as $ci) {
                if (!is_array($ci)) {
                    continue;
                }
                $type = mb_strtolower((string) ($ci['communicationType'] ?? ($ci['type']['name'] ?? '')));
                if ($type !== 'email') {
                    continue;
                }
                if ($firstEmail === '') {
                    $firstEmail = (string) ($ci['value'] ?? '');
                }
                if (!empty($ci['defaultFlag'])) {
                    $email = (string) ($ci['value'] ?? '');
                    break;
                }
            }
            if ($email === '') {
                $email = $firstEmail;
            }
        }
        return array(
            'id'           => (int) $c['id'],
            'firstName'    => (string) ($c['firstName'] ?? ''),
            'lastName'     => (string) ($c['lastName'] ?? ''),
            'emailAddress' => $email,
            'companyID'    => (int) ($c['company']['id'] ?? 0),
        );
    }

    /**
     * @param array $m Raw CW member.
     * @return array<string,mixed>
     */
    private function normalizeMember(array $m): array
    {
        return array(
            'id'        => (int) $m['id'],
            'firstName' => (string) ($m['firstName'] ?? ''),
            'lastName'  => (string) ($m['lastName'] ?? ''),
            'email'     => (string) ($m['officeEmail'] ?? ($m['primaryEmail'] ?? '')),
            'isActive'  => empty($m['inactiveFlag']),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Core request handling                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Issue an authenticated API request, decoding JSON and translating errors
     * into ApiException. Performs a single inline retry on HTTP 429/503
     * honoring Retry-After (bounded), leaving longer backoff to the retry
     * queue.
     *
     * @param string     $method  GET|POST|PATCH|PUT|DELETE
     * @param string     $path    Path relative to the API base.
     * @param array|null $body    JSON body (JSON-Patch list for PATCH).
     * @return array  Decoded JSON response (associative or list).
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        $url = $this->apiBase() . ltrim($path, '/');

        $attempt = 0;
        do {
            $attempt++;
            $resp = $this->rawCurl($method, $url, $body, true);

            // Transient: one short inline retry before deferring to the queue.
            if (in_array($resp['status'], array(429, 503), true) && $attempt === 1) {
                $wait = (int) ($resp['retry_after'] ?? 2);
                $wait = max(1, min(5, $wait)); // never block the web request long
                usleep($wait * 1000000);
                continue;
            }
            break;
        } while ($attempt < 2);

        return $this->handleResponse($method, $url, $resp);
    }

    /**
     * @param string $method
     * @param string $url
     * @param array  $resp  Result of rawCurl().
     * @return array
     */
    private function handleResponse(string $method, string $url, array $resp): array
    {
        $status = $resp['status'];
        $json   = $resp['json'];

        if ($this->logger) {
            $this->logger->debug("API $method $url -> $status", array(
                'category' => 'api',
                'request'  => $resp['sent_body'] ?? '',
                'response' => $resp['raw'] ?? '',
            ));
        }

        if ($status >= 200 && $status < 300) {
            return is_array($json) ? $json : array();
        }

        $message = $this->extractError($json, $resp['raw'] ?? '', $status);

        $retryable = in_array($status, array(0, 408, 429, 500, 502, 503, 504), true);

        if ($status === 401 || $status === 403) {
            if ($this->logger) {
                $this->logger->error("Authentication failure (HTTP $status): $message",
                    array('category' => 'auth'));
            }
            throw new ApiException("Authentication failed: $message", $status, false);
        }

        throw new ApiException($message, $status, $retryable);
    }

    /**
     * Pull a human message out of ConnectWise's error response shapes.
     *
     * @param mixed  $json
     * @param string $raw
     * @param int    $status
     */
    private function extractError($json, string $raw, int $status): string
    {
        if (is_array($json)) {
            $parts = array();
            if (!empty($json['message'])) {
                $parts[] = (string) $json['message'];
            }
            if (!empty($json['errors']) && is_array($json['errors'])) {
                foreach ($json['errors'] as $err) {
                    if (is_array($err) && !empty($err['message'])) {
                        $parts[] = (string) $err['message'];
                    } elseif (is_string($err)) {
                        $parts[] = $err;
                    }
                }
            }
            if ($parts) {
                return implode('; ', array_unique($parts));
            }
        }
        $snippet = trim(substr(strip_tags((string) $raw), 0, 300));
        return "HTTP $status" . ($snippet !== '' ? ": $snippet" : '');
    }

    /**
     * Low-level cURL execution.
     *
     * @param string     $method
     * @param string     $url
     * @param array|null $body      JSON body, or multipart fields when
     *                              $multipart is true.
     * @param bool       $auth      Whether to attach authentication headers.
     * @param bool       $multipart Send $body as multipart/form-data.
     * @return array{status:int,json:mixed,raw:string,retry_after:?int,sent_body:?string}
     */
    private function rawCurl(string $method, string $url, ?array $body, bool $auth, bool $multipart = false): array
    {
        if (!function_exists('curl_init')) {
            throw new ApiException('PHP cURL extension is required but not installed.');
        }

        // Reuse one handle across calls so the TLS connection is kept alive
        // (big win for batch runs that make many requests to the same host).
        if ($this->ch === null) {
            $this->ch = curl_init();
        } else {
            curl_reset($this->ch);
        }
        $ch = $this->ch;
        $headers = array('Accept: application/json');
        if (!$multipart) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($auth) {
            $headers[] = 'Authorization: Basic ' . base64_encode(
                $this->companyId . '+' . $this->publicKey . ':' . $this->privateKey
            );
            $headers[] = 'clientId: ' . $this->clientId;
        }

        $sentBody = null;
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'osTicket-ConnectWise/1.0',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ));

        if ($body !== null && in_array($method, array('POST', 'PATCH', 'PUT'), true)) {
            if ($multipart) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                $sentBody = '(multipart form data)';
            } else {
                $sentBody = json_encode($body);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $sentBody);
            }
        }

        $response   = curl_exec($ch);
        $errno      = curl_errno($ch);
        $error      = curl_error($ch);
        $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        // NB: handle intentionally NOT closed here — reused (see __destruct).

        if ($errno) {
            throw new ApiException("Network error: $error", 0, true);
        }

        $rawHeaders = substr((string) $response, 0, $headerSize);
        $rawBody    = substr((string) $response, $headerSize);
        $retryAfter = null;
        if (preg_match('/^Retry-After:\s*(\d+)/mi', $rawHeaders, $m)) {
            $retryAfter = (int) $m[1];
        }

        return array(
            'status'      => $status,
            'json'        => json_decode($rawBody, true),
            'raw'         => $rawBody,
            'retry_after' => $retryAfter,
            'sent_body'   => $sentBody,
        );
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /** Double-quote + escape a string for a ConnectWise conditions clause. */
    private function q(string $s): string
    {
        return '"' . str_replace('"', '\\"', $s) . '"';
    }

    /** Normalize a datetime string to ISO-8601 UTC with a Z suffix. */
    private function isoUtc(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return gmdate('Y-m-d\TH:i:s\Z');
        }
        // Already zoned (Z or ±hh:mm offset): pass through.
        if (preg_match('/(Z|[+\-]\d{2}:?\d{2})$/i', $s)) {
            return $s;
        }
        $ts = strtotime($s . ' UTC');
        if ($ts === false) {
            $ts = strtotime($s) ?: time();
        }
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    private function clip(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }

    /**
     * Close the reused cURL handle when the client is destroyed.
     */
    public function __destruct()
    {
        if ($this->ch !== null) {
            @curl_close($this->ch);
            $this->ch = null;
        }
    }
}
