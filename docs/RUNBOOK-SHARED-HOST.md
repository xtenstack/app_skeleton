# Runbook: build locally, upload to shared hosting (SQLite, MySQL/MariaDB, PostgreSQL)

One flow for all three databases. You build the site (and any modules) on
your own machine, check it there, then upload it once to a cPanel-style
host. The host never runs Composer, and you don't need SSH on it.

Where the steps differ by database, the step has marked blocks:

> **▶ SQLite** · **▶ MySQL/MariaDB** · **▶ PostgreSQL**

Everything else is the same for all three. Follow only the blocks for your
database.

Placeholders:

| Placeholder | Meaning |
|---|---|
| `~/build` | a folder on your machine (any path) |
| `example.com` | your domain or subdomain |
| `USER` | your hosting account's username |
| `/home/USER/example.com` | the site's folder on the host |
| `USER_app` | the database (and database user) you create on the host |

Which database to pick:

- **SQLite:** no database server. The database is a file inside the site
  folder. Simplest, and fine for a demo, training or small site, but only
  one writer at a time.
- **MySQL/MariaDB:** what nearly every cPanel host offers. Good for a real
  site on shared hosting. Needs MySQL 8.0+ or MariaDB 10.6+.
- **PostgreSQL:** the skeleton's primary database and what its Docker
  setup uses. Few shared hosts offer it; a VPS is the usual home.

## What you need

- **PHP 8.3 or newer**, on your machine and on the host, with `phalcon`
  (5.x, sometimes listed as `phalcon5`), `psr` (if your platform packages
  Phalcon with it), `mbstring`, `intl`, `curl`, `openssl`, `zlib`, the
  usual built-ins (`ctype`, `json`, `session`), and the driver for your
  database:

  > **▶ SQLite:** `pdo_sqlite`.
  >
  > **▶ MySQL/MariaDB:** `pdo_mysql` (with `mysqlnd`).
  >
  > **▶ PostgreSQL:** `pdo_pgsql`.

  Check with `php -m` locally. On the host, a one-line `<?php phpinfo();`
  page shows the version and extensions (delete it straight afterwards).
  Many shared hosts (CloudLinux *Select PHP Version* and similar) choose
  the PHP version and tick extensions per version for the whole account:
  pick 8.3+ there and tick the extensions above for it.
- **Composer** and **git** on your machine.
- A database to build against locally:

  > **▶ SQLite:** nothing. Install the `sqlite3` command-line tool.
  >
  > **▶ MySQL/MariaDB:** a local MySQL 8.0+ or MariaDB 10.6+ server and its
  > `mysql` client. Ideally use the same family as the host (cPanel hosts
  > usually run MariaDB).
  >
  > **▶ PostgreSQL:** a local PostgreSQL server (the version you'll run on
  > the server) and `psql`/`pg_dump`.
- FTP/SFTP or the host's file manager for the upload, plus the host's
  database tool (phpMyAdmin or phpPgAdmin) for MySQL/PostgreSQL.

## Part A: on your machine

### 1. Get the code

```bash
mkdir -p ~/build && cd ~/build
git clone https://github.com/xtenstack/app_skeleton.git site
cd site
```

### 2. Create the local database

> **▶ SQLite:** nothing to do. Step 5 creates the file.
>
> **▶ MySQL/MariaDB:** create a database and a user for it. Use
> `utf8mb4_unicode_ci`: MySQL 8's own default collation
> (`utf8mb4_0900_ai_ci`) doesn't exist on MariaDB, so a dump made with it
> won't import there.
>
> ```sql
> CREATE DATABASE app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
> CREATE USER 'app'@'localhost' IDENTIFIED WITH mysql_native_password BY 'choose-a-password';
> GRANT ALL PRIVILEGES ON app.* TO 'app'@'localhost';
> ```
>
> (On MariaDB, write `IDENTIFIED BY 'choose-a-password'`: native
> passwords are its default.) `mysql_native_password` avoids a common
> local snag with MySQL 8. MySQL 8's default
> login method is `caching_sha2_password`. Some PHP builds' `mysqlnd`
> don't include it, and connecting then fails with `SQLSTATE[HY000] [2054]
> The server requested authentication method unknown to the client
> [caching_sha2_password]`. The fix is either a native-password user as
> above (the server needs `mysql_native_password=ON` on MySQL 8.4), or a
> PHP build whose `mysqlnd` has the plugin. MariaDB doesn't use
> `caching_sha2_password`, so it isn't affected.
>
> **▶ PostgreSQL:**
>
> ```bash
> createuser --pwprompt app
> createdb --owner app app
> ```

### 3. Point the site at the database

Create `app/config/config.local.php`. Use `BASE_PATH` for anything that is
a path, never an absolute path like `/home/you/...`: `BASE_PATH` is worked
out at runtime from wherever the folder is, so the same file works on your
machine and on the host.

> **▶ SQLite:**
>
> ```php
> <?php
> return [
>     'database' => [
>         'adapter' => 'Sqlite',
>         'dbname'  => BASE_PATH . '/db/app.sqlite',
>     ],
> ];
> ```
>
> **▶ MySQL/MariaDB:**
>
> ```php
> <?php
> return [
>     'database' => [
>         'adapter'  => 'Mysql',
>         'host'     => 'localhost',
>         'port'     => 3306,
>         'dbname'   => 'app',
>         'username' => 'app',
>         'password' => 'choose-a-password',
>         // Or connect over a Unix socket instead of host/port:
>         // 'socket' => '/path/to/mysqld.sock',
>     ],
> ];
> ```
>
> With `'host' => 'localhost'`, PHP connects over the server's default
> Unix socket, not TCP. That's right on most hosts. If a local server's
> socket isn't where PHP expects it ("No such file or directory"), set
> `socket` to the path the server reports (`SHOW VARIABLES LIKE 'socket'`),
> or use `'host' => '127.0.0.1'` for TCP. The connection always uses
> utf8mb4 / `utf8mb4_unicode_ci`, strict SQL mode and PHP's time zone,
> whatever the server's defaults are.
>
> **▶ PostgreSQL:**
>
> ```php
> <?php
> return [
>     'database' => [
>         'adapter'  => 'Postgresql',
>         'host'     => 'localhost',
>         'port'     => 5432,
>         'dbname'   => 'app',
>         'username' => 'app',
>         'password' => 'choose-a-password',
>     ],
> ];
> ```

Leave the mail settings out for now (step 16).

### 4. Add modules (optional)

If the site uses modules, set them up now, before installing: see
[Adding modules](#adding-modules). A module needs migrations for your
database (`migrations/sqlite/`, `migrations/mysql/` or
`migrations/postgresql/`).

### 5. Install

```bash
composer install --no-dev --optimize-autoloader
```

Composer installs the dependencies, then runs the skeleton's install
script: `applying migrations...`, `seeding defaults...`, `syncing module
registry...`, `install complete.` With step 3's config in place, that
already builds the database.

### 6. Build the database

Run the same steps explicitly. Each one only does what isn't done yet:

```bash
./run migrate run
./run seed run
./run modules sync
```

Expect `No pending migrations.` (or `Migrations complete (22 applied).` if
the install script didn't run), `Seeding complete.` and `Sync complete.`.
Enable any modules with `./run modules enable <key>`.

### 7. Create the first admin

No admin is seeded, on purpose. There are three parts, in this order, and
the last does nothing unless the first has happened.

Start the local server in its own terminal tab and leave it running:

```bash
php -S localhost:8091 -t public bin/dev-router.php
```

**7a. Sign up in the browser.** Open http://localhost:8091/backend/signup,
fill in the email and password you'll use on the live site, and submit.

**7b. Check the account exists, and copy its email exactly.** Run this
query:

```sql
SELECT email, role_id FROM users;
```

> **▶ SQLite:** `sqlite3 db/app.sqlite "SELECT email, role_id FROM users;"`
>
> **▶ MySQL/MariaDB:** `mysql -u app -p app -e "SELECT email, role_id FROM users;"`
>
> **▶ PostgreSQL:** `psql -U app -d app -c "SELECT email, role_id FROM users;"`

You must see one row with your email. No rows means the signup didn't go
through: do 7a again. Check the email carefully. A typo at signup means
the next command matches nothing and silently does nothing.

**7c. Make it an admin and mark it verified**, with the email exactly as
7b showed it, the same way as 7b:

```sql
UPDATE users SET role_id = (SELECT id FROM roles WHERE name = 'admin'),
       email_verified_at = CURRENT_TIMESTAMP
 WHERE email = 'you@example.com';
SELECT email, role_id FROM users;
```

The row must now show the admin role (`1`). If not, the email didn't match: copy it from
the SELECT output and run the UPDATE again.

### 8. Check it locally

Log in at http://localhost:8091/backend and click through the dashboard,
Tickets, KB articles, Users, Configuration and Settings (and your modules'
pages). Every page should load; if one doesn't, check `logs/app.log`. Stop
the server (Ctrl+C).

### 9. Export the data

> **▶ SQLite:** nothing to export. The database is `db/app.sqlite` (plus
> any schema files, see [Adding modules](#adding-modules)), and it travels
> inside the zip.
>
> **▶ MySQL/MariaDB:** make an import file with the app's own backup task:
>
> ```bash
> ./run backup run
> ```
>
> It writes `backups/<dbname>-<date>.sql.gz`, using `mysqldump` if it's
> on your PATH and otherwise PHP. Either way the file has no `CREATE
> DATABASE` or `USE` line, so it imports into whatever the host calls
> your database. (Plain `mysqldump --single-transaction --no-tablespaces
> app | gzip > app.sql.gz` works too. With MySQL's client, add
> `--set-gtid-purged=OFF`.)
>
> **▶ PostgreSQL:**
>
> ```bash
> pg_dump --no-owner --no-privileges -U app app | gzip > app.sql.gz
> ```

### 10. Package it

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
  developer tools' web entry point. They sit in the web root and contain a
  hard-coded developer path. Never upload them.
- `.encryption_key` only exists if you saved an external connection in
  Configuration. If it exists, keep it in the zip: saved credentials can't
  be decrypted without it.
- `backups/` stays out of the zip. For MySQL/PostgreSQL, upload the export
  from step 9 separately (step 13).

What's in the zip:

| Path | Needed on the host? | Web-served? |
|---|---|---|
| `public/` | yes | **yes, the only folder that is** |
| `app/` (including `config/config.local.php`) | yes | never |
| `vendor/` | yes | never |
| `run`, `bin/` | yes (cron) | never |
| `cache/volt/`, `logs/`, `sessions/`, `storage/` | yes, as empty folders | never |
| `db/*.sqlite` (**▶ SQLite** only) | yes | **never** |

Nothing in the built folder holds a path from your machine, so it works
unchanged at `/home/USER/example.com`.

## Part B: on the host

### 11. Create the database on the host

> **▶ SQLite:** nothing to do.
>
> **▶ MySQL/MariaDB:** cPanel → *MySQL® Databases* (or *Manage My
> Databases*):
>
> 1. Create a database, e.g. `app`. cPanel names it `USER_app`.
> 2. Create a user, e.g. `app`, giving `USER_app`, with a strong password.
> 3. *Add User To Database* → *ALL PRIVILEGES*.
>
> Note the full prefixed names. The host decides the server's default
> collation, which doesn't matter: the import file sets
> `utf8mb4_unicode_ci` on every table.
>
> **▶ PostgreSQL:** cPanel → *PostgreSQL Databases*, where the host offers
> it: create a database and a user and add the user to it, as for MySQL.
> Most shared hosts don't offer PostgreSQL. Use a VPS with PostgreSQL
> instead (or the skeleton's Docker setup, see [INSTALL.md](INSTALL.md)),
> and follow the rest of this runbook with SSH in place of the file
> manager.

### 12. Upload and unpack

1. File manager → `/home/USER`. Upload `site.zip`. (PHP's
   `upload_max_filesize` limits uploads *through the app*, not the host's
   file manager. If the file manager refuses a large zip, use FTP.)
2. *Extract* it, giving `/home/USER/site`.
3. Move its contents into `/home/USER/example.com`, or rename the folder to
   that. `/home/USER/example.com/public/index.php` must exist.
4. Delete `site.zip` from the server.

### 13. Connect to the host's database and load the data

> **▶ SQLite:** nothing to do. The database came in the zip.
>
> **▶ MySQL/MariaDB:**
>
> 1. File manager → edit `/home/USER/example.com/app/config/config.local.php`:
>    `dbname` and `username` become the prefixed names from step 11,
>    `password` the host user's password, `host` `localhost`. Remove
>    `socket` if you set one.
> 2. phpMyAdmin → select `USER_app` → *Import* → choose the
>    `.sql.gz` from step 9 → *Import*. It creates every table and loads
>    your admin account, settings and data.
>
> Importing is the practical route with no SSH: the database is already
> built and checked, including the first admin. An **empty** install can
> instead be built on the host by a one-off cron job
> (`/path/to/php /home/USER/example.com/run migrate run`, then `seed run`
> and `modules sync`, then delete the job), with the first admin done
> through phpMyAdmin's SQL tab as in step 7.
>
> **▶ PostgreSQL:** as for MySQL: edit `config.local.php`, then import
> with phpPgAdmin where the host offers it, or on a VPS
> `gunzip -c app.sql.gz | psql -U app -d app`.

### 14. Permissions

The site runs as your hosting user, so **755 for folders and 644 for
files** is enough. Check these folders are 755 and owned by `USER`:
`cache/volt`, `logs`, `sessions`, `storage`, `backups` (create `backups` if
it's missing).

> **▶ SQLite:** also `db`, and it matters most. SQLite writes a temporary
> journal file *next to* the database while it saves, so the folder must
> be writable, not just the `.sqlite` files. "attempt to write a readonly
> database" or "unable to open database file" means `db` or a file in it
> isn't writable by `USER`.

Never use 777.

### 15. PHP version and document root

1. Make sure the site runs on PHP 8.3+ with the extensions from "What you
   need". If the account default is 8.3+ with them enabled, there's
   nothing to do.
2. In the host's domain settings, set the **document root** of
   `example.com` to `example.com/public`. That keeps `app/`, `vendor/` and
   `db/` out of the web root. (The skeleton's root `.htaccess` also routes
   everything into `public/` if the document root is left at the folder,
   but don't rely on it.)

Check: `https://example.com/` loads the landing page. Log in at `/backend`
with the account from step 7. For SQLite, also check that
`https://example.com/db/app.sqlite` returns the site's 404, not a
download.

### 16. Cron and mail

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
file lists `phalcon` and your database driver, then delete the job and the
file.

The cron runner runs the scheduled jobs, including the daily backup into
`backups/` (14 days kept):

> **▶ SQLite:** a compacted, gzipped copy of each database file.
>
> **▶ MySQL/MariaDB:** a gzipped `.sql` dump, made with `mysqldump` if the
> host lets PHP run it and with PHP otherwise. Restore by importing it in
> phpMyAdmin.
>
> **▶ PostgreSQL:** a gzipped `pg_dump`. `pg_dump` must be installed where
> the cron job runs.

**Mail:** signup verification and password-reset emails go through Resend
(`mail.resend_api_key` in `config.local.php`). Without a key they're logged
and skipped. For a demo or training site you can leave it out and verify
accounts with the SQL from step 7.

## Part C: re-seed or reset

Do it on your machine, then replace the data on the host. The code on the
host stays as it is.

1. Locally, start clean:

   > **▶ SQLite:** `rm db/*.sqlite`
   >
   > **▶ MySQL/MariaDB:** `DROP DATABASE app; CREATE DATABASE app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
   >
   > **▶ PostgreSQL:** `dropdb app && createdb --owner app app`

2. Steps 6–8 again (and reload your own demo data, if any), then step 9.
3. On the host:

   > **▶ SQLite:** upload the files from `db/` into
   > `/home/USER/example.com/db/`, overwriting, all together.
   >
   > **▶ MySQL/MariaDB:** phpMyAdmin → `USER_app` → select all tables →
   > *Drop*, then *Import* the new file.
   >
   > **▶ PostgreSQL:** drop and recreate the database (or its tables), then
   > import the new dump.

Anything visitors changed on the live site since the last upload is
replaced. If the code changed, rebuild from step 5 and upload the whole
folder again (steps 10 and 12).

## Adding modules

Modules are Composer packages with a `module.json` manifest (see
[MODULE-SPEC.md](MODULE-SPEC.md) and [INTERNAL-MODULES.md](INTERNAL-MODULES.md)).

### Installing them into the build

Put your module repositories next to the skeleton (e.g.
`~/build/your-modules/`) and create `composer.local.json` in the site
folder **before step 5**:

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

`module.json` names the module's migrations folder
(`"migrations": "migrations"`), and `./run migrate run` applies
`<that folder>/<database>/*.sql`: `sqlite/`, `mysql/` or `postgresql/`.
A module without a folder for your database installs with no tables.

### Modules that use Postgres schemas

> **▶ SQLite:** if a module's SQL uses schema-qualified names
> (`sales.orders`), list the schemas in the config:
> `'schemas' => ['sales']` in the `database` block. Each listed schema
> becomes its own file next to the main one (`db/app.sales.sqlite`),
> attached under the schema's name on every connection, so `sales.orders`
> works unchanged. No schemas are attached unless listed. A missing one
> shows up as `unknown database <name>` during migration.
>
> **▶ MySQL/MariaDB:** MySQL has no schemas inside a database, and
> `sales.orders` means a table in another *database* named `sales`, which
> a shared-host user usually can't create. Port such a module with
> prefixed table names (`sales_orders`) in its `mysql/` migrations and a
> `getType() === 'mysql'` branch in its code, or keep it on PostgreSQL or
> SQLite.
>
> **▶ PostgreSQL:** schemas work natively.

### Porting a module's migrations (for module authors)

Write `migrations/sqlite/` and `migrations/mysql/` as ports of
`migrations/postgresql/`, file for file, same names, each starting with a
comment naming the Postgres file it mirrors.

| Postgres | ▶ SQLite | ▶ MySQL/MariaDB |
|---|---|---|
| `SERIAL PRIMARY KEY` | `INTEGER PRIMARY KEY AUTOINCREMENT` | `INT NOT NULL AUTO_INCREMENT PRIMARY KEY` (not MySQL's `SERIAL`, which is `BIGINT UNSIGNED` and won't match `INTEGER` foreign keys) |
| `TIMESTAMP` / `TIMESTAMPTZ` | `TIMESTAMP` | `DATETIME` (no 2038 limit, no MariaDB auto-update quirks) |
| `BOOLEAN ... DEFAULT false` | `BOOLEAN ... DEFAULT 0` | `TINYINT(1) ... DEFAULT 0` |
| `JSONB` | `TEXT` | `JSON` (an alias of `LONGTEXT` on MariaDB) or `TEXT` |
| `NUMERIC(p,s)` | `DECIMAL(p,s)` | `DECIMAL(p,s)` |
| column-level `REFERENCES t(id)` | fine | **silently ignored by MySQL**: write a table-level `FOREIGN KEY (...) REFERENCES t(id)` |
| `CREATE ... INDEX ... WHERE ...` (partial) | supported | not supported. For "unique among live rows", add a stored generated column that is NULL when the row doesn't count (`AS (CASE WHEN deleted_at IS NULL THEN name END) STORED`) and a UNIQUE index on it. A plain UNIQUE index already allows many NULLs |
| index on `LOWER(x)` | supported | not on MariaDB. The `utf8mb4_unicode_ci` collation already compares case-insensitively |
| `ALTER TABLE ... ALTER COLUMN TYPE/SET DEFAULT`, `DROP CONSTRAINT` | not supported: create the final shape earlier, make the later file a commented no-op | supported (`MODIFY COLUMN`, `ALTER COLUMN ... SET DEFAULT`, `DROP CHECK`/`DROP FOREIGN KEY`) |
| `CHECK` constraints | supported | enforced on MySQL 8.0.16+ and MariaDB 10.2+ |
| `CREATE SCHEMA`, `schema.table` | attached files (see above) | see above |
| stored functions, `LATERAL`, materialized views | not available: inline expressions, window functions, plain tables | inline expressions, window functions (MySQL 8 / MariaDB 10.2+), plain tables. `LATERAL` needs MySQL 8.0.14+ and doesn't exist on MariaDB |

Also for MySQL:

- End table definitions with
  `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.
- Keep indexed `VARCHAR` columns at 768 characters or fewer (3072 bytes
  in utf8mb4). Don't make `TEXT` columns unique.
- Quote reserved words with backticks: `key`, `order`, `rank`, `groups`
  and others are reserved in MySQL but not in Postgres.
- DDL isn't transactional: a migration that fails halfway leaves its
  earlier statements applied, so keep each file small and re-runnable
  where you can (`IF NOT EXISTS`).

What the connection handles automatically in your PHP code's raw SQL:

- **▶ SQLite** (`App_skeleton\Db\SqliteAdapter`, `SqliteDialect`,
  `PgSqlTranslator`) rewrites `ILIKE`, `::type` casts, `FOR UPDATE`,
  `+/- INTERVAL 'n unit'`, `UPDATE table alias SET` and `~*`/`~` against a
  bound pattern. It registers `now()`, `greatest()`, `least()`,
  `split_part()`, `btrim()`, `left()`, `right()`, `initcap()`,
  `date_trunc()`, `md5()`, `similarity()`, `regexp()`/`iregexp()`,
  `regexp_substr()` and the advisory-lock functions as PHP functions.
  Models with `setSchema()` find attached schemas, and a model may sit on
  a view.
- **▶ MySQL/MariaDB** (`App_skeleton\Db\MysqlAdapter`,
  `MysqlSqlTranslator`) rewrites `ILIKE` (to `LIKE`, case-insensitive under
  the collation), `::type` casts (to `CAST(... AS CHAR/SIGNED/DECIMAL/DATE/
  DATETIME)`), `INTERVAL 'n unit'` (to `INTERVAL n UNIT`), `~*`/`~` against
  a bound pattern (to `REGEXP`, case-insensitive), and `NULLS FIRST/LAST`
  on simple column sorts. It sets utf8mb4, strict mode and PHP's time zone
  on every connection. `now()`, `GREATEST()`, `LEAST()`, `FOR UPDATE`,
  `UPDATE t alias SET` and window functions are native.
- Both bind PHP booleans as 1/0.

What needs an explicit branch in PHP, keeping the Postgres query exactly
as it is:

```php
$type = $this->db->getType(); // 'postgresql', 'mysql' or 'sqlite'
```

- `RETURNING`: not on MySQL; MariaDB has it for `INSERT`/`DELETE` only. Use
  `lastInsertId()` or a follow-up SELECT.
- `ON CONFLICT ... DO UPDATE/NOTHING`: MySQL uses `INSERT ... ON DUPLICATE
  KEY UPDATE` or `INSERT IGNORE`. SQLite supports `ON CONFLICT`.
- `DISTINCT ON`: use `ROW_NUMBER() OVER (PARTITION BY ...)`.
- Arrays (`ANY(:ids)`, `@>`, `array_agg(...)[1]`): expand `IN (...)` lists
  and use window functions.
- `IS DISTINCT FROM`: `NOT (a <=> b)` on MySQL.
- Materialized views, trigram operators/indexes, stored Postgres functions,
  and advisory locks. The skeleton's cron runner uses `GET_LOCK()` on
  MySQL.

## Known differences from a Postgres install

> **▶ SQLite:**
>
> - One writer at a time; the connection waits up to 5 seconds for a lock.
>   Fine for a demo, training or small site.
> - Booleans are stored as `1`/`0`. Timestamps have no fractional seconds.
>   Column defaults (`CURRENT_TIMESTAMP`) are UTC, while PHP-written times
>   use PHP's time zone.
>
> **▶ MySQL/MariaDB:**
>
> - Migrations aren't atomic (DDL commits immediately). If one fails,
>   fix the cause, check which of its statements already ran, and re-run.
> - Text comparison, `LIKE` and unique indexes are case-insensitive
>   (`utf8mb4_unicode_ci`). Two KB enquiry types named "Billing" and
>   "billing" can't both be live, where Postgres would allow it.
> - Booleans are `TINYINT(1)` (`1`/`0`). Timestamps are `DATETIME` in PHP's
>   time zone, with no fractional seconds.
> - `external_connections` and `kb_enquiry_types` each have one extra
>   read-only column, `name_active`, which backs the "unique among live
>   rows" rule.
>
> **▶ PostgreSQL:** the reference behaviour; nothing to note.
