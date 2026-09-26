# Runbook: install on cPanel shared hosting with SQLite

Step-by-step install of the skeleton, and optionally the XTen modules, on an
ordinary cPanel shared host with SQLite as the database. There's no Docker
and no Postgres, and you don't need SSH. Written to be followed on camera:
each step says what you do, what you should see, and what to do if you don't.

**Status (26 Sep 2026):** Part 1 (base skeleton), Part 2 (modules) and
Part 3 (demo data) are verified locally on PHP 8.3 + SQLite 3.53. Not yet
done on the real host: the steps in Part 1 §2 that need cPanel clicks.

The Postgres install is unaffected by any of this: SQLite has its own
migration folders (`db/migrations/sqlite/`, each module's
`migrations/sqlite/`) and its own connection class, which only loads when
`database.adapter` is `Sqlite`.

## What you need

| Thing | Why | How to check |
|---|---|---|
| cPanel host with **PHP 8.3** available (CloudLinux *Select PHP Version*) | the skeleton needs `^8.3` | cPanel → *Select PHP Version*: the version dropdown lists 8.3 |
| The PHP 8.3 extensions below, ticked for 8.3 | the framework, the database driver, and what the code calls | same screen → *Extensions*, with 8.3 picked in the dropdown |
| A build machine with PHP 8.3 + Phalcon + `pdo_sqlite` + Composer + git | builds `vendor/` and the database files, since the host can't | `php -m \| grep -iE 'phalcon\|pdo_sqlite'` shows both |
| FTP/SFTP or cPanel File Manager | upload | |
| Optional: cPanel *Terminal* | runs `./run` on the host; everything below also works without it | |

PHP 8.3 extensions to tick. The skeleton's `composer.json` asks for
`ext-phalcon` and `ext-openssl` (its `ext-pdo_pgsql` is only needed for
Postgres), `league/commonmark` needs `ext-mbstring`, and the code also calls
curl, zlib and ctype functions:

| Extension | Why |
|---|---|
| `phalcon` (listed as `phalcon5` on some hosts) | the framework |
| `psr` | Phalcon's companion extension, if the host lists it |
| `pdo_sqlite` (and `pdo`) | the database driver |
| `mbstring` | Markdown rendering (league/commonmark), string helpers |
| `intl` | Phalcon/locale helpers; cheap to have on |
| `curl` | outgoing HTTPS: mail (Resend), Dolibarr, status feeds, verifier and search APIs |
| `openssl` | encrypted external-connection credentials, HTTPS |
| `zlib` | `./run backup run` gzips its copies |
| `ctype`, `json`, `session` | usually built in; tick them if they're listed and unticked |

Reference host (XTen's, Sep 2026): `cpanel-013-syd.hostingww.com`, CloudLinux
cPanel, LiteSpeed (lsapi), PHP 8.1 by default with 8.1–8.5 available,
Phalcon 5.20.3, SQLite 3.53, 128M `memory_limit`, 30s `max_execution_time`,
2M `upload_max_filesize`. On 8.3 this account had only `mysqlnd`, `mysqli`,
`pdo_mysql` and `sqlite3` ticked (no `mbstring`, no `intl`) as of 26 Sep.

## Part 1: the base skeleton

### 1. Build on your machine

```bash
git clone https://github.com/xtenstack/app_skeleton.git demo-site
```

```bash
cd demo-site && composer install --no-dev --optimize-autoloader
```

`composer install` runs `bin/install.php`, which will try Postgres and fail.
That's expected, because nothing is configured yet. Next, create
`app/config/config.local.php`:

```php
<?php
return [
    'database' => [
        'adapter' => 'Sqlite',
        // Relative to the site root on the host. Keep it OUTSIDE public/.
        'dbname'  => BASE_PATH . '/db/app.sqlite',
        // Optional. Postgres schemas the modules use, each one a separate
        // file next to app.sqlite (app.abn_lookup.sqlite, ...). This is
        // the default; only set it to add or drop one.
        // 'schemas' => ['abn_lookup', 'directory'],
    ],
];
```

Then build the database file:

```bash
./run migrate run && ./run seed run && ./run modules sync
```

Expect `Migrations complete (22 applied).`, then `Seeding complete.`, then one
`discovered:` line per module package. Running `./run migrate run` again
should print `No pending migrations.`

To try it before uploading:

```bash
php -S localhost:8091 -t public bin/dev-router.php
```

### 2. Prepare the domain in cPanel

1. *Domains* → create the domain or subdomain (e.g. `demo-sqlite-stack.xten.au`).
   Its document root can stay the folder cPanel makes
   (`/home/<user>/demo-sqlite-stack.xten.au`): the skeleton's own root
   `.htaccess` sends every request into `public/`, so `app/`, `db/`,
   `vendor/` and `config.local.php` are never served. (Pointing the
   document root straight at `<folder>/public` also works.)
2. **Run just this folder on PHP 8.3.** The account's default PHP (8.1 on
   XTen's host) is too old, but Dolibarr and the other sites must stay on
   it. Put this as the **first line** of the site folder's `.htaccess`
   (the root one that the upload in step 3 brings; add it after uploading):

   ```apache
   AddHandler application/x-httpd-alt-php83___lsphp .php
   ```

   That makes LiteSpeed run PHP 8.3 (8.3.33 on XTen's host) for this folder
   and everything under it, and nothing else.
3. **Turn on the 8.3 extensions.** cPanel → *Select PHP Version* → pick
   **8.3** in the version dropdown → *Extensions* → tick everything in the
   table under "What you need". **Do not press "Set as current".** That
   button would move the whole account, Dolibarr included, to 8.3. Picking
   8.3 in the dropdown only chooses which version's extension list you are
   editing.

   Why not a per-folder `php.ini`: tested on 26 Sep, this host ignores a
   folder `php.ini`, `lsapi_phpini`, `SetEnv PHPRC` and
   `SetEnv PHP_INI_SCAN_DIR`, and `dl()` is disabled, so extensions can only
   be switched on account-wide, per PHP version, on this screen. Ticking
   them for 8.3 doesn't touch anything running on 8.1.
4. Optional: raise upload limits with a `.user.ini` in `public/` (e.g.
   `upload_max_filesize = 20M`, `post_max_size = 24M`). It takes up to 5
   minutes to apply (`user_ini.cache_ttl`).

To check: upload a one-line `public/info.php` containing
`<?php phpinfo();` and open it. The page should say PHP 8.3 and list
`phalcon` and `pdo_sqlite`. **Delete it straight afterwards.**

### 3. Upload

Zip the built tree (including `vendor/` and every `db/*.sqlite` file) and
upload it with File Manager, then *Extract* it into the domain's folder.
This is much faster than FTPing thousands of `vendor/` files one at a time.
If the zip is over the host's upload limit, split it or use FTP for
`vendor/`. Then add the `AddHandler` line from step 2.2.

Then fix the folder permissions. The web server runs as your cPanel user, so
**755 on folders and 644 on files** is enough. These must be writable:
`db/` (the folder, not just the files, because SQLite writes a journal next
to them), `cache/volt/`, `logs/`, `sessions/`, `storage/`, `backups/` and
`public/temp/`.

### 4. First visit and first admin

1. Open `https://<domain>/`. You should see the landing page. A blank page
   or 500 almost always means the PHP version or an extension (step 2).
   Check cPanel → *Errors*, or `logs/`.
2. Go to `/backend/signup` and create your account.
3. Make it an admin. No admin user is seeded, deliberately. With cPanel
   Terminal:

   ```bash
   sqlite3 db/app.sqlite "UPDATE users SET role_id=(SELECT id FROM roles WHERE name='admin'), email_verified_at=CURRENT_TIMESTAMP WHERE email='you@example.com';"
   ```

   Without Terminal: do this on your build machine *before* uploading
   (sign up against the local `php -S` run from step 1), or download
   `db/app.sqlite`, run the command locally and upload it back.
4. Log in at `/backend`. The dashboard, Tickets, KB articles, Users,
   Configuration, Audit log and Settings pages should all load.

### 5. Scheduled jobs

cPanel → *Cron Jobs*, every 5 minutes (use the PHP 8.3 binary's full path;
on CloudLinux it's `/opt/alt/php83/usr/bin/php`):

```
/opt/alt/php83/usr/bin/php /home/<cpanel-user>/<folder>/run cron run >/dev/null 2>&1
```

The CLI binary reads the account's 8.3 extension ticks from step 2.3 too.

`./run backup run` (the seeded *Database backup* cron job) works on SQLite:
it writes a compacted, gzipped copy of `app.sqlite` and of each schema file
into `backups/` with `VACUUM INTO`, while the site stays up, and keeps 14
days.

### 6. Outgoing mail

Signup verification and password reset send mail through Resend
(`mail.resend_api_key` in `config.local.php`). For a demo, leave it unset
and verify accounts with the SQL in step 4.

## Part 2: the XTen modules

Every module in `xtenstack/internal` (agent rooms, requirements, licensing,
KPI, directory, marketing) and `XTenDeploy/plugins` (announcements) now ships
a `migrations/sqlite/` folder, so `./run migrate run` builds their tables
too. Until the module branches are merged, check out
`feat/sqlite-modules` (internal) and `feat/sqlite-announcements` (plugins).

### 7. Install the modules (build machine)

Clone the two module repos next to the skeleton, as `../internal` and
`../plugins` (see [INTERNAL-MODULES.md](INTERNAL-MODULES.md)), then create
`composer.local.json`:

```json
{
    "repositories": [
        { "type": "path", "url": "../internal/*", "options": { "symlink": false } },
        { "type": "path", "url": "../plugins/*",  "options": { "symlink": false } }
    ],
    "require": {
        "xtendeploy/announcements": "*",
        "xtenstack/agent-rooms-module": "*",
        "xtenstack/requirements-module": "*",
        "xtenstack/licensing-module": "*",
        "xtenstack/kpi-module": "*",
        "xtenstack/directory-module": "*",
        "xtenstack/marketing-module": "*"
    }
}
```

`symlink: false` matters here: the upload must carry real files, not links.

```bash
composer update --no-dev 'xtendeploy/*' 'xtenstack/*'
git checkout composer.lock    # never commit the module-bearing lock file
./run migrate run
./run modules sync
for m in announcements agent_rooms requirements licensing kpi directory marketing; do ./run modules enable $m; done
./run unspsc import           # directory: UNSPSC code list (public data, ~20s)
```

Expect `Migrations complete (70 applied).` on top of the 22 base ones, and
three database files in `db/`: `app.sqlite`, `app.abn_lookup.sqlite`,
`app.directory.sqlite`. Upload all three.

Add the search-table refresh to cron (marketing's prospect search reads a
table that this task rebuilds; Postgres uses a materialized view instead):

```bash
sqlite3 db/app.sqlite "INSERT INTO cron_jobs (name, task, task_action, frequency, enabled) VALUES ('Marketing: refresh prospect search', 'prospect-search', 'refresh', '+1 hour', 1);"
```

### What works on SQLite, module by module

Checked locally on 26 Sep by loading every menu page, the detail/edit pages,
the create/update forms and the JSON APIs listed, plus the modules' cron
tasks.

| Module | Works | Doesn't, or differs |
|---|---|---|
| Base skeleton | everything in Part 1, cron, backups, KB, tickets | one writer at a time (fine for a demo) |
| Announcements | backend list/history/create/publish/edit, `GET/POST /api/announcements` (upsert by source + external id), the status-feed poller | none found |
| Agent Rooms | backend rooms, the whole `/agent_rooms/api/*` flow (create, join, message, read receipts, status, transcript, lock) | `read_by` is stored as `'{1,2}'` text instead of an integer array; same API output |
| Requirements | requirements and changelogs, create/edit/assign/export | none found |
| Licensing | keys list/create/revoke | none found |
| KPI | dashboard, entries, period entry form, metrics, `POST /api/kpi/entry`, `./run kpi openPeriod` | none found |
| Directory | backend claims, records (search, detail, UNSPSC codes, slugs), opt-outs, enquiries; the public `/api/v1/directory/*` API (search, entity, person, verify-abn, UNSPSC, enquiry, opt-out, slug resolve) | paid claims and payment sync need Dolibarr (as on any instance without it); searches are `LIKE` scans, not trigram-indexed; JSON booleans come back as `0`/`1` |
| Marketing | prospects (browse, filter, search, detail, edit, bulk status), campaigns, leads (create, convert), lead reservations, addresses, mail templates, `api-contacts/redirect`, public lead capture, cron tasks (activate, reservation sweep, send plan / dry run, verify status, prospect-search refresh) | see below |

Marketing differences on SQLite:

- **No materialized view, no trigram index.** `mv_prospect_search` is a
  plain table rebuilt by `./run prospect-search refresh` (hourly cron above),
  searched with `LIKE`. Fine for thousands of rows, not for production's
  ~450k.
- **`abn_lookup.v_prospect_qualification` is a table** filled by the demo
  data loader (Part 3). On Postgres it's a view whose definition isn't in
  any repo.
- **Pre-module tables are reconstructed.** The Postgres migrations assume
  `campaigns`, `campaign_members`, `contacts`, `entity_domains`,
  `site_profile`, `asic_business_names`, `dgr` and
  `v_prospect_qualification` already exist (they came from the original
  xten_marketing database). The SQLite `000_base_tables.sql` rebuilds them
  from what the code reads and writes. Good enough for the pages; not a
  copy of production.
- **Not available:** `abn_lookup.bulk_convert_leads_to_campaign()` (an
  operator SQL helper, not used by the PHP code); G-NAF address lookup
  (the `gnaf` schema isn't attached, so the page shows its normal "not
  loaded" state); `abn_lookup.normalise_name()` is replaced by a PHP
  normaliser for public lead capture's name match.
- **Needs outside services, as on any instance:** sending mail (Resend
  key), address verification (DeBounce / MillionVerifier keys), the search
  resolver (Gemini / Brave keys).

### How the SQLite port works (for whoever maintains it)

- **Schemas are attached files.** `abn_lookup.abns` in module SQL works
  unchanged because `App_skeleton\Db\SqliteAdapter` attaches
  `app.abn_lookup.sqlite` as `abn_lookup` (and the same for `directory`) on
  every connection. A view or trigger can only use tables in its own file,
  and there are no foreign keys between files, so the SQLite migrations drop
  cross-schema foreign keys and write view bodies without a schema prefix.
- **Postgres functions as PHP functions.** `now()`, `greatest()`,
  `least()`, `split_part()`, `btrim()`, `left()`, `right()`, `initcap()`,
  `date_trunc()`, `md5()`, `similarity()`, `regexp()`/`iregexp()` and the
  two advisory-lock functions CronRunner uses are registered on the
  connection. The Postgres connection doesn't get them and doesn't need
  them.
- **A small SQL translator** (`App_skeleton\Db\PgSqlTranslator`) rewrites
  only purely syntactic differences in raw SQL on the SQLite connection:
  `ILIKE`, `::casts`, `FOR UPDATE`, `+/- INTERVAL '...'`, `UPDATE t alias`,
  and `~*`/`~` against a bound pattern.
- **Everything else is an explicit branch** in module code on
  `$db->getType() === 'sqlite'`, leaving the Postgres query text as it was:
  `DISTINCT ON` and `(array_agg(...))[1]` become `ROW_NUMBER()` windows,
  integer-array read receipts become text, the matview refresh becomes a
  table rebuild.
- **Keep the two migration folders in step.** Each `migrations/sqlite/`
  file starts with "SQLite port of postgresql/<same name>". Where SQLite
  can't alter a column or constraint in place, the earlier SQLite file
  creates the final shape and the later one is a documented no-op. SQLite
  installs are always built fresh, so there is no upgrade path to protect.

## Part 3: demo data

`xtenstack/internal`'s `bin/sqlite-demo-sampler.php` fills the directory and
marketing tables of a migrated SQLite install. Run it on the build machine
after step 7, then refresh the search table:

```bash
php ../internal/bin/sqlite-demo-sampler.php --target="$PWD/db/app.sqlite" --synthetic --wipe --entities=300
./run prospect-search refresh
```

`--synthetic` invents everything: checksum-valid fake ABNs, made-up business
and person names, `*.example` domains, phone numbers in ACMA's
fictitious-use range. Nothing real, so it's the right choice for a public
demo. 300 entities gives 4 campaigns, ~130 campaign members, ~450 contacts,
~100 leads and a few directory claims.

It can instead sample a real database (a restored backup, or a read-only
role on production if you decide that's acceptable):

```bash
PGPASSWORD=... php ../internal/bin/sqlite-demo-sampler.php --target="$PWD/db/app.sqlite" --wipe \
  --source-dsn='pgsql:host=127.0.0.1;port=5432;dbname=restored_copy' --source-user=readonly_user \
  --campaigns=6 --members=60
```

That reads inside a `READ ONLY` transaction, takes the newest members of
the first N campaigns and everything keyed by their ABNs, and replaces every
ABN, name, email, phone, domain, street and free-text field on the way in
(only coarse attributes survive: state, postcode, ANZSIC, entity type,
statuses, dates). Unsubscribes and privacy opt-outs are never copied. A
leak scan then looks for every real ABN, name, email and phone it replaced
anywhere in the output, and rolls the whole run back (exit code 2) if it
finds one.

## Known differences from a Postgres install

- One writer at a time. The connection waits up to 5 seconds for a lock
  (`busy_timeout`) rather than failing. That's fine for a demo or training
  site, but not for a busy production instance.
- Booleans are stored and returned as `1`/`0`, and `module_registry.enabled`
  as `1`/`0`/`''`. `enabled = true` queries still work.
- `now()` and PHP-written timestamps use PHP's timezone; column defaults
  (`CURRENT_TIMESTAMP`) are UTC.
- Timestamps have no fractional seconds.
