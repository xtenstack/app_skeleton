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
 *  - a member is only eligible once first_line is non-empty. That field
 *    carries the one-line, specific, verified fact Health-Check-Outreach-
 *    Emails.md rule 2 calls "the whole game" -- a merge field is
 *    explicitly NOT personalisation per that doc, so this task refuses to
 *    fabricate one and refuses to send without it, however qualified a
 *    member otherwise looks. This is the main lever a human has over what
 *    actually goes out: write first_line, the record becomes eligible;
 *    leave it blank, it never sends. (Migration 008, XTMK Session 1: this
 *    was `notes` until this session -- moved to its own column so `notes`
 *    is free for general use without silently changing what sends.)
 *  - abn_lookup.unsubscribes is checked immediately before every single
 *    send, not once at the start of the run.
 *  - priority = 0 is a manual exclude -- ignored regardless of every other
 *    eligibility criterion above. priority 1-10 jumps a member to the
 *    front of the queue (ascending, 1 first) ahead of every unprioritized
 *    (NULL) member no matter how it scores; unprioritized members then
 *    fall back to the existing score-DESC order among themselves
 *    (migration 007, XTMK Session 1 -- added after a dry-run surfaced data
 *    quality that needs a human override, not pure score-based trust).
 *  - never wired into cron -- run by hand (with dry-run first) until
 *    proven safe over real sends.
 */
class CampaignSendTask extends \Phalcon\Cli\Task
{
    public function mainAction(): void
    {
        echo 'Usage: ./run campaign-send send <campaign_code> [dry-run] [limit]' . PHP_EOL;
    }

    public function sendAction($campaignCode = null, $mode = null, $limitArg = null): void
    {
        if (!$campaignCode) {
            echo 'Usage: ./run campaign-send send <campaign_code> [dry-run] [limit]' . PHP_EOL;

            return;
        }

        $dryRun = ($mode === 'dry-run');
        $db     = $this->db;

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

        $limit = $limitArg !== null ? (int) $limitArg : (int) $campaign['daily_send_limit'];

        $candidates = $db->fetchAll(
            "SELECT abn, main_ent_name, first_line, best_contact_kind, best_contact_value, best_contact_person_name
             FROM abn_lookup.v_campaign_prospects
             WHERE campaign_code = :code
               AND outreach_status = 'not contacted'
               AND first_line IS NOT NULL AND btrim(first_line) != ''
               AND best_contact_kind = 'email'
               AND best_contact_value IS NOT NULL
               AND priority IS DISTINCT FROM 0
               AND NOT EXISTS (
                   SELECT 1 FROM abn_lookup.campaign_sends cs
                   WHERE cs.campaign_code = :code2 AND cs.abn = v_campaign_prospects.abn
                     AND cs.status IN ('pending', 'sent')
               )
             ORDER BY (priority IS NULL) ASC, priority ASC, score DESC NULLS LAST
             LIMIT :lim",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['code' => $campaignCode, 'code2' => $campaignCode, 'lim' => $limit]
        );

        if (!$candidates) {
            echo "No eligible members in {$campaignCode} -- need: not contacted, priority not 0, first_line set on campaign_members, and a real email." . PHP_EOL;

            return;
        }

        echo count($candidates) . ($dryRun ? ' candidate(s) [dry-run, nothing will send]:' : ' to send:') . PHP_EOL;

        foreach ($candidates as $row) {
            $email = $row['best_contact_value'];

            $unsubscribed = $db->fetchOne(
                'SELECT 1 FROM abn_lookup.unsubscribes WHERE email = :email',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['email' => $email]
            );

            if ($unsubscribed) {
                echo "  SKIP {$row['main_ent_name']} <{$email}> -- unsubscribed" . PHP_EOL;

                continue;
            }

            $name    = $row['best_contact_person_name'] ?: 'there';
            $subject = $template['subject'];
            $body    = str_replace(
                ['{{name}}', '{{first_line}}'],
                [$name, trim($row['first_line'])],
                $template['body']
            );

            if ($dryRun) {
                echo "  WOULD SEND to {$row['main_ent_name']} <{$email}>:" . PHP_EOL;
                echo "    Subject: {$subject}" . PHP_EOL;
                echo '    ---' . PHP_EOL;

                foreach (explode("\n", $body) as $line) {
                    echo "    {$line}" . PHP_EOL;
                }

                echo '    ---' . PHP_EOL;

                continue;
            }

            $token = bin2hex(random_bytes(24));

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

            $unsubscribeUrl = 'https://xtmk.xten.au/marketing/unsubscribe/submit?token=' . $token;

            $mailer = new \App_skeleton\Mailer();
            $mailer->setDI($this->getDI());
            $sent = $mailer->send($email, $subject, $body, $unsubscribeUrl);

            $db->execute(
                'UPDATE abn_lookup.campaign_sends SET status = :status, sent_at = :sent_at WHERE unsubscribe_token = :token',
                [
                    'status'  => $sent ? 'sent' : 'failed',
                    'sent_at' => $sent ? date('Y-m-d H:i:s') : null,
                    'token'   => $token,
                ]
            );

            if ($sent) {
                $db->execute(
                    "UPDATE abn_lookup.campaign_members SET outreach_status = 'sent', status_changed = :now
                     WHERE campaign_code = :campaign_code AND abn = :abn",
                    ['now' => date('Y-m-d H:i:s'), 'campaign_code' => $campaignCode, 'abn' => $row['abn']]
                );
            }

            echo '  ' . ($sent ? 'SENT' : 'FAILED') . " to {$row['main_ent_name']} <{$email}>" . PHP_EOL;
        }
    }
}
