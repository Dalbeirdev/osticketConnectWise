<?php
/**
 * ConnectWise Integration — ticket mapping repository & field translator.
 *
 * Owns the connectwise_ticket_map table and converts osTicket ticket data into
 * ConnectWise Ticket entity fields (and interprets ConnectWise values coming back).
 *
 * @package ConnectWise Integration
 */

namespace ConnectWise;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * Persistence + translation for the osTicket <-> ConnectWise ticket relationship.
 */
class Ticket
{
    /** @var Settings */
    private $settings;

    /** @var int|null Client instance this repository is bound to (null = global). */
    private $instanceId;

    /**
     * @param Settings $settings
     * @param int|null $instanceId Instance that owns mappings created/queried
     *                             through this repository. null = global view
     *                             (reads unscoped; writes tag as legacy #1).
     */
    public function __construct(Settings $settings, ?int $instanceId = null)
    {
        $this->settings = $settings;
        $this->instanceId = $instanceId;
    }

    /* ------------------------------------------------------------------ */
    /* Mapping repository (prepared via db_input escaping)                */
    /* ------------------------------------------------------------------ */

    /**
     * @param int $osticketTicketId
     * @return array|null Mapping row.
     */
    public function findByOsticketId(int $osticketTicketId): ?array
    {
        $prefix = Installer::prefix();
        $id = (int) $osticketTicketId;
        $res = db_query("SELECT * FROM `{$prefix}connectwise_ticket_map` WHERE osticket_ticket_id=$id LIMIT 1");
        return ($res && ($row = db_fetch_array($res))) ? $row : null;
    }

    /**
     * @param int $connectwiseTicketId
     * @return array|null Mapping row.
     */
    public function findByConnectWiseId(int $connectwiseTicketId): ?array
    {
        $prefix = Installer::prefix();
        $id = (int) $connectwiseTicketId;
        // Scoped to this repository's instance: the same ConnectWise ticket id can
        // exist in two different client tenants. Global view = unscoped.
        $scope = $this->instanceId ? (' AND instance_id=' . (int) $this->instanceId) : '';
        $res = db_query("SELECT * FROM `{$prefix}connectwise_ticket_map` "
            . "WHERE connectwise_ticket_id=$id$scope LIMIT 1");
        return ($res && ($row = db_fetch_array($res))) ? $row : null;
    }

    /**
     * Create the initial mapping row when an osTicket ticket is registered for
     * sync (ConnectWise id may be filled in later by the queue worker).
     *
     * @return int Inserted map id.
     */
    public function createMapping(int $osticketTicketId, ?int $connectwiseTicketId = null, string $direction = 'bidirectional'): int
    {
        $prefix = Installer::prefix();
        $ost = (int) $osticketTicketId;
        $at  = $connectwiseTicketId ? (int) $connectwiseTicketId : 'NULL';
        $dir = db_input($direction);
        $iid = (int) ($this->instanceId ?: 1);
        db_query(
            "INSERT INTO `{$prefix}connectwise_ticket_map` "
            . "(instance_id, osticket_ticket_id, connectwise_ticket_id, sync_direction, last_updated_by, created, updated) "
            . "VALUES ($iid, $ost, $at, $dir, 'osticket', NOW(), NOW()) "
            . "ON DUPLICATE KEY UPDATE updated=NOW()"
        );
        return (int) db_insert_id();
    }

    /**
     * Attach a resolved ConnectWise ticket id + number to an existing mapping.
     */
    public function linkConnectWiseTicket(int $mapId, int $connectwiseTicketId, string $number = ''): void
    {
        $prefix = Installer::prefix();
        $mid = (int) $mapId;
        $at  = (int) $connectwiseTicketId;
        $num = db_input($number);
        db_query(
            "UPDATE `{$prefix}connectwise_ticket_map` "
            . "SET connectwise_ticket_id=$at, connectwise_ticket_number=$num, updated=NOW() WHERE id=$mid"
        );
    }

    /**
     * Record that a sync happened, storing the direction, author and a content
     * hash used to detect future changes / avoid ping-pong loops.
     */
    public function touch(int $mapId, string $updatedBy, ?string $osticketHash = null, ?string $connectwiseLastActivity = null): void
    {
        $prefix = Installer::prefix();
        $mid = (int) $mapId;
        $by  = db_input($updatedBy);
        $sets = array("last_sync_time=NOW()", "last_updated_by=$by", "updated=NOW()");
        if ($osticketHash !== null) {
            $sets[] = 'osticket_hash=' . db_input($osticketHash);
        }
        if ($connectwiseLastActivity !== null) {
            $sets[] = 'connectwise_lastactivity=' . db_input($connectwiseLastActivity);
        }
        db_query("UPDATE `{$prefix}connectwise_ticket_map` SET " . implode(',', $sets) . " WHERE id=$mid");
    }

    /**
     * Cache the ConnectWise status on the mapping row (perf: avoids live lookups).
     */
    public function setConnectWiseStatus(int $mapId, string $status): void
    {
        $prefix = Installer::prefix();
        $mid = (int) $mapId;
        $st  = db_input($status);
        db_query("UPDATE `{$prefix}connectwise_ticket_map` SET connectwise_status=$st, updated=NOW() WHERE id=$mid");
    }

    /**
     * @return int Total mapped tickets.
     */
    public function countMapped(): int
    {
        $prefix = Installer::prefix();
        $scope = $this->instanceId ? (' AND instance_id=' . (int) $this->instanceId) : '';
        $res = db_query("SELECT COUNT(*) AS c FROM `{$prefix}connectwise_ticket_map` WHERE connectwise_ticket_id IS NOT NULL$scope");
        return ($res && ($r = db_fetch_array($res))) ? (int) $r['c'] : 0;
    }

    /* ------------------------------------------------------------------ */
    /* Field translation: osTicket -> ConnectWise                            */
    /* ------------------------------------------------------------------ */

    /**
     * Build the ConnectWise Ticket entity payload from an osTicket ticket.
     *
     * Resolves the ConnectWise company/contact from the requester email, falling
     * back to the configured default company. Priority/status/queue use either
     * the per-instance mapping or the configured defaults.
     *
     * @param \Ticket      $osTicket
     * @param ConnectWiseApi  $api
     * @param Logger       $logger
     * @return array ConnectWise Ticket fields.
     */
    public function buildConnectWiseFields(\Ticket $osTicket, ConnectWiseApi $api, Logger $logger): array
    {
        $defaults = $this->settings->defaults();

        // Resolve contact & company from requester email where possible.
        $email     = $this->osticketEmail($osTicket);
        $companyId = $defaults['company_id'];
        $contactId = null;

        if ($email) {
            try {
                $contact = $api->findContactByEmail($email);
                if ($contact) {
                    $contactId = (int) $contact['id'];
                    if (!empty($contact['companyID'])) {
                        $companyId = (int) $contact['companyID'];
                    }
                    // Contact Sync: persist the Contact -> User link for later
                    // inbound attribution (identity map, per instance).
                    if ($contactId && method_exists($osTicket, 'getOwnerId') && (int) $osTicket->getOwnerId()) {
                        (new IdentityMap($this->instanceId))
                            ->mapContact($contactId, (int) $osTicket->getOwnerId(), $email);
                    }
                }
            } catch (\Throwable $e) {
                $logger->warning('Contact lookup failed for ' . $email . ': ' . $e->getMessage(),
                    array('category' => 'mapping', 'osticket_ticket_id' => $osTicket->getId()));
            }
        }

        if (!$companyId) {
            throw new ApiException('No ConnectWise company resolved and no default company configured.');
        }

        $title = $this->clip($this->osticketSubject($osTicket), 250);
        $body  = $this->osticketDescription($osTicket);

        $fields = array(
            'companyID'   => $companyId,
            'title'       => $title !== '' ? $title : 'osTicket #' . $osTicket->getNumber(),
            'description' => $this->clip($body, 8000),
            'source'      => null, // optional; left to ConnectWise default
        );

        if ($contactId)             { $fields['contactID'] = $contactId; }
        if ($defaults['queue_id'])  { $fields['queueID'] = $defaults['queue_id']; }
        if ($defaults['priority'])  { $fields['priority'] = $defaults['priority']; }
        if ($defaults['status'])    { $fields['status'] = $defaults['status']; }
        if ($defaults['ticket_type']) { $fields['ticketType'] = $defaults['ticket_type']; }
        // Required by some tenants (e.g. Issue/Sub-Issue marked * on their form).
        if (!empty($defaults['issue_type']))     { $fields['issueType'] = $defaults['issue_type']; }
        if (!empty($defaults['sub_issue_type'])) { $fields['subIssueType'] = $defaults['sub_issue_type']; }

        // Map osTicket priority onto ConnectWise priority when no fixed default set.
        if (empty($fields['priority'])) {
            $fields['priority'] = $this->mapPriority($osTicket);
        }
        // ConnectWise requires a status on create; fall back to "New" (1).
        if (empty($fields['status'])) {
            $fields['status'] = 1;
        }
        // Identity masking: NO "Synced from osTicket" trailer — the linkage
        // lives in the mapping table; the client sees a clean description.

        // Drop nulls ConnectWise would reject.
        return array_filter($fields, function ($v) { return $v !== null; });
    }

    /**
     * Map an osTicket priority to a sensible ConnectWise priority picklist value.
     * ConnectWise defaults: 1=High,2=Medium,3=Low,4=Critical (varies per tenant).
     * Admins can override globally via default_priority.
     */
    public function mapPriority(\Ticket $osTicket): int
    {
        $p = '';
        if (method_exists($osTicket, 'getPriority')) {
            $prio = $osTicket->getPriority();
            if (is_object($prio) && method_exists($prio, 'getDesc')) {
                $p = strtolower((string) $prio->getDesc());
            } elseif (is_string($prio)) {
                $p = strtolower($prio);
            }
        }
        if (strpos($p, 'emerg') !== false || strpos($p, 'critical') !== false) {
            return 4;
        }
        if (strpos($p, 'high') !== false) {
            return 1;
        }
        if (strpos($p, 'low') !== false) {
            return 3;
        }
        return 2; // medium / normal default
    }

    /**
     * Compute a stable hash of the osTicket-side state used to detect changes.
     */
    public function computeHash(\Ticket $osTicket): string
    {
        $parts = array(
            $this->osticketSubject($osTicket),
            method_exists($osTicket, 'getStatusId') ? $osTicket->getStatusId() : '',
            method_exists($osTicket, 'isClosed') ? (int) $osTicket->isClosed() : '',
        );
        return hash('sha256', implode('|', $parts));
    }

    /* ------------------------------------------------------------------ */
    /* osTicket accessors (defensive against version differences)         */
    /* ------------------------------------------------------------------ */

    private function osticketSubject(\Ticket $t): string
    {
        if (method_exists($t, 'getSubject')) {
            return (string) $t->getSubject();
        }
        return '';
    }

    private function osticketDescription(\Ticket $t): string
    {
        // Use the first/last message body if available.
        try {
            if (method_exists($t, 'getThread')) {
                $thread = $t->getThread();
                if ($thread && method_exists($thread, 'getOriginalMessage')) {
                    $msg = $thread->getOriginalMessage();
                    if ($msg && method_exists($msg, 'getBody')) {
                        return $this->plain($msg->getBody());
                    }
                }
            }
            if (method_exists($t, 'getLastMessage')) {
                $m = $t->getLastMessage();
                if ($m && method_exists($m, 'getBody')) {
                    return $this->plain($m->getBody());
                }
            }
        } catch (\Throwable $e) {
            // ignore — fall through to subject
        }
        return $this->osticketSubject($t);
    }

    private function osticketEmail(\Ticket $t): string
    {
        if (method_exists($t, 'getEmail')) {
            $e = $t->getEmail();
            return is_string($e) ? $e : (string) (is_object($e) && method_exists($e, '__toString') ? $e : '');
        }
        if (method_exists($t, 'getOwner')) {
            $o = $t->getOwner();
            if ($o && method_exists($o, 'getEmail')) {
                return (string) $o->getEmail();
            }
        }
        return '';
    }

    /**
     * Convert a ThreadEntryBody/HTML to plain text.
     *
     * @param mixed $body
     */
    private function plain($body): string
    {
        if (is_object($body) && method_exists($body, 'getClean')) {
            $body = $body->getClean();
        }
        $text = is_string($body) ? $body : (string) $body;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\n{3,}/', "\n\n", $text));
    }

    private function clip(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
}
