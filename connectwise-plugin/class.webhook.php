<?php
/**
 * ConnectWise Integration — webhook (callback) service. FUTURE-READY STUB.
 *
 * ConnectWise PSA can push change notifications ("callbacks": a POST of
 * {FromUrl, CompanyId, MemberId, Action, ID, Entity, Metadata}) to a
 * registered URL. This service provides the receipt log, shared-secret
 * verification and a processing hook so a callback endpoint can be added
 * without touching the sync engine.
 *
 * CURRENT BEHAVIOUR: no HTTP endpoint ships yet — POLLING REMAINS THE
 * AUTHORITATIVE SYNC TRIGGER. process() only nudges the incremental cursor
 * work forward by recording the event; it never mutates tickets directly,
 * so a replayed or forged callback can at worst cause one extra (idempotent)
 * poll.
 *
 * @package ConnectWise Integration
 */

namespace ConnectWise;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * Receipt log + verification + processing hook for ConnectWise callbacks.
 */
class WebhookService
{
    /** @var Logger */ private $logger;
    /** @var int */    private $instanceId;

    public function __construct(Logger $logger, int $instanceId = 1)
    {
        $this->logger = $logger;
        $this->instanceId = max(1, $instanceId);
    }

    /**
     * Persist a received callback. Always log first — verification and
     * processing outcomes are recorded against this row.
     *
     * @param string $eventType e.g. "added", "updated", "deleted".
     * @param string $entity    e.g. "ticket", "company", "contact".
     * @param int    $entityId  ConnectWise record id.
     * @param string $payload   Raw request body (stored verbatim).
     * @param string $signature Shared-secret token presented by the caller.
     * @return int New webhook_log row id (0 on failure).
     */
    public function record(string $eventType, string $entity, int $entityId, string $payload, string $signature = ''): int
    {
        $prefix = Installer::prefix();
        $ok = db_query(
            "INSERT INTO `{$prefix}connectwise_webhook_log` "
            . '(instance_id, event_type, entity, entity_id, payload, signature, status, received_at) VALUES ('
            . (int) $this->instanceId . ','
            . db_input(mb_substr($eventType, 0, 64)) . ','
            . db_input(mb_substr($entity, 0, 64)) . ','
            . (int) $entityId . ','
            . db_input($payload) . ','
            . db_input(mb_substr($signature, 0, 191)) . ","
            . "'received', NOW())",
            false
        );
        return $ok ? (int) db_insert_id() : 0;
    }

    /**
     * Constant-time comparison of the caller-presented token against the
     * configured shared secret. ConnectWise callbacks carry no HMAC signature
     * natively — the standard hardening is a secret token in the callback URL.
     *
     * @param string $presented Token from the request.
     * @param string $secret    Configured shared secret ('' = not configured).
     */
    public function verifySharedSecret(string $presented, string $secret): bool
    {
        if ($secret === '') {
            return false; // never accept callbacks without a configured secret
        }
        return hash_equals($secret, $presented);
    }

    /**
     * Mark a logged callback's outcome.
     */
    public function markOutcome(int $id, string $status, string $error = ''): void
    {
        if (!in_array($status, array('processed', 'failed', 'ignored'), true)) {
            return;
        }
        $prefix = Installer::prefix();
        db_query(
            "UPDATE `{$prefix}connectwise_webhook_log` SET status=" . db_input($status)
            . ', error=' . ($error === '' ? 'NULL' : db_input(mb_substr($error, 0, 2000)))
            . ', processed_at=NOW() WHERE id=' . (int) $id
            . ' AND instance_id=' . (int) $this->instanceId,
            false
        );
    }

    /**
     * Unprocessed callbacks, oldest first (for a future queue-driven worker).
     *
     * @return array<int,array<string,mixed>>
     */
    public function pending(int $limit = 25): array
    {
        $prefix = Installer::prefix();
        $out = array();
        $res = db_query(
            "SELECT * FROM `{$prefix}connectwise_webhook_log` "
            . 'WHERE instance_id=' . (int) $this->instanceId . " AND status='received' "
            . 'ORDER BY id ASC LIMIT ' . max(1, min(500, $limit)),
            false
        );
        while ($res && ($row = db_fetch_array($res))) {
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Processing hook. STUB: records the event as 'ignored' — the scheduled
     * incremental sync (cursor-based) picks the change up on its next tick,
     * which keeps callback handling idempotent and forgery-safe. A future
     * phase can upgrade this to enqueue a targeted inbound job for
     * ticket-entity events instead.
     *
     * @param array $row A connectwise_webhook_log row.
     */
    public function process(array $row): void
    {
        $this->markOutcome((int) ($row['id'] ?? 0), 'ignored');
        $this->logger->debug(
            'Webhook received (' . ($row['entity'] ?? '?') . ' #' . ($row['entity_id'] ?? 0)
            . ' ' . ($row['event_type'] ?? '?') . ') — polling remains authoritative',
            array('category' => 'webhook')
        );
    }

    /**
     * Retention: delete webhook rows older than N days (called by the
     * scheduler alongside log pruning).
     */
    public function prune(int $days): void
    {
        $prefix = Installer::prefix();
        db_query(
            "DELETE FROM `{$prefix}connectwise_webhook_log` "
            . 'WHERE received_at < (NOW() - INTERVAL ' . max(1, $days) . ' DAY)',
            false
        );
    }
}
