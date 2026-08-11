<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli\Tasks;

/**
 * Usage: ./run audit archive
 *
 * Moves old audit_log rows into audit_log_archive rather than deleting them:
 * auth events (login/logout/login_failed) older than 1 day, data-change
 * events (insert/update/delete) older than 1 year. Safe to run daily via
 * cron — the annual rule just no-ops on days nothing has crossed the
 * threshold. Not scheduled automatically; add your own cron entry, e.g.:
 *
 *   0 2 * * * cd /opt/local/www/apache2/html/app_skeleton && ./run audit archive >> logs/audit-archive.log 2>&1
 */
class AuditTask extends \Phalcon\Cli\Task
{
    public function mainAction()
    {
        echo 'Usage: ./run audit archive' . PHP_EOL;
    }

    public function archiveAction()
    {
        $this->archive("entity_type = 'auth'", '-1 day', 'auth events (older than 1 day)');
        $this->archive("entity_type != 'auth'", '-1 year', 'data changes (older than 1 year)');

        echo 'Audit archival complete.' . PHP_EOL;
    }

    private function archive(string $condition, string $cutoffModifier, string $label): void
    {
        $db     = $this->db;
        $cutoff = date('Y-m-d H:i:s', strtotime($cutoffModifier));

        $row   = $db->fetchOne(
            "SELECT COUNT(*) AS c FROM audit_log WHERE {$condition} AND created_at < :cutoff",
            null,
            ['cutoff' => $cutoff]
        );
        $count = (int) ($row['c'] ?? 0);

        if ($count === 0) {
            echo "  {$label}: nothing to archive" . PHP_EOL;

            return;
        }

        $db->begin();

        $db->execute(
            "INSERT INTO audit_log_archive (id, entity_type, entity_id, action, actor_user_id, old_values, new_values, created_at)
             SELECT id, entity_type, entity_id, action, actor_user_id, old_values, new_values, created_at
             FROM audit_log WHERE {$condition} AND created_at < :cutoff",
            ['cutoff' => $cutoff]
        );

        $db->execute(
            "DELETE FROM audit_log WHERE {$condition} AND created_at < :cutoff",
            ['cutoff' => $cutoff]
        );

        $db->commit();

        echo "  {$label}: archived {$count} row(s)" . PHP_EOL;
    }
}
