<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Project audit Fix Kit (2026-08-13, Tier 1) — direct coverage for
 * CronController, which got real bulk-action logic (bulkAction()'s
 * "with selected" bulk enable/disable) via the list-view convention
 * rollout (1eb5b5b) but had zero automated coverage before this. Covers
 * create/update, bulk enable/disable, and runNowAction's manual-mode
 * guard plus an actual manual run against a self-contained fixture job
 * (an unknown task/task_action, deliberately — this exercises
 * CronRunner's own error path rather than depending on what SeedTask's
 * real 'audit'/'backup' jobs happen to do, and keeps this test's
 * assertions scoped to a job it created and can account for, not
 * whatever else is due in a given environment).
 * Real HTTP against the real running stack — see HttpClient's own
 * docblock.
 */
final class CronControllerTest extends TestCase
{
    private static string $adminEmail;
    private static string $adminPassword = 'PhpunitTest123!';
    private static ?string $originalCronMode = null;

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        self::$adminEmail = 'phpunit-cron-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $admin                = new \Users();
        $admin->email         = self::$adminEmail;
        $admin->password_hash = password_hash(self::$adminPassword, PASSWORD_DEFAULT);
        $admin->first_name    = 'PHPUnit';
        $admin->last_name     = 'CronControllerTest';
        $admin->role_id       = 1; // admin
        $admin->is_active     = 1;

        self::assertTrue($admin->save(), 'fixture admin failed to save: ' . implode('; ', $admin->getMessages()));

        $existing = \Settings::findFirst(['conditions' => "setting_key = 'cron_mode'"]);
        self::$originalCronMode = $existing?->setting_value;
    }

    public static function tearDownAfterClass(): void
    {
        foreach (\CronJobs::find(['conditions' => "name LIKE 'phpunit_cron_fixture_%'"]) as $leftover) {
            \CronRunLog::find(['conditions' => 'cron_job_id = :id:', 'bind' => ['id' => $leftover->id]])->delete();
            $leftover->delete();
        }

        $cronModeSetting = \Settings::findFirst(['conditions' => "setting_key = 'cron_mode'"]);

        if ($cronModeSetting && self::$originalCronMode !== null) {
            $cronModeSetting->setting_value = self::$originalCronMode;
            $cronModeSetting->save();
        }

        $admin = \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => self::$adminEmail]]);
        $admin?->softDelete();
    }

    private function loggedInClient(): HttpClient
    {
        $client    = new HttpClient();
        $loginPage = $client->get('/backend/session');
        $csrf      = HttpClient::extractCsrf($loginPage['body']);

        $response = $client->post('/backend/session/login', [
            'email'      => self::$adminEmail,
            'password'   => self::$adminPassword,
            $csrf['key'] => $csrf['token'],
        ]);

        $this->assertStringNotContainsString(
            'Invalid email or password',
            $response['body'],
            'fixture admin failed to log in — can\'t test the controller without a real authenticated session'
        );

        return $client;
    }

    private function setCronMode(string $mode): void
    {
        $setting = \Settings::findFirst(['conditions' => "setting_key = 'cron_mode'"]);

        if (!$setting) {
            $setting              = new \Settings();
            $setting->setting_key = 'cron_mode';
        }

        $setting->setting_value = $mode;
        $setting->save();
    }

    public function testAdminCanCreateACronJob(): void
    {
        $client = $this->loggedInClient();

        $newPage = $client->get('/backend/cron/new');
        $csrf    = HttpClient::extractCsrf($newPage['body']);

        $name = 'phpunit_cron_fixture_' . bin2hex(random_bytes(4));

        $client->post('/backend/cron/create', [
            'name'        => $name,
            'task'        => 'phpunit_fixture_task',
            'task_action' => 'phpunit_fixture_action',
            'frequency'   => '+1 day',
            'enabled'     => '1',
            $csrf['key']  => $csrf['token'],
        ]);

        $saved = \CronJobs::findFirst(['conditions' => 'name = :name:', 'bind' => ['name' => $name]]);

        $this->assertNotNull($saved, 'cron job was not created');
        $this->assertSame('phpunit_fixture_task', $saved->task);
        $this->assertSame(1, (int) $saved->enabled);
    }

    public function testAdminCanUpdateACronJob(): void
    {
        $client = $this->loggedInClient();

        $job              = new \CronJobs();
        $job->name        = 'phpunit_cron_fixture_' . bin2hex(random_bytes(4));
        $job->task        = 'phpunit_fixture_task';
        $job->task_action = 'phpunit_fixture_action';
        $job->frequency   = '+1 day';
        $job->enabled     = 1;
        $job->save();

        $editPage = $client->get('/backend/cron/edit/' . $job->id);
        $csrf     = HttpClient::extractCsrf($editPage['body']);

        $client->post('/backend/cron/update/' . $job->id, [
            'name'        => $job->name,
            'task'        => $job->task,
            'task_action' => $job->task_action,
            'frequency'   => '+2 days',
            // enabled deliberately omitted -- form checkbox unchecked
            $csrf['key']  => $csrf['token'],
        ]);

        $job->refresh();

        $this->assertSame('+2 days', $job->frequency, 'updateAction() did not update frequency');
        $this->assertSame(0, (int) $job->enabled, 'updateAction() did not disable the job when the enabled checkbox was omitted');
    }

    public function testBulkActionEnablesAndDisablesSelectedJobs(): void
    {
        $client = $this->loggedInClient();

        $jobToEnable          = new \CronJobs();
        $jobToEnable->name    = 'phpunit_cron_fixture_' . bin2hex(random_bytes(4));
        $jobToEnable->task    = 'phpunit_fixture_task';
        $jobToEnable->task_action = 'phpunit_fixture_action';
        $jobToEnable->frequency  = '+1 day';
        $jobToEnable->enabled = 0;
        $jobToEnable->save();

        $jobToLeaveAlone          = new \CronJobs();
        $jobToLeaveAlone->name    = 'phpunit_cron_fixture_' . bin2hex(random_bytes(4));
        $jobToLeaveAlone->task    = 'phpunit_fixture_task';
        $jobToLeaveAlone->task_action = 'phpunit_fixture_action';
        $jobToLeaveAlone->frequency  = '+1 day';
        $jobToLeaveAlone->enabled = 0;
        $jobToLeaveAlone->save();

        $indexPage = $client->get('/backend/cron');
        $csrf      = HttpClient::extractCsrf($indexPage['body']);

        $client->post('/backend/cron/bulk', [
            'cron_job_ids' => [(string) $jobToEnable->id],
            'enabled'      => '1',
            $csrf['key']   => $csrf['token'],
        ]);

        $jobToEnable->refresh();
        $jobToLeaveAlone->refresh();

        $this->assertSame(1, (int) $jobToEnable->enabled, 'bulkAction() did not enable the selected job');
        $this->assertSame(0, (int) $jobToLeaveAlone->enabled, 'bulkAction() changed a job that was not selected');
    }

    public function testRunNowIsBlockedWhenCronModeIsAuto(): void
    {
        $client = $this->loggedInClient();
        $this->setCronMode('auto');

        $job              = new \CronJobs();
        $job->name        = 'phpunit_cron_fixture_' . bin2hex(random_bytes(4));
        $job->task        = 'phpunit_fixture_nonexistent_task';
        $job->task_action = 'doesNotExist';
        $job->frequency   = '+1 second';
        $job->enabled     = 1;
        $job->save();

        $client->get('/backend/cron/run-now');

        $job->refresh();
        $this->assertNull($job->last_run_at, 'runNowAction() ran a job even though cron_mode was auto');

        $this->setCronMode('manual');
    }

    public function testRunNowExecutesADueFixtureJobWhenCronModeIsManual(): void
    {
        $client = $this->loggedInClient();
        $this->setCronMode('manual');

        $job              = new \CronJobs();
        $job->name        = 'phpunit_cron_fixture_' . bin2hex(random_bytes(4));
        $job->task        = 'phpunit_fixture_nonexistent_task';
        $job->task_action = 'doesNotExist';
        $job->frequency   = '+1 second';
        $job->enabled     = 1;
        $job->save();

        $client->get('/backend/cron/run-now');

        $job->refresh();
        $this->assertNotNull($job->last_run_at, 'runNowAction() did not run the due fixture job in manual mode');
        $this->assertSame('error', $job->last_status, 'expected an "error" status for an unknown task/task_action target');

        $log = \CronRunLog::findFirst(['conditions' => 'cron_job_id = :id:', 'bind' => ['id' => $job->id]]);
        $this->assertNotNull($log, 'runNowAction() did not write a cron_run_log row for the fixture job');
    }
}
