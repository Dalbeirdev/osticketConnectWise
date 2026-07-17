<?php
/**
 * ConnectWise Integration — identity map repository.
 *
 * Persistent links between ConnectWise records and their osTicket
 * counterparts, one table per identity type, scoped per client instance:
 *
 *   connectwise_company_map   Company -> Organization
 *   connectwise_contact_map   Contact -> User
 *   connectwise_member_map    Member  -> Staff (IDENTITY ONLY — the plugin
 *                             never auto-assigns ticket ownership from it)
 *
 * The sync services record links here as they resolve or create records
 * (Company/Contact/Resource sync phases); everything else does cheap indexed
 * lookups instead of re-resolving by name/email each time.
 *
 * All writes are idempotent upserts keyed on (instance_id, cw_*_id).
 *
 * @package ConnectWise Integration
 */

namespace ConnectWise;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * CRUD over the three identity map tables (prepared via db_input escaping).
 */
class IdentityMap
{
    /** @var int|null Client instance this repository is bound to (null = legacy #1). */
    private $instanceId;

    /**
     * @param int|null $instanceId Instance that owns links created/queried
     *                             through this repository.
     */
    public function __construct(?int $instanceId = null)
    {
        $this->instanceId = $instanceId;
    }

    private function scope(): int
    {
        return (int) ($this->instanceId ?: 1);
    }

    /* ----- Companies (ConnectWise Company -> osTicket Organization) ------- */

    /**
     * Record (or refresh) a Company -> Organization link.
     */
    public function mapCompany(int $cwCompanyId, int $orgId, string $companyName = ''): void
    {
        if (!$cwCompanyId || !$orgId) {
            return;
        }
        $this->upsert('connectwise_company_map', 'cw_company_id', $cwCompanyId, array(
            'osticket_org_id' => (int) $orgId,
            'company_name'    => mb_substr($companyName, 0, 191),
        ));
    }

    /** @return int|null osTicket organization id linked to a CW company. */
    public function orgIdForCompany(int $cwCompanyId): ?int
    {
        return $this->lookupInt('connectwise_company_map', 'cw_company_id', $cwCompanyId, 'osticket_org_id');
    }

    /* ----- Contacts (ConnectWise Contact -> osTicket User) ----------------- */

    /**
     * Record (or refresh) a Contact -> User link.
     */
    public function mapContact(int $cwContactId, int $userId, string $email = ''): void
    {
        if (!$cwContactId || !$userId) {
            return;
        }
        $this->upsert('connectwise_contact_map', 'cw_contact_id', $cwContactId, array(
            'osticket_user_id' => (int) $userId,
            'email'            => mb_substr(mb_strtolower(trim($email)), 0, 191),
        ));
    }

    /** @return int|null osTicket user id linked to a CW contact. */
    public function userIdForContact(int $cwContactId): ?int
    {
        return $this->lookupInt('connectwise_contact_map', 'cw_contact_id', $cwContactId, 'osticket_user_id');
    }

    /** @return int|null CW contact id linked to an osTicket user (reverse). */
    public function contactIdForUser(int $userId): ?int
    {
        if (!$userId) {
            return null;
        }
        $prefix = Installer::prefix();
        $res = db_query("SELECT cw_contact_id v FROM `{$prefix}connectwise_contact_map` "
            . 'WHERE instance_id=' . $this->scope() . ' AND osticket_user_id=' . (int) $userId . ' LIMIT 1');
        return ($res && ($r = db_fetch_array($res))) ? (int) $r['v'] : null;
    }

    /* ----- Members (ConnectWise Member -> osTicket Staff, identity only) --- */

    /**
     * Record (or refresh) a Member identity. Staff id may be null when no
     * osTicket agent shares the member's email — the identity row still
     * exists so names/emails resolve without an API call.
     */
    public function mapMember(int $cwMemberId, ?int $staffId, string $email = '', string $name = ''): void
    {
        if (!$cwMemberId) {
            return;
        }
        $this->upsert('connectwise_member_map', 'cw_member_id', $cwMemberId, array(
            'osticket_staff_id' => $staffId ? (int) $staffId : null,
            'email'             => mb_substr(mb_strtolower(trim($email)), 0, 191),
            'name'              => mb_substr($name, 0, 191),
        ));
    }

    /** @return int|null osTicket staff id linked to a CW member (may be null). */
    public function staffIdForMember(int $cwMemberId): ?int
    {
        return $this->lookupInt('connectwise_member_map', 'cw_member_id', $cwMemberId, 'osticket_staff_id');
    }

    /** @return int|null CW member id linked to an osTicket staff (reverse). */
    public function memberIdForStaff(int $staffId): ?int
    {
        if (!$staffId) {
            return null;
        }
        $prefix = Installer::prefix();
        $res = db_query("SELECT cw_member_id v FROM `{$prefix}connectwise_member_map` "
            . 'WHERE instance_id=' . $this->scope() . ' AND osticket_staff_id=' . (int) $staffId . ' LIMIT 1');
        return ($res && ($r = db_fetch_array($res))) ? (int) $r['v'] : null;
    }

    /* ----- Dashboard helpers ---------------------------------------------- */

    /**
     * @return array{companies:int,contacts:int,members:int} Linked-record
     *         counts for this instance (dashboard tiles).
     */
    public function counts(): array
    {
        return array(
            'companies' => $this->countRows('connectwise_company_map'),
            'contacts'  => $this->countRows('connectwise_contact_map'),
            'members'   => $this->countRows('connectwise_member_map'),
        );
    }

    /* ----- Internals -------------------------------------------------------- */

    /**
     * Idempotent upsert keyed on (instance_id, $keyCol).
     *
     * @param array<string,int|string|null> $fields Columns to set/update.
     */
    private function upsert(string $table, string $keyCol, int $keyVal, array $fields): void
    {
        $prefix = Installer::prefix();
        $cols = array(
            'instance_id' => (string) $this->scope(),
            $keyCol       => (string) $keyVal,
            'created'     => 'NOW()',
            'updated'     => 'NOW()',
        );
        $updates = array('`updated`=NOW()');
        foreach ($fields as $col => $val) {
            $cols[$col] = ($val === null) ? 'NULL' : db_input($val);
            $updates[] = "`$col`=VALUES(`$col`)";
        }
        $names  = implode(',', array_map(function ($c) { return "`$c`"; }, array_keys($cols)));
        $values = implode(',', array_values($cols));
        db_query(
            "INSERT INTO `{$prefix}{$table}` ($names) VALUES ($values) "
            . 'ON DUPLICATE KEY UPDATE ' . implode(',', $updates),
            false
        );
    }

    private function lookupInt(string $table, string $keyCol, int $keyVal, string $valueCol): ?int
    {
        if (!$keyVal) {
            return null;
        }
        $prefix = Installer::prefix();
        $res = db_query("SELECT `$valueCol` v FROM `{$prefix}{$table}` "
            . 'WHERE instance_id=' . $this->scope() . " AND `$keyCol`=" . (int) $keyVal . ' LIMIT 1');
        if ($res && ($r = db_fetch_array($res))) {
            return $r['v'] === null ? null : (int) $r['v'];
        }
        return null;
    }

    private function countRows(string $table): int
    {
        $prefix = Installer::prefix();
        $res = db_query("SELECT COUNT(*) c FROM `{$prefix}{$table}` WHERE instance_id=" . $this->scope());
        return ($res && ($r = db_fetch_array($res))) ? (int) $r['c'] : 0;
    }
}
