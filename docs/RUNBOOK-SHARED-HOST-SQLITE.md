# Runbook: build a SQLite demo on your Mac, upload it to cPanel

One linear pass, written to be followed on camera. You build and fill the
whole site on your Mac, check it there, then upload the finished folder
once. The host never runs Composer or migrations. Each step says what to
type, what you should see, and what to do if you don't.

Everything below was run end to end on 26 Sep 2026 on macOS (MacPorts PHP
8.3.33, SQLite 3.53): build, migrate, first admin, demo data, local check,
zip, unzip into a copy of the host's folder path, check again there.

Worked example values used throughout:

| | |
|---|---|
| Mac build folder | `~/demo-build` (any folder works) |
| Host | `cpanel-013-syd.hostingww.com`, cPanel user `zgqeztaq` |
| Site folder on the host | `/home/zgqeztaq/demo-sqlite-stack.xten.au` |
| Domain | `demo-sqlite-stack.xten.au` |

The Postgres installs are not affected by any of this. SQLite has its own
migration folders and its own connection class, which only loads when
`database.adapter` is `Sqlite`.

## Part A: on your Mac

### 1. Prerequisites (MacPorts)

```bash
sudo port install php83 php83-phalcon5 php83-psr php83-sqlite php83-mbstring php83-intl php83-curl php83-openssl php83-zip sqlite3
sudo port select --set php php83
```

Check:

```bash
php -v
php -m | grep -iE '^(phalcon|psr|pdo_sqlite|mbstring|intl|curl|openssl|zlib)$'
```

You should see PHP 8.3.x and all eight names. If `php -v` still shows
another version, open a new Terminal tab (the `port select` link is picked up
by new shells). If an extension is missing, `sudo port install php83-<name>`.

Composer isn't a MacPorts port. Install it with the official installer
(https://getcomposer.org/download/) and check with `composer --version`.

### 2. Get the code

The skeleton and the two module repos sit side by side in one folder:

```bash
mkdir -p ~/demo-build && cd ~/demo-build
git clone --branch feat/sqlite-shared-host https://github.com/xtenstack/app_skeleton.git demo-site
git clone --branch feat/sqlite-modules https://github.com/xtenstack/internal.git internal
git clone --branch feat/sqlite-announcements https://github.com/XTenDeploy/plugins.git plugins
```

(Once those branches are merged, drop the `--branch` options.) You should
now have `demo-site`, `internal` and `plugins` in `~/demo-build`.

### 3. Tell it which modules to install

```bash
cd ~/demo-build/demo-site
```

Create `composer.local.json` in `demo-site`:

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

`"symlink": false` matters: Composer copies each module into `vendor/`
instead of linking to `../internal`. A link would point at a folder that
won't exist on the host.

### 4. Point it at SQLite

Create `app/config/config.local.php`:

```php
<?php
return [
    'database' => [
        'adapter' => 'Sqlite',
        'dbname'  => BASE_PATH . '/db/app.sqlite',
    ],
];
```

That's the whole config. Use `BASE_PATH`, never a path like
`/Users/you/...`: `BASE_PATH` is worked out at runtime from wherever the
folder is, so the same file works on the Mac and on the host. The
directory and marketing modules also use two more database files,
`db/app.abn_lookup.sqlite` and `db/app.directory.sqlite`. Their paths come
from `dbname` automatically, so they move with it. Don't list them.

Leave `mail.resend_api_key` out: the demo sends no mail.

### 5. Install the code

```bash
composer update --no-dev --optimize-autoloader 'xtendeploy/*' 'xtenstack/*'
```

You should see Composer install the framework's dependencies and seven
`xtenstack/...`/`xtendeploy/...` packages, then the skeleton's own install
script: `app_skeleton: applying migrations...`, `seeding defaults...`,
`syncing module registry...`, `install complete.` That script creates the
empty database files from step 4's config. It runs even with
`--no-scripts`, because the plugin that merges `composer.local.json` runs a
second install pass, so don't bother adding it. Check:

```bash
ls vendor/xtenstack vendor/xtendeploy
```

That should list six module folders and `announcements`. They must be real
folders, not links (`ls -l` shows no `->`).

Composer also rewrites `composer.lock` with the modules in it. That's fine
for this build folder; just never commit it.

### 6. Build the database (migrations)

```bash
./run migrate run
./run seed run
./run modules sync
for m in announcements agent_rooms requirements licensing kpi directory marketing; do ./run modules enable $m; done
./run unspsc import
```

Each command only does what isn't done yet, so it's safe that Composer's
install script already ran the first three. What you should see:

- `No pending migrations.` (Composer's script applied all 92: 22 base + 70
  module. On a folder where it didn't run, you see `Migrations complete
  (92 applied).`)
- `Seeding complete.`
- seven `updated:` (or `discovered:`) lines, then `Sync complete.`
- seven `...: enabled` lines
- `Import completed successfully! Total records processed: 23873` (the public
  UNSPSC code list the directory module uses, about 10 seconds)

`ls db` shows `app.sqlite`, `app.abn_lookup.sqlite` and
`app.directory.sqlite`.

Add the hourly job that keeps the marketing search up to date (on Postgres
this is a materialized view; on SQLite a task rebuilds a table):

```bash
sqlite3 db/app.sqlite "INSERT INTO cron_jobs (name, task, task_action, frequency, enabled) VALUES ('Marketing: refresh prospect search', 'prospect-search', 'refresh', '+1 hour', 1);"
```

### 7. Create the first admin

Start the local server (leave it running in its own Terminal tab):

```bash
php -S localhost:8091 -t public bin/dev-router.php
```

Open http://localhost:8091/backend/signup and sign up with the email and
password you'll use on the live demo. Then make that account an admin and
mark it verified (no admin is seeded, on purpose):

```bash
sqlite3 db/app.sqlite "UPDATE users SET role_id=(SELECT id FROM roles WHERE name='admin'), email_verified_at=CURRENT_TIMESTAMP WHERE email='you@example.com';"
```

`sqlite3 db/app.sqlite "SELECT email, role_id FROM users;"` should show
your email with role `1`.

### 8. Add the demo data

This is a separate step and it runs after step 6, on the database files that
step just built. It writes into `db/app.abn_lookup.sqlite` and
`db/app.directory.sqlite`:

```bash
php ../internal/bin/sqlite-demo-sampler.php --target="$PWD/db/app.sqlite" --synthetic --wipe
./run prospect-search refresh
```

You should see a table of row counts (300 businesses, 4 campaigns, ~130
campaign members, ~450 contacts, ~100 leads, 6 directory claims) ending in
`Done.`, then `mv_prospect_search refreshed: 130 rows`.

Everything is invented: fake but checksum-valid ABNs, made-up names,
`*.example` domains, phone numbers in ACMA's fictitious-use range. It's safe
to show publicly. `--wipe` clears the demo tables first (not your admin
account), so you can re-run it.

### 9. Check it locally

With the server from step 7 still running, log in at
http://localhost:8091/backend and click through:

- Dashboard, Tickets, KB articles, Users, Configuration
- Marketing → Prospects (try searching "harbour"), Campaigns, Leads, Lead
  Reservations, Addresses, Mail Templates
- Directory → Claims, Records (open one), Opt-outs, Enquiries
- KPI → Dashboard; Agent Rooms; Announcements; Requirements; Licensing
- http://localhost:8091/api/v1/directory/search?q=harbour&portal=entity
  returns JSON with results

Every page should load. If one doesn't, check `logs/app.log`.

Then stop the server (Ctrl+C in its tab).

### 10. Package it

From `demo-site`, clear what belongs to this Mac only, then zip:

```bash
find sessions cache/volt logs -type f ! -name .gitkeep -delete
find db -maxdepth 1 \( -name '*.advisory-lock-*' -o -name '*-journal' \) -delete
rm -f public/webtools.php public/webtools.config.php
cd ..
zip -qr demo-site.zip demo-site -x 'demo-site/.git/*' 'demo-site/.github/*' 'demo-site/.claude/*' \
  'demo-site/tests/*' 'demo-site/docker/*' 'demo-site/node_modules/*' 'demo-site/backups/*' \
  'demo-site/composer.local.json' '*.DS_Store'
ls -lh demo-site.zip
```

About 6 MB. What's in it and why:

| Path | Needed on the host? | Web-served? |
|---|---|---|
| `public/` | yes | **yes, the only folder that is** |
| `app/` (including `config/config.local.php`) | yes | never |
| `vendor/` (framework deps + the 7 modules) | yes | never |
| `db/app*.sqlite` (the three database files) | yes | **never** |
| `run`, `bin/` | yes (cron) | never |
| `cache/volt/`, `logs/`, `sessions/`, `storage/` | yes, as empty folders | never |
| `.git/`, `tests/`, `docker/`, `composer.local.json` | no | |

Why the two `rm` lines:

- Session files, compiled templates and logs from your Mac would only be
  stale on the host; it recreates them.
- `public/webtools.php` and `public/webtools.config.php` are the Phalcon
  developer tools' web entry point. They sit in the web root, and the config
  file has a hard-coded `/Users/...` path from whoever first generated the
  skeleton. Never upload them.
- `.encryption_key` doesn't exist unless you saved an external connection
  in Configuration. If you did, keep it in the zip, because those saved
  credentials can't be read without it.

Nothing else in the folder holds a Mac path. Checked on 26 Sep: the built
folder, database files included, had no copy of the build path in it, and
the unzipped copy worked from a different path unchanged.

## Part B: on the host

The account's default PHP is 8.3, with every extension this needs already
on (checked 26 Sep: phalcon, psr, pdo_sqlite, intl, mbstring, curl and the
rest), so there's nothing to set up for PHP.

### 11. Upload and unpack

1. cPanel → *File Manager* → `/home/zgqeztaq`. Upload `demo-site.zip`.
   About 6 MB, which File Manager takes in one go. (PHP's 2M
   `upload_max_filesize` limits uploads through the app, not File Manager.)
   If File Manager ever refuses a bigger zip, upload it by FTP instead.
2. *Extract* it. You get `/home/zgqeztaq/demo-site`.
3. Move the *contents* of `demo-site` into
   `/home/zgqeztaq/demo-sqlite-stack.xten.au`, or rename the folder to that,
   replacing any placeholder cPanel created. Afterwards
   `/home/zgqeztaq/demo-sqlite-stack.xten.au/public/index.php` must exist.
4. Delete `demo-site.zip` from the server.

### 12. Permissions

The site runs as your cPanel user, so the usual **755 for folders, 644 for
files** is enough. Check these folders are 755 and owned by `zgqeztaq`:
`db`, `cache/volt`, `logs`, `sessions`, `storage`, `backups` (create
`backups` if it isn't there).

`db` matters most. SQLite writes a temporary journal file *next to* the
database while it saves, so the folder must be writable, not just the
`.sqlite` files. If saving anything gives "attempt to write a readonly
database" or "unable to open database file", `db` or a file in it isn't
writable by `zgqeztaq`. Never make anything 777.

### 13. Point the domain at `public/`

cPanel → *Domains* → `demo-sqlite-stack.xten.au` → *Manage* → set the
**document root** to `demo-sqlite-stack.xten.au/public`.

That keeps `db/`, `app/` and `vendor/` out of the web root entirely. (The
skeleton's root `.htaccess` would also route everything into `public/` if
the docroot were left at the folder, but pointing it at `public/` doesn't
depend on that.)

Check: https://demo-sqlite-stack.xten.au/ loads the landing page, and
https://demo-sqlite-stack.xten.au/db/app.sqlite returns the site's 404, not a
download. Log in at `/backend` with the account from step 7.

### 14. Cron

cPanel → *Cron Jobs* → every 5 minutes:

```
/opt/alt/php83/usr/bin/php /home/zgqeztaq/demo-sqlite-stack.xten.au/run cron run >/dev/null 2>&1
```

Use `/opt/alt/php83/usr/bin/php`, the CloudLinux PHP 8.3 binary. It stays
8.3 even if the account's default version changes later.
`/usr/local/bin/php` follows the account default, so it works today too,
but would quietly switch versions with it. Once, before relying on it, add
a one-off cron job
`/opt/alt/php83/usr/bin/php -m > /home/zgqeztaq/php83-cron-check.txt`, let it
run, check the file lists `phalcon` and `pdo_sqlite`, then delete the job
and the file.

The cron job runs the demo's scheduled work: the hourly search refresh
from step 6, the daily backup (`./run backup run`, compacted gzipped copies
of the three database files in `backups/`, 14 days kept), and the rest.

### 15. Mail

Leave it off: no `mail.resend_api_key` in `config.local.php`. Signup
verification and password-reset mails are logged and skipped. To add an
account on the live demo, create it the same way as step 7, locally, and
re-upload (next part), or sign up on the site and run step 7's SQL on the
host's `db/app.sqlite` via cPanel *Terminal* if the account has it.

## Part C: re-seed or reset

Do it on the Mac and re-upload the database files. The code on the host
stays as it is.

- **Fresh demo data, same accounts:** in `~/demo-build/demo-site`, run
  step 8 again (`--wipe` replaces the demo tables).
- **Start completely clean:** `rm db/*.sqlite`, then steps 6, 7 and 8
  again.

Then upload only the three files `db/app.sqlite`, `db/app.abn_lookup.sqlite`
and `db/app.directory.sqlite` into `/home/zgqeztaq/demo-sqlite-stack.xten.au/db/`,
overwriting. Upload all three together: they belong together (the demo data
references accounts and codes across them). Anything visitors changed on the
live demo since the last upload is replaced, which is usually the point.

If you pulled new code (a module update), rebuild from step 5 and upload
the whole folder again as in steps 10–11.

## Reference

### What works on SQLite, module by module

Checked on 26 Sep by loading every menu page, the detail and edit pages, the
create/update forms, the JSON APIs listed and the cron tasks.

| Module | Works | Doesn't, or differs |
|---|---|---|
| Base skeleton | everything above, cron, backups, KB, tickets | one writer at a time (fine for a demo) |
| Announcements | backend list/history/create/publish/edit, `GET/POST /api/announcements`, the status-feed poller | none found |
| Agent Rooms | backend rooms and the whole `/agent_rooms/api/*` flow | read receipts stored as `'{1,2}'` text instead of an integer array; same API output |
| Requirements | requirements and changelogs | none found |
| Licensing | keys list/create/revoke | none found |
| KPI | dashboard, entries, period form, metrics, `POST /api/kpi/entry` | none found |
| Directory | backend claims, records, opt-outs, enquiries; the public `/api/v1/directory/*` API | paid claims need Dolibarr; searches are `LIKE` scans, not trigram-indexed; JSON booleans are `0`/`1` |
| Marketing | prospects, campaigns, leads, lead reservations, addresses, mail templates, contacts redirect, public lead capture, cron tasks | see below |

Marketing on SQLite:

- **No materialized view, no trigram index.** `mv_prospect_search` is a
  table rebuilt by `./run prospect-search refresh`, searched with `LIKE`.
  Fine for thousands of rows, not for production's ~450k.
- **The pre-module tables match production.** The eight objects the
  Postgres migrations assume already exist (`campaigns`, `campaign_members`,
  `contacts`, `entity_domains`, `site_profile`, `asic_business_names`,
  `dgr`, `v_prospect_qualification`) are built by the SQLite
  `000_base_tables.sql` from production's own definition, with the same
  columns, constraints and indexes. `v_prospect_qualification` is the same
  view, translated, and gives the same output as production's on the same
  rows. One difference: `asic_business_names.name_normalised` is a plain
  column filled by the demo loader (on Postgres it's generated by a
  database function whose body isn't in any repo).
- **Not available:** `bulk_convert_leads_to_campaign()` (an operator SQL
  helper the PHP code doesn't use) and G-NAF address lookup (shows its
  normal "not loaded" state). `normalise_name()` is replaced by a PHP
  normaliser for public lead capture's name match.
- **Needs outside services, as on any instance:** mail (Resend), address
  verification (DeBounce / MillionVerifier), the search resolver
  (Gemini / Brave).

### How the SQLite port works

- **Schemas are attached files.** `abn_lookup.abns` in module SQL works
  unchanged because `App_skeleton\Db\SqliteAdapter` attaches
  `app.abn_lookup.sqlite` as `abn_lookup` (and the same for `directory`) on
  every connection, at a path worked out from `database.dbname`. A view or
  trigger can only use tables in its own file, and there are no foreign
  keys between files.
- **Postgres functions as PHP functions.** `now()`, `greatest()`,
  `least()`, `split_part()`, `btrim()`, `left()`, `right()`, `initcap()`,
  `date_trunc()`, `md5()`, `similarity()`, `regexp()`/`iregexp()`,
  `regexp_substr()` and CronRunner's advisory-lock functions are registered
  on the connection. Views that use them only work through the app, not the
  bare `sqlite3` command line.
- **A small SQL translator** (`App_skeleton\Db\PgSqlTranslator`) rewrites
  only purely syntactic differences in raw SQL on the SQLite connection:
  `ILIKE`, `::casts`, `FOR UPDATE`, `INTERVAL` arithmetic, `UPDATE t alias`,
  `~*`/`~`.
- **Everything else is an explicit branch** in module code on
  `$db->getType() === 'sqlite'`, leaving the Postgres query as it was.
- **Keep the two migration folders in step.** Each `migrations/sqlite/`
  file starts with "SQLite port of postgresql/<same name>". Where SQLite
  can't alter a column or constraint in place, the earlier SQLite file
  creates the final shape and the later one is a documented no-op. SQLite
  installs are always built fresh, so there's no upgrade path to protect.

### Sampling real data instead

`sqlite-demo-sampler.php` can also sample a Postgres database (a restored
backup, or a read-only role) with `--source-dsn`/`--source-user` in place of
`--synthetic`. It reads inside a `READ ONLY` transaction and replaces every
ABN, name, email, phone, domain, street and free-text field. It never copies
unsubscribes or opt-outs. It also scans the result for any real value it
replaced, and if it finds one it rolls the whole run back (exit code 2).
For a public demo, stick with `--synthetic`.

### Known differences from a Postgres install

- One writer at a time. The connection waits up to 5 seconds for a lock
  rather than failing.
- Booleans are stored and returned as `1`/`0`.
- `now()` and PHP-written timestamps use PHP's timezone; column defaults
  (`CURRENT_TIMESTAMP`) are UTC.
- Timestamps have no fractional seconds.
