# Runbook: run the skeleton on SQLite on shared hosting

How to build the skeleton (and optionally your own modules) with SQLite as
the database on your own machine, check it there, then upload the finished
folder once to an ordinary cPanel-style shared host. The host never runs
Composer or migrations, and you don't need SSH on it.

Placeholders used below:

| Placeholder | Meaning |
|---|---|
| `~/build` | a folder on your machine (any path works) |
| `example.com` | your domain or subdomain |
| `USER` | your hosting account's username |
| `/home/USER/example.com` | the site's folder on the host |

A Postgres install is not affected by any of this: SQLite has its own
migration folders (`db/migrations/sqlite/`, each module's
`migrations/sqlite/`) and its own connection class, which only loads when
`database.adapter` is `Sqlite`.

## What you need

- **PHP 8.3 or newer** with these extensions, on your machine and on the host:
  `phalcon` (5.x, sometimes listed as `phalcon5`), `psr` (if your platform
  packages Phalcon with it), `pdo_sqlite`, `mbstring`, `intl`, `curl`,
  `openssl`, `zlib`, and the usual built-ins (`ctype`, `json`, `session`).
  Check with `php -m` locally. On the host, a one-line `phpinfo()` page
  shows the version and extensions (delete it straight afterwards).
  Many shared hosts (CloudLinux "Select PHP Version" and similar) pick the
  PHP version and tick extensions per version, for the whole account. Pick
  8.3+ and tick the extensions above for it.
- **Composer**, **git** and the **`sqlite3`** command-line tool on your
  machine.
- FTP/SFTP or the host's file manager for the upload.

## Part A: on your machine

### 1. Get the code

```bash
mkdir -p ~/build && cd ~/build
git clone https://github.com/xtenstack/app_skeleton.git site
cd site
```

### 2. Point it at SQLite

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

Use `BASE_PATH`, never an absolute path like `/home/you/...` or
`C:\...`. `BASE_PATH` is worked out at runtime from wherever the folder
is, so the same file works on your machine and on the host. Leave the mail
settings out for now (see step 14).

If you're adding modules, do that now (see "Adding modules" below), before
installing.

### 3. Install

```bash
composer install --no-dev --optimize-autoloader
```

Composer installs the dependencies, then runs the skeleton's install script
(`bin/install.php`): `applying migrations...`, `seeding defaults...`,
`syncing module registry...`, `install complete.` With step 2's config in
place, that already creates `db/app.sqlite`.

### 4. Build the database

Run the same steps explicitly. Each one only does what isn't done yet:

```bash
./run migrate run
./run seed run
./run modules sync
```

Expect `No pending migrations.` (or `Migrations complete (22 applied).` if
the install script didn't run), `Seeding complete.` and `Sync complete.`.
If you added modules, enable each one: `./run modules enable <key>`.

### 5. Create the first admin

No admin is seeded, on purpose. This step has three parts, in this order.
The last one does nothing unless the first one has happened.

Start the local server in its own terminal tab and leave it running:

```bash
php -S localhost:8091 -t public bin/dev-router.php
```

**5a. Sign up in the browser.** Open http://localhost:8091/backend/signup,
fill in the email and password you'll use on the live site, and submit.

**5b. Check the account exists, and copy its email exactly:**

```bash
sqlite3 db/app.sqlite "SELECT email, role_id FROM users;"
```

You must see one row with your email. No rows means the signup didn't go
through: do 5a again. Check the email carefully. A typo at signup means the
next command matches nothing and silently does nothing.

**5c. Make it an admin and mark it verified**, with the email exactly as
5b showed it:

```bash
sqlite3 db/app.sqlite "UPDATE users SET role_id=(SELECT id FROM roles WHERE name='admin'), email_verified_at=CURRENT_TIMESTAMP WHERE email='you@example.com';"
sqlite3 db/app.sqlite "SELECT email, role_id FROM users;"
```

The row must now show the admin role (`1`). If not, the email didn't
match: copy it from the SELECT output and run the UPDATE again.

### 6. Check it locally

Log in at http://localhost:8091/backend and click through the dashboard,
Tickets, KB articles, Users, Configuration and Settings (and your modules'
pages). Every page should load; if one doesn't, check `logs/app.log`. Then
stop the server (Ctrl+C).

### 7. Package it

From the site folder, clear what belongs to this machine only, then zip:

```bash
find sessions cache/volt logs -type f ! -name .gitkeep -delete
find db -maxdepth 1 \( -name '*.advisory-lock-*' -o -name '*-journal' \) -delete
rm -f public/webtools.php public/webtools.config.php
cd ..
zip -qr site.zip site -x 'site/.git/*' 'site/.github/*' 'site/.claude/*' \
  'site/tests/*' 'site/docker/*' 'site/node_modules/*' 'site/backups/*' \
  'site/composer.local.json' '*.DS_Store'
ls -lh site.zip
```

Why:

- Session files, compiled templates and logs from your machine would only
  be stale on the host, which recreates them.
- `public/webtools.php` and `public/webtools.config.php` are the Phalcon
  developer tools' web entry point. They sit in the web root, and the config
  file contains a hard-coded developer path. Never upload them.
- `.encryption_key` only exists if you saved an external connection in
  Configuration. If it exists, keep it in the zip: saved credentials can't
  be decrypted without it.

What's in the zip:

| Path | Needed on the host? | Web-served? |
|---|---|---|
| `public/` | yes | **yes, the only folder that is** |
| `app/` (including `config/config.local.php`) | yes | never |
| `vendor/` | yes | never |
| `db/*.sqlite` | yes | **never** |
| `run`, `bin/` | yes (cron) | never |
| `cache/volt/`, `logs/`, `sessions/`, `storage/` | yes, as empty folders | never |

Nothing in the built folder holds a path from your machine, so it works
unchanged at `/home/USER/example.com`.

## Part B: on the host

### 8. Upload and unpack

1. File manager → `/home/USER`. Upload `site.zip`. (PHP's
   `upload_max_filesize` limits uploads *through the app*, not the host's
   file manager. If the file manager refuses a large zip, use FTP.)
2. *Extract* it, giving `/home/USER/site`.
3. Move its contents into `/home/USER/example.com`, or rename the folder to
   that. `/home/USER/example.com/public/index.php` must exist.
4. Delete `site.zip` from the server.

### 9. Permissions

The site runs as your hosting user, so **755 for folders and 644 for
files** is enough. Check these folders are 755 and owned by `USER`: `db`,
`cache/volt`, `logs`, `sessions`, `storage`, `backups` (create `backups`
if it's missing).

`db` matters most. SQLite writes a temporary journal file *next to* the
database while it saves, so the folder must be writable, not just the
`.sqlite` files. "attempt to write a readonly database" or "unable to open
database file" means `db` or a file in it isn't writable by `USER`. Never
use 777.

### 10. PHP version

Make sure the site runs on PHP 8.3+ with the extensions from "What you
need". If the account default is 8.3+ with them enabled, there's nothing to
do.

### 11. Point the domain at `public/`

In the host's domain settings, set the **document root** of `example.com`
to `example.com/public`. That keeps `db/`, `app/` and `vendor/` out of the
web root. (The skeleton's root `.htaccess` also routes everything into
`public/` if the document root is left at the folder, but don't rely on
it.)

Check: `https://example.com/` loads the landing page, and
`https://example.com/db/app.sqlite` returns the site's 404, not a download.
Log in at `/backend` with the account from step 5.

### 12. Cron

Add one cron job, every 5 minutes:

```
*/5 * * * * /path/to/php /home/USER/example.com/run cron run >/dev/null 2>&1
```

Pick the PHP binary deliberately:

- **A version-pinned binary** stays on that version even if the account's
  default changes later. On CloudLinux/cPanel hosts it's
  `/opt/alt/phpXX/usr/bin/php`, so `/opt/alt/php83/usr/bin/php` for 8.3.
  This is the safe choice.
- **The default binary** (often `/usr/local/bin/php` or `/usr/bin/php`)
  follows the account's selected version. It works while that is 8.3+, but
  it switches silently when the default changes.

Once, before relying on it, add a one-off job
`/path/to/php -m > /home/USER/php-cron-check.txt`, let it run, check the
file lists `phalcon` and `pdo_sqlite`, then delete the job and the file.

The cron runner runs the scheduled jobs, including the daily backup:
`./run backup run` writes compacted, gzipped copies of every database file
into `backups/` and keeps 14 days.

### 13. Mail

Signup verification and password-reset emails go through Resend
(`mail.resend_api_key` in `config.local.php`). Without a key they're logged
and skipped. For a demo or training site you can leave it out, and verify
accounts with the SQL from step 5.

## Part C: re-seed or reset

Do it on your machine and re-upload the database files; the code on the
host stays as it is.

- **Start clean:** `rm db/*.sqlite`, then steps 4–6 again (and reload your
  own demo data, if any).

Then upload the files from `db/` (`app.sqlite` and any schema files, see
"Adding modules") into `/home/USER/example.com/db/`, overwriting, all
together. Anything visitors changed on the live site since the last upload
is replaced.

If the code changed, rebuild from step 3 and upload the whole folder again
(steps 7–8).

## Adding modules

Modules are Composer packages with a `module.json` manifest (see
[MODULE-SPEC.md](MODULE-SPEC.md) and [INTERNAL-MODULES.md](INTERNAL-MODULES.md)).

### Installing them into the build

Put your module repositories next to the skeleton (e.g.
`~/build/your-modules/`) and create `composer.local.json` in the site folder
**before step 3**:

```json
{
    "repositories": [
        { "type": "path", "url": "../your-modules/*", "options": { "symlink": false } }
    ],
    "require": {
        "your-vendor/your-module": "*"
    }
}
```

A `vcs` repository (`{ "type": "vcs", "url": "https://..." }`) works too,
for modules that live in their own git repos.

`"symlink": false` matters: Composer must copy each module into `vendor/`.
A symlink would point at a folder that doesn't exist on the host. After
installing, `ls -l vendor/your-vendor` must show real folders, not `->`
links. Install with
`composer update --no-dev --optimize-autoloader 'your-vendor/*'`. Composer
may first print `Pattern "your-vendor/*" listed for update does not match
any locked packages.` That's expected: the lock file doesn't list your
modules, and the merge plugin adds them in a second pass. Never commit the
`composer.lock` this produces.

Each module needs to ship SQLite migrations: `module.json` names its
migrations folder (`"migrations": "migrations"`), and `./run migrate run`
applies `<that folder>/sqlite/*.sql` when the adapter is SQLite (and
`<that folder>/postgresql/*.sql` on Postgres). A module with only a
`postgresql/` folder installs with no tables on SQLite.

### Modules that use Postgres schemas

If a module's SQL uses schema-qualified names (`sales.orders`), list the
schemas in the config:

```php
'database' => [
    'adapter' => 'Sqlite',
    'dbname'  => BASE_PATH . '/db/app.sqlite',
    'schemas' => ['sales'],
],
```

Each listed schema becomes its own database file next to the main one
(`db/app.sales.sqlite`), attached under the schema's name on every
connection, so `sales.orders` works unchanged. Its path follows `dbname`,
so it moves with the folder. Upload these files with `app.sqlite`. No
schemas are attached unless listed. Missing one shows up as
`unknown database <name>` during migration.

### Porting a module's SQL to SQLite (for module authors)

Write `migrations/sqlite/` as a port of `migrations/postgresql/`, file for
file, same names. Start each with a comment naming the Postgres file it
mirrors. Typical changes: `SERIAL`/`BIGSERIAL PRIMARY KEY` → `INTEGER
PRIMARY KEY AUTOINCREMENT`; `TIMESTAMPTZ` → `TIMESTAMP`; `JSONB` → `TEXT`;
`NUMERIC(p,s)` → `DECIMAL(p,s)`; `now()` defaults → `CURRENT_TIMESTAMP`.
Also:

- **Indexes and references in a schema:** `CREATE INDEX sales.idx ON orders
  (...)`, with the schema on the index name, not the table. `REFERENCES`
  must name a table in the same file, unqualified.
- **One statement per `ALTER TABLE`.** SQLite can't alter a column type,
  default or constraint in place. For a fresh install, create the final
  shape in the earlier SQLite file and make the later one a commented
  no-op (`SELECT 1;`).
- **Views and triggers** may only use tables in their own file, so write
  their bodies without a schema prefix. `LATERAL` joins become
  `ROW_NUMBER() OVER (PARTITION BY ...)` subqueries; stored functions
  either become inline expressions or aren't available.

What the SQLite connection handles automatically in your PHP code's raw SQL
(`App_skeleton\Db\SqliteAdapter`, `SqliteDialect`, `PgSqlTranslator`):

- `ILIKE`, `::type` casts, `FOR UPDATE`, `+/- INTERVAL 'n unit'`,
  `UPDATE table alias SET`, and `~*`/`~` against a bound pattern are
  rewritten.
- These are available as functions: `now()`, `greatest()`, `least()`,
  `split_part()`, `btrim()`, `left()`, `right()`, `initcap()`,
  `date_trunc()`, `md5()`, `similarity()`, `regexp()`/`iregexp()`,
  `regexp_substr()`, and `pg_try_advisory_lock()`/`pg_advisory_unlock()`.
- `RETURNING`, `ON CONFLICT`, `FILTER (WHERE ...)`, `IS DISTINCT FROM`,
  `NULLS LAST` and window functions are native in SQLite 3.39+.
- PHP booleans are bound as 1/0.
- Models with `setSchema()` find their tables in attached schemas, and a
  model may sit on a view.

What needs an explicit branch, keeping the Postgres query exactly as it
is:

```php
if ($this->db->getType() === 'sqlite') {
    // SQLite version
} else {
    // the existing Postgres query, unchanged
}
```

Use one for `DISTINCT ON`, arrays (`ANY(:ids)`, `@>`, `array_agg(...)[1]`),
`LATERAL`, materialized views (`REFRESH MATERIALIZED VIEW` → rebuild a
plain table), trigram operators/indexes, and calls to stored Postgres
functions. Functions registered by the connection only exist inside the
app. A view that uses them can't be queried from the bare `sqlite3` tool.

## Known differences from a Postgres install

- One writer at a time. The connection waits up to 5 seconds for a lock
  rather than failing. That's fine for a demo, training or small site, but
  not for a busy one.
- Booleans are stored and returned as `1`/`0`.
- `now()` and PHP-written timestamps use PHP's timezone; column defaults
  (`CURRENT_TIMESTAMP`) are UTC.
- Timestamps have no fractional seconds.
