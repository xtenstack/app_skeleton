<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli\Tasks;

/**
 * Usage: ./run seed run
 *
 * Idempotent — safe to run repeatedly. Only inserts rows that don't already
 * exist (matched by the same unique key the schema enforces), so it won't
 * duplicate data or clobber anything you've added by hand.
 */
class SeedTask extends \Phalcon\Cli\Task
{
    public function mainAction()
    {
        echo "Usage: ./run seed run" . PHP_EOL;
    }

    public function runAction()
    {
        $this->seedRoles();
        $this->seedSettings();
        $this->seedCronJobs();

        echo "Seeding complete." . PHP_EOL;
    }

    private function seedRoles(): void
    {
        foreach (['admin', 'member', 'operator', 'agent'] as $name) {
            $exists = \Roles::findFirst([
                'conditions' => 'name = :name:',
                'bind'       => ['name' => $name],
            ]);

            if ($exists) {
                echo "  roles: '{$name}' already exists, skipping" . PHP_EOL;

                continue;
            }

            $role       = new \Roles();
            $role->name = $name;

            if ($role->save()) {
                echo "  roles: created '{$name}'" . PHP_EOL;
            } else {
                echo "  roles: FAILED to create '{$name}': " . implode(', ', $role->getMessages()) . PHP_EOL;
            }
        }
    }

    private function seedSettings(): void
    {
        $defaults = [
            'site_name'            => 'App Skeleton',
            'cron_mode'            => 'manual',
            'mail_from'            => 'no-reply@app-skeleton.local',
            'mail_reply_to'        => 'stack@xten.au',
            'debug_mode'           => '0',
            'default_theme_palette' => 'blue',
            'default_theme_mode'    => 'auto',
        ];

        foreach ($defaults as $key => $value) {
            $exists = \Settings::findFirst([
                'conditions' => 'setting_key = :key:',
                'bind'       => ['key' => $key],
            ]);

            if ($exists) {
                echo "  settings: '{$key}' already exists, skipping" . PHP_EOL;

                continue;
            }

            $setting                = new \Settings();
            $setting->setting_key   = $key;
            $setting->setting_value = $value;

            if ($setting->save()) {
                echo "  settings: created '{$key}'" . PHP_EOL;
            } else {
                echo "  settings: FAILED to create '{$key}': " . implode(', ', $setting->getMessages()) . PHP_EOL;
            }
        }
    }

    private function seedCronJobs(): void
    {
        $defaults = [
            ['name' => 'Archive audit log', 'task' => 'audit', 'task_action' => 'archive', 'frequency' => '+1 day'],
            ['name' => 'Database backup', 'task' => 'backup', 'task_action' => 'run', 'frequency' => '+1 day'],
        ];

        foreach ($defaults as $data) {
            $exists = \CronJobs::findFirst([
                'conditions' => 'task = :task: AND task_action = :task_action:',
                'bind'       => ['task' => $data['task'], 'task_action' => $data['task_action']],
            ]);

            if ($exists) {
                echo "  cron_jobs: '{$data['name']}' already exists, skipping" . PHP_EOL;

                continue;
            }

            $job              = new \CronJobs();
            $job->name        = $data['name'];
            $job->task        = $data['task'];
            $job->task_action = $data['task_action'];
            $job->frequency   = $data['frequency'];

            if ($job->save()) {
                echo "  cron_jobs: created '{$data['name']}'" . PHP_EOL;
            } else {
                echo "  cron_jobs: FAILED to create '{$data['name']}': " . implode(', ', $job->getMessages()) . PHP_EOL;
            }
        }
    }
}
