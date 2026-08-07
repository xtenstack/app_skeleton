# User Guide

The reference for anyone who's just installed App Skeleton and wants to
understand what's already there before extending it. See also
[README.md](../README.md) for what this project is and how to run it,
and [INSTALL.md](INSTALL.md) for getting a fresh instance up.

- [Modules](#modules) — what a "module" means in this codebase, which
  ones ship with the base product, and how to build your own.
- [Cron](#cron) — how scheduled/background jobs work and how to add one.

## Modules

## Two kinds of module

### Core (application-defining) modules

`backend`, `api`, `cli`, and `frontend` — always on, wired directly into
`app/bootstrap_web.php`'s `$builtInModules` (and `bootstrap_cli.php` for
`cli`). They have no `module.json`, never appear in the admin
Configuration page's module list, and can't be disabled through the
UI. That's deliberate, not an oversight: disabling `backend`, for
example, would break the very Configuration page you'd use to disable
it. These are the app, not features bolted onto it.

| Module | What it is |
|---|---|
| `backend` | Server-rendered AdminLTE admin UI — staff/admin-facing. |
| `api` | JSON-only, session- or API-key-authenticated. |
| `cli` | `./run <task> <action>` — migrations, seeding, module management, and anything else scripted. |
| `frontend` | The public-facing side: the guest landing page and the non-admin member's own dashboard. Separate from `backend` on purpose — an admin's dashboard and a customer's dashboard are different products wearing the same login form. |

### Optional (plug-in) modules

Composer packages carrying a `module.json` manifest, discovered by
`App_skeleton\ModuleManager` and toggled per-instance from the admin
Configuration page — `./run modules sync|list|enable|disable` is the
CLI side of the same system. An instance that doesn't need a feature
simply doesn't install (or disables) the package; core code never
changes.

Ticketing (customer support tickets) currently ships as a *built-in*
feature rather than a real optional module — a deliberate exception:
`ModuleManager`'s own design was still unsettled when Ticketing was
built, so guessing at module boundaries from nothing was avoided in
favor of shipping a working feature first. The Requirements module is
the first feature built as a *real* optional module, now that
`ModuleManager` v1 is proven — treat it as the current worked example
for the pattern below, not Ticketing.

## Building your own module

1. A Composer package (its own repo, or a local `path` repository during
   development) with a `module.json` manifest at its root. Required
   fields: `key` (unique, used as the `module_registry` key) and `tier`
   (`'application'` for a module contributing a full Phalcon module —
   also needs `className` — vs. a lighter-weight extension). Optional:
   `surface` (defaults to `'backend'`) and `menu` (a path to a PHP file
   returning the module's menu entries, picked up automatically by
   `ModuleManager::mergedMenu()`). See
   `App_skeleton\ModuleManager::parseManifest()`/`discover()`
   (`app/common/library/ModuleManager.php`) for the authoritative,
   current field list — that source is the source of truth, this list
   just orients you.
2. Your own `Module.php` implementing `registerAutoloaders()` /
   `registerServices()`, the same shape every module here already uses
   (`app/modules/*/Module.php` are all real examples to copy from).
3. Controllers/models under your package, autoloaded the same
   per-module way — see CODING-STANDARDS.md's PHP section for why
   models are unqualified globals (`Tickets::find(...)`) rather than
   namespaced.
4. A `migrations/<adapter>/*.sql` directory if you need schema — the
   module-aware migration runner (`./run migrate run`,
   `app/modules/cli/tasks/MigrateTask.php`) applies any installed
   module's migrations alongside the base engine's own, tracked
   separately.
5. A menu entry via your manifest's `menu` field (see step 1);
   `ModuleManager::mergedMenu()` merges every enabled module's items
   into the sidebar automatically.
6. `composer require` it, `./run modules sync`, enable it from the
   Configuration page (or `./run modules enable <key>`).

Draft a short plan before building anything non-trivial: context, design
decisions, schema, controllers, menu entry, what the plan deliberately
doesn't cover, and a verification checklist. That structure is what
makes an autonomous or human build reviewable before code exists, not
just after — worth doing even if the plan itself doesn't ship with the
module.

## Standard conventions your module should follow

- List views: row actions (View/Edit/Delete) right-aligned per record, a
  New button top-right, a "with selected" bulk-operations dropdown
  top-left for batch update/delete — see `TicketsController`/`tickets/index.phtml`
  for the reference implementation.
- Branching/commits: trunk-based off `main`, `feature/<REQ-id>-short-name`
  or `fix/<issue>` branch names, Conventional Commits — see
  CODING-STANDARDS.md's Git workflow section.
- Soft deletes, audit logging, CSRF, RBAC: CODING-STANDARDS.md.

## Why the split

Every optional module can be removed with zero trace in core code —
that's the whole point of the Composer-package boundary. Core modules
can't be, because they *are* what "App Skeleton" means. If you're
unsure which kind your feature is, ask: "would every single instance of
this product need this to function at all?" If yes, it's core (and
probably belongs in a conversation with the base engine's maintainers,
not a plug-in). If no, it's a module.

## Cron

One thing every deployment needs at most one OS-level scheduled task
for, no matter how many background jobs the app itself has — see
[Adding a new cron job](#adding-a-new-cron-job) below for the workflow,
or the backend's Cron screen (admin-only) to see what's currently
registered.

### How it works

`cron_jobs` (Postgres) is the registry: one row per job — `name`,
`task`/`task_action` (which CLI task class + method to call), a
`frequency` (a `strtotime()`-relative expression like `+1 day`, applied
to the job's own `last_run_at` to decide if it's due — not a full
cron-expression parser, deliberately, to keep this simple), and
`enabled`.

`App_skeleton\CronRunner::runDueJobs()` (`app/common/library/CronRunner.php`)
is the shared logic: loop every enabled row, run the ones that are due,
record the result. Two callers use it:

- **CLI** (`./run cron run`, `app/modules/cli/tasks/CronTask.php`) —
  the one that matters for real deployments. Point a single OS crontab
  entry at it and every registered job gets evaluated on that same
  schedule:

  ```
  * * * * * cd /opt/app_skeleton && ./run cron run >> logs/cron.log 2>&1
  ```

  Only relevant when the `cron_mode` setting (Settings screen) is
  `auto`. Claude Code won't add this crontab entry itself — see
  `CONTRIBUTING.md`/`CODING-STANDARDS.md`'s Git Safety Protocol — that's
  a one-time step for whoever provisions the box.
- **Backend "Run now" button** (Cron screen) — the same
  `runDueJobs()` call, triggered manually. Only enabled when
  `cron_mode` is `manual`; in `auto` mode the button is disabled with a
  tooltip pointing at the crontab entry above, so there's never two
  things racing to run the same job.

Every execution — CLI or manual — writes one row to `cron_run_log`
(job name, status, output, a real timestamp) in addition to updating
the job's own `last_run_at`/`last_status`/`last_output` (kept for an
at-a-glance index view). The Cron screen's **Log** button (top-level:
every job's history; per-row: just that job) is how you read it back —
`cron_jobs`' own last-run columns only ever hold the *most recent* run,
so the log is the only place to see history at all.

The database backup (`BackupTask`) is a normal `cron_jobs` row like any
other, seeded on every install — see [Backups](#backups) below. It used
to be a deliberate exception (a separate host-crontab script, since the
app image had no Postgres client tools) — that's no longer true, the
app image carries its own `pg_dump` now, so backups go through the same
single crontab entry as everything else.

### Adding a new cron job

1. **Write the task.** A CLI task class under
   `app/modules/cli/tasks/`, same shape as any other
   (`AuditTask::archiveAction()` is the reference implementation) — the
   action just needs to do the work and `echo` anything worth recording
   as this run's output.
2. **Register it.** Cron screen → *New cron job* → `task` is the class
   name minus `Task` (e.g. `audit` for `AuditTask`), `task_action` is
   the method minus `Action` (e.g. `archive` for `archiveAction()`),
   `frequency` is a `strtotime()`-relative expression (`+1 day`,
   `+30 minutes`, `+1 week`). Leave `enabled` checked.
3. **Nothing else, if `cron_mode` is already `auto` and the crontab
   entry above already exists** — the next minute's `./run cron run`
   picks up the new row automatically, since it evaluates every enabled
   job, not a fixed list. If this is the *first* job on a fresh
   install, see the crontab line above and the `cron_mode` setting.
4. Watch it land in the Log view on its first due run.

### Backups

`BackupTask` (`app/modules/cli/tasks/BackupTask.php`) runs a real
`pg_dump` of the app's own database, gzips it, and writes it to
`./backups` on the host (bind-mounted into the app container at
`/app/backups` — see `docker-compose.yml`). It's seeded as a normal
`cron_jobs` row ("Database backup") on every install, same as
"Archive audit log" — no separate setup, it runs on whatever schedule
that row's `frequency` says (`+1 day` by default) through the exact
same single crontab entry described above.

- **Retention**: 14 days, local to the instance — old dump files are
  deleted automatically after each successful run. This protects
  against a bad migration or bad data, not against losing the droplet
  itself; there's no off-instance upload built in yet.
- **Restoring**: `gunzip -c backups/<file>.sql.gz | psql -h <host> -U <user> -d <dbname>`
  against an empty database. Always test a restore somewhere other than
  production before you actually need one.
- **Filename**: `<dbname>-<YYYYmmdd-HHMMSS>.sql.gz`.
- Disable or reschedule it from the Cron screen like any other job —
  there's nothing special about it there.

A separate, older host-crontab script (`docker/backup-db.sh`) predates
`BackupTask` and is kept only for instances that already have it wired
into their host crontab; new installs should use `BackupTask` instead.
