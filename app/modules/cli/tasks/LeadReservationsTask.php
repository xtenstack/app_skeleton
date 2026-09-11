<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli\Tasks;

/**
 * Usage: ./run lead-reservations sweep
 *
 * REQ-223 -- Human-Agent-Lead-Reservation-Policy-v1.0-FINAL.md Sec 5:
 * day-75 expiry warning, day-90 automatic release back to the shared
 * pool. Same placement and raw-SQL-against-abn_lookup pattern as
 * CampaignSendTask -- marketing-module's own models (LeadReservation,
 * Users) aren't autoloaded from a CLI task context, only from inside
 * the plugin module's own backend request lifecycle (see that file's
 * docblock for the underlying reason). Wired into the existing
 * cron_jobs/cronRunner mechanism the same way CampaignSendTask/
 * CampaignActivateTask are -- add a cron_jobs row via the backend Cron
 * admin screen (name/task = 'lead-reservations', task_action = 'sweep',
 * frequency = '+1 day', enabled = true) after this deploys; no existing
 * migration seeds a cron_jobs row for the other two tasks either, so
 * this follows the same manual-registration practice rather than
 * introducing a new one.
 */
class LeadReservationsTask extends \Phalcon\Cli\Task
{
    public function mainAction(): void
    {
        echo 'Usage: ./run lead-reservations sweep' . PHP_EOL;
    }

    public function sweepAction(): void
    {
        $db = $this->db;

        $warned = $this->sendExpiryWarnings($db);
        $expired = $this->expireOverdue($db);

        echo "{$warned} warning(s) sent, {$expired} reservation(s) expired." . PHP_EOL;
    }

    /**
     * Day-75 notice: any active reservation within 15 days of its own
     * expires_at that hasn't already been warned. warning_sent_at
     * guards against re-notifying on every subsequent daily run.
     */
    private function sendExpiryWarnings(\Phalcon\Db\Adapter\AdapterInterface $db): int
    {
        $rows = $db->fetchAll(
            "SELECT lr.id, lr.abn, lr.expires_at, u.email
             FROM abn_lookup.lead_reservations lr
             JOIN users u ON u.id = lr.reserved_by_user_id
             WHERE lr.status = 'active'
               AND lr.warning_sent_at IS NULL
               AND lr.expires_at <= now() + interval '15 days'",
            \Phalcon\Db\Enum::FETCH_ASSOC
        );

        $mailer = new \App_skeleton\Mailer();
        $mailer->setDI($this->getDI());
        $count = 0;

        foreach ($rows as $row) {
            $expires = date('j M Y', strtotime((string) $row['expires_at']));
            $subject = "Lead reservation expiring soon -- ABN {$row['abn']}";
            $body    = "Your reservation on ABN {$row['abn']} expires on {$expires} (90-day working window). "
                . "Log substantive activity in the Lead Reservations screen before then, or it will automatically "
                . 'release back to the shared pool.';

            $mailer->send((string) $row['email'], $subject, $body);

            $db->execute(
                'UPDATE abn_lookup.lead_reservations SET warning_sent_at = :now WHERE id = :id',
                ['now' => date('Y-m-d H:i:s'), 'id' => $row['id']]
            );

            $count++;
        }

        return $count;
    }

    /** Day-90 hard boundary -- the only automatic release this system performs. */
    private function expireOverdue(\Phalcon\Db\Adapter\AdapterInterface $db): int
    {
        $db->execute(
            "UPDATE abn_lookup.lead_reservations
             SET status = 'expired', released_at = now(), released_reason = '90-day window expired'
             WHERE status = 'active' AND expires_at <= now()"
        );

        return $db->affectedRows();
    }
}
