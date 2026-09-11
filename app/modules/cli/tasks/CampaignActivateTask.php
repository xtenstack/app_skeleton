<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli\Tasks;

/**
 * Usage: ./run campaign-activate run
 *
 * Counterpart to CampaignSendTask::sendAllAction() on the activation
 * side of the staged ANZSIC rollout (2026-09-11, Travis's call to
 * compress the original division-by-division schedule into daily
 * tiers): flips a campaign from 'planning' to 'active' once its own
 * scheduled_activation_date has arrived, so the smallest-divisions-first
 * staging happens on its own via cron rather than needing someone to run
 * an UPDATE by hand each day. Only ever moves 'planning' -> 'active' --
 * never touches a 'paused' or 'closed' campaign, and never touches
 * scheduled_activation_date itself (set once, at build time, by hand).
 */
class CampaignActivateTask extends \Phalcon\Cli\Task
{
    public function mainAction(): void
    {
        echo 'Usage: ./run campaign-activate run' . PHP_EOL;
    }

    public function runAction(): void
    {
        $due = $this->db->fetchAll(
            "SELECT campaign_code FROM abn_lookup.campaigns
             WHERE status = 'planning'
               AND scheduled_activation_date IS NOT NULL
               AND scheduled_activation_date <= CURRENT_DATE
             ORDER BY campaign_code",
            \Phalcon\Db\Enum::FETCH_ASSOC
        );

        if (!$due) {
            echo 'No campaigns due for activation.' . PHP_EOL;

            return;
        }

        foreach ($due as $row) {
            $code = $row['campaign_code'];

            $this->db->execute(
                "UPDATE abn_lookup.campaigns SET status = 'active' WHERE campaign_code = :code",
                ['code' => $code]
            );

            echo "  ACTIVATED {$code}" . PHP_EOL;
        }
    }
}
