<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli\Tasks;

/**
 * Usage: ./run campaign-send send <campaign_code> [dry-run] [limit]
 *
 * Sends a campaign's mail_template to its as-yet-uncontacted members, one
 * send per person, up to the campaign's daily_send_limit (or the given
 * limit override). Written against abn_lookup.* via raw SQL rather than
 * XtenMarketing's own models (Campaign/CampaignProspect/CampaignSend/
 * Unsubscribe) -- those bare classes are autoloaded only inside the
 * marketing plugin module's own Loader instance (see XtenMarketing\
 * Module::registerAutoloaders()), which app/config/loader.php's global
 * setDirectories() call does not include, so they're invisible to a CLI
 * task living in this module -- the same cross-module gap
 * UnsubscribesController hit (see that file's docblock).
 *
 * Deliberately conservative, all by design, not oversight:
 *  - campaigns.status must be 'active' (not 'planning'/'paused'/'closed')
 *    -- the human flips this switch once a list is actually trustworthy,
 *    so a freshly-scraped or freshly-imported campaign never sends by
 *    default (see 2026-08-27's Perth_IT_Businesses.xlsx finding for why
 *    that default matters).
 *  - campaigns.mail_template_id must be set -- no silent fallback template.
 *  - a member is only eligible once first_paragraph is non-empty. That
 *    field carries the specific, verified fact Data-Restore-Audit-
 *    Outreach-Emails.md rule 2 calls "the whole game" -- a merge field is
 *    explicitly NOT personalisation per that doc, so this task refuses to
 *    fabricate one and refuses to send without it, however qualified a
 *    member otherwise looks. This is the main lever a human has over what
 *    actually goes out: write first_paragraph, the record becomes
 *    eligible; leave it blank, it never sends. (Migration 008, XTMK
 *    Session 1: this was `notes` until this session -- moved to its own
 *    column so `notes` is free for general use without silently changing
 *    what sends. Renamed from first_line to first_paragraph by migration
 *    016, matching the merge-field rename in the documented outreach copy
 *    since 2026-08-29 -- MAA-20260829-001.)
 *  - abn_lookup.unsubscribes is checked immediately before every single
 *    send, not once at the start of the run.
 *  - priority = 0 is a manual exclude -- ignored regardless of every other
 *    eligibility criterion above. priority 1-10 jumps a member to the
 *    front of the queue (ascending, 1 first) ahead of every unprioritized
 *    (NULL) member no matter how it scores; unprioritized members then
 *    fall back to the existing score-DESC order among themselves
 *    (migration 007, XTMK Session 1 -- added after a dry-run surfaced data
 *    quality that needs a human override, not pure score-based trust).
 *  - wired into cron as of 2026-09-11 (Travis's explicit call, after
 *    the Utilities canary's 20 real sends and this task's own ordering
 *    bug were both reviewed) -- sendAllAction() is what cron_jobs
 *    actually invokes, once daily, over every currently-'active'
 *    campaign at its own daily_send_limit. The status='active' gate
 *    below is what still keeps a freshly-built campaign from sending
 *    automatically -- CampaignActivateTask is the piece that flips it,
 *    on each campaign's own scheduled_activation_date, so the staged
 *    rollout (smallest divisions first) still happens without a human
 *    re-running SQL every day. sendAction() (single campaign, dry-run
 *    supported) stays the manual/review path for everything else --
 *    a new campaign, a re-run, or checking one division out of cycle.
 *  - candidate ordering ends with `abn ASC` specifically so ties (most
 *    of this population has no score at all -- see the ANZSIC campaign
 *    docs) resolve the same way every time a query runs. Without it,
 *    Postgres is free to return a different arbitrary subset of a tied
 *    group on each execution -- confirmed live 2026-09-11: a dry-run
 *    previewed 5 candidates, the real send moments later sent to 5
 *    entirely different ones from the same tied pool. Silently broke
 *    the "dry-run first, review, then send" safety practice this whole
 *    task is built around.
 */
class CampaignSendTask extends \Phalcon\Cli\Task
{
    public function mainAction(): void
    {
        echo 'Usage: ./run campaign-send send <campaign_code> [dry-run] [limit]' . PHP_EOL;
        echo '       ./run campaign-send send-all   -- every active campaign, its own daily_send_limit each (cron entry point)' . PHP_EOL;
    }

    public function sendAction($campaignCode = null, $mode = null, $limitArg = null): void
    {
        if (!$campaignCode) {
            echo 'Usage: ./run campaign-send send <campaign_code> [dry-run] [limit]' . PHP_EOL;

            return;
        }

        $this->sendOneCampaign($campaignCode, $mode === 'dry-run', $limitArg !== null ? (int) $limitArg : null, true);
    }

    /**
     * Cron entry point (CampaignActivateTask's counterpart on the
     * activation side) -- no dry-run, no limit override, every
     * currently-'active' campaign gets exactly one pass at its own
     * daily_send_limit. Real send failures inside one campaign don't
     * stop the loop -- sendOneCampaign() already handles its own
     * per-recipient failures the same way (logs, moves on), so a bad
     * template or a Resend outage on one campaign shouldn't also skip
     * every campaign after it in the loop.
     */
    public function sendAllAction(): void
    {
        $codes = array_column(
            $this->db->fetchAll(
                "SELECT campaign_code FROM abn_lookup.campaigns WHERE status = 'active' ORDER BY campaign_code",
                \Phalcon\Db\Enum::FETCH_ASSOC
            ),
            'campaign_code'
        );

        if (!$codes) {
            echo 'No active campaigns.' . PHP_EOL;

            return;
        }

        foreach ($codes as $code) {
            echo "=== {$code} ===" . PHP_EOL;

            try {
                // Not verbose -- see sendOneCampaign()'s docblock note.
                // cron_run_log.output is one text column per run; a
                // per-recipient line for every send, times ~768/day once
                // the full rollout is active, would make that column
                // unusably large within days. A summary count is what
                // the cron log is actually for -- the per-recipient
                // detail still exists, in campaign_sends, queryable per
                // campaign from its own admin screen.
                $this->sendOneCampaign($code, false, null, false);
            } catch (\Throwable $e) {
                echo "  ERROR in {$code}: {$e->getMessage()} -- continuing to next campaign" . PHP_EOL;
            }
        }
    }

    /**
     * $verbose controls whether every recipient gets its own echo line
     * ("SENT to X <email>", "WOULD SEND to X: <full body>", "SKIP X --
     * unsubscribed") or just a one-line summary count at the end. The
     * manual/dry-run path (sendAction()) always wants the detail -- it's
     * a human reviewing one campaign. sendAllAction() (the cron path)
     * wants the summary -- see its own call site for why.
     */
    private function sendOneCampaign(string $campaignCode, bool $dryRun, ?int $limitOverride, bool $verbose): void
    {
        $db = $this->db;

        $campaign = $db->fetchOne(
            'SELECT campaign_code, title, status, mail_template_id, daily_send_limit
             FROM abn_lookup.campaigns WHERE campaign_code = :code',
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['code' => $campaignCode]
        );

        if (!$campaign) {
            echo "No such campaign: {$campaignCode}" . PHP_EOL;

            return;
        }

        if ($campaign['status'] !== 'active') {
            echo "Campaign {$campaignCode} is '{$campaign['status']}', not 'active' -- refusing to send." . PHP_EOL;
            echo "Flip it with: UPDATE abn_lookup.campaigns SET status = 'active' WHERE campaign_code = '{$campaignCode}';" . PHP_EOL;

            return;
        }

        if (!$campaign['mail_template_id']) {
            echo "Campaign {$campaignCode} has no mail_template_id set -- nothing to send." . PHP_EOL;

            return;
        }

        $template = $db->fetchOne(
            'SELECT id, subject, body FROM abn_lookup.mail_templates WHERE id = :id',
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['id' => $campaign['mail_template_id']]
        );

        $limit = $limitOverride ?? (int) $campaign['daily_send_limit'];

        $candidates = $db->fetchAll(
            "SELECT abn, main_ent_name, first_paragraph, best_contact_kind, best_contact_value, best_contact_person_name
             FROM abn_lookup.v_campaign_prospects
             WHERE campaign_code = :code
               AND outreach_status = 'not contacted'
               AND first_paragraph IS NOT NULL AND btrim(first_paragraph) != ''
               AND best_contact_kind = 'email'
               AND best_contact_value IS NOT NULL
               AND priority IS DISTINCT FROM 0
               AND NOT EXISTS (
                   SELECT 1 FROM abn_lookup.campaign_sends cs
                   WHERE cs.campaign_code = :code2 AND cs.abn = v_campaign_prospects.abn
                     AND cs.status IN ('pending', 'sent')
               )
             ORDER BY (priority IS NULL) ASC, priority ASC, score DESC NULLS LAST, abn ASC
             LIMIT :lim",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['code' => $campaignCode, 'code2' => $campaignCode, 'lim' => $limit]
        );

        if (!$candidates) {
            echo "No eligible members in {$campaignCode} -- need: not contacted, priority not 0, first_paragraph set on campaign_members, and a real email." . PHP_EOL;

            return;
        }

        if ($verbose) {
            echo count($candidates) . ($dryRun ? ' candidate(s) [dry-run, nothing will send]:' : ' to send:') . PHP_EOL;
        }

        $sentCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        foreach ($candidates as $row) {
            $email = $row['best_contact_value'];

            $unsubscribed = $db->fetchOne(
                'SELECT 1 FROM abn_lookup.unsubscribes WHERE email = :email',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['email' => $email]
            );

            if ($unsubscribed) {
                $skippedCount++;

                if ($verbose) {
                    echo "  SKIP {$row['main_ent_name']} <{$email}> -- unsubscribed" . PHP_EOL;
                }

                continue;
            }

            // REQ-223, Charter Sec 6.5 "No Cannibalisation" -- XTen's
            // formal commitment to never let an automated send compete
            // with an active Charter Agent lead reservation. Checked
            // per-row immediately before send, same as the unsubscribe
            // check above, not once at the top of the run -- a
            // reservation claimed mid-run must still block this send.
            $reserved = $db->fetchOne(
                "SELECT 1 FROM abn_lookup.lead_reservations WHERE abn = :abn AND status = 'active'",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['abn' => $row['abn']]
            );

            if ($reserved) {
                $skippedCount++;

                if ($verbose) {
                    echo "  SKIP {$row['main_ent_name']} <{$email}> -- reserved by a Charter Agent" . PHP_EOL;
                }

                continue;
            }

            $name    = $row['best_contact_person_name'] ?: 'there';
            $subject = $template['subject'];

            // Generated before $body so {{unsubscribe_url}} (part of the
            // compliant footer, MAA-20260908-008 item 2) can be merged in
            // the same pass as {{name}}/{{first_paragraph}} — including in
            // dry-run mode, so the preview matches what would actually
            // send. Only persisted to campaign_sends below, in the real
            // (non-dry-run) path — a dry-run token is never written down
            // anywhere, purely a preview value.
            $token          = bin2hex(random_bytes(24));
            $unsubscribeUrl = 'https://xtmk.xten.au/marketing/unsubscribe/submit?token=' . $token;

            // {{product_name}} drives the ANZSIC dual-wording design
            // (MAA-20260911-004): the shared body is identical between a
            // campaign's HC- and DRA- wording, this merge field is the one
            // thing that differs. Derived from the campaign_code prefix
            // rather than a new campaigns column -- the prefix is already
            // the authoritative wording marker every other part of this
            // design keys off (mail_templates.subject, population
            // partitioning, the 3-month swap).
            $productName = match (true) {
                str_starts_with($campaignCode, 'HC-')  => 'Health Check',
                str_starts_with($campaignCode, 'DRA-') => 'Data Restore Audit',
                default                                 => '',
            };

            $body = str_replace(
                ['{{name}}', '{{first_paragraph}}', '{{business}}', '{{product_name}}', '{{unsubscribe_url}}'],
                [$name, trim($row['first_paragraph']), $row['main_ent_name'], $productName, $unsubscribeUrl],
                $template['body']
            );

            if ($dryRun) {
                if ($verbose) {
                    echo "  WOULD SEND to {$row['main_ent_name']} <{$email}>:" . PHP_EOL;
                    echo "    Subject: {$subject}" . PHP_EOL;
                    echo '    ---' . PHP_EOL;

                    foreach (explode("\n", $body) as $line) {
                        echo "    {$line}" . PHP_EOL;
                    }

                    echo '    ---' . PHP_EOL;
                }

                continue;
            }

            $db->execute(
                'INSERT INTO abn_lookup.campaign_sends (campaign_code, abn, contact_email, mail_template_id, unsubscribe_token, status)
                 VALUES (:campaign_code, :abn, :email, :template_id, :token, :status)',
                [
                    'campaign_code' => $campaignCode,
                    'abn'           => $row['abn'],
                    'email'         => $email,
                    'template_id'   => $template['id'],
                    'token'         => $token,
                    'status'        => 'pending',
                ]
            );

            $mailer = new \App_skeleton\Mailer();
            $mailer->setDI($this->getDI());
            $sent = $mailer->send($email, $subject, $body, $unsubscribeUrl);

            // Mailer::send() returns the Resend message id (string) on a
            // real send, plain `true` on the two shortcut paths where
            // nothing was actually sent to Resend (.invalid test
            // addresses, missing API key), `false` on a real failure.
            // Stored so WebhookController::resendAction() can correlate
            // a later bounce/complaint event back to this exact row.
            $providerMessageId = is_string($sent) ? $sent : null;

            $db->execute(
                'UPDATE abn_lookup.campaign_sends SET status = :status, sent_at = :sent_at, provider_message_id = :provider_message_id WHERE unsubscribe_token = :token',
                [
                    'status'              => $sent ? 'sent' : 'failed',
                    'sent_at'             => $sent ? date('Y-m-d H:i:s') : null,
                    'provider_message_id' => $providerMessageId,
                    'token'               => $token,
                ]
            );

            if ($sent) {
                $db->execute(
                    "UPDATE abn_lookup.campaign_members SET outreach_status = 'sent', status_changed = :now
                     WHERE campaign_code = :campaign_code AND abn = :abn",
                    ['now' => date('Y-m-d H:i:s'), 'campaign_code' => $campaignCode, 'abn' => $row['abn']]
                );

                $sentCount++;
            } else {
                $failedCount++;
            }

            if ($verbose) {
                echo '  ' . ($sent ? 'SENT' : 'FAILED') . " to {$row['main_ent_name']} <{$email}>" . PHP_EOL;
            }
        }

        if (!$verbose) {
            echo "{$campaignCode}: {$sentCount} sent, {$failedCount} failed, {$skippedCount} skipped (unsubscribed)" . PHP_EOL;
        }
    }
}
