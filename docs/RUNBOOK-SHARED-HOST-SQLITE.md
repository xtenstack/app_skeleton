# Runbook: install on cPanel shared hosting with SQLite

Step-by-step install of the skeleton on an ordinary cPanel shared host, with
SQLite as the database. There's no Docker and no Postgres, and you don't need
SSH. Written to be followed on camera: each step says what you do, what you
should see, and what to do if you don't.

**Status:** Part 1 (the base skeleton) is verified locally. Parts 2 and 3 (the
internal modules for a stack-internal/xtmk-style demo, and scaled-down sample
data) are still being built. They are marked **planned** below.

## What you need

| Thing | Why | How to check |
|---|---|---|
| cPanel host with **PHP 8.3+** selectable per domain | the skeleton needs `^8.3` | cPanel → *Select PHP Version* (CloudLinux) or *MultiPHP Manager* |
| **Phalcon 5.x** extension enabled for that PHP version | the framework is the extension | same screen → *Extensions* tab → tick `phalcon` (and `psr` if listed) |
| `pdo_sqlite` enabled | the database driver | same *Extensions* tab |
| A build machine with PHP 8.3 + Phalcon + Composer + git | builds `vendor/` and the database file, since the host can't | `php -m \| grep -iE 'phalcon\|sqlite'` shows both |
| FTP/SFTP or cPanel File Manager | upload | |
| Optional: cPanel *Terminal* | runs `./run` on the host; everything below also works without it | |

Reference host (XTen's, Sep 2026): CloudLinux cPanel, LiteSpeed (lsapi), PHP
8.1 by default with 8.1–8.5 available, Phalcon 5.20.3, SQLite 3.53, 128M
`memory_limit`, 30s `max_execution_time`, 2M `upload_max_filesize`.
**The default PHP 8.1 is too old.** Switch the domain to 8.3 first (step 2).

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

### 2. Prepare the domain in cPanel

1. *Domains* → create the domain or subdomain (e.g. `demo-sqlite-stack.xten.au`).
   Set its **document root to `<folder>/public`**, not the folder itself. That
   keeps `app/`, `db/`, `vendor/` and `config.local.php` off the web.
2. *Select PHP Version* → set **8.3** for that domain. On the *Extensions*
   tab, make sure `phalcon` and `pdo_sqlite` are ticked.
3. Optional: raise upload limits with a `.user.ini` in `public/` (e.g.
   `upload_max_filesize = 20M`, `post_max_size = 24M`). It takes up to 5
   minutes to apply (`user_ini.cache_ttl`).

### 3. Upload

Zip the built tree (including `vendor/` and `db/app.sqlite`), upload it
with File Manager, and *Extract* it into the domain's folder. This is much
faster than FTPing thousands of `vendor/` files one at a time. If the zip is
over the host's upload limit, split it or use FTP for `vendor/`.

Then fix the folder permissions. The web server runs as your cPanel user, so
**755 on folders and 644 on files** is enough. These must be writable:
`db/` (the folder, not just the file, because SQLite writes a journal next to
it), `cache/volt/`, `logs/`, `sessions/`, `storage/` and `public/temp/`.

### 4. First visit and first admin

1. Open `https://<domain>/`. You should see the landing page. A blank page
   or 500 almost always means the PHP version or Phalcon (step 2). Check
   cPanel → *Errors*, or `logs/`.
2. Go to `/backend/signup` and create your account.
3. Make it an admin. No admin user is seeded, deliberately. With cPanel
   Terminal:

   ```bash
   sqlite3 db/app.sqlite "UPDATE users SET role_id=(SELECT id FROM roles WHERE name='admin'), email_verified_at=CURRENT_TIMESTAMP WHERE email='you@example.com';"
   ```

   Without Terminal: do this on your build machine *before* uploading
   (sign up against a local `php -S` run of the same tree), or download
   `db/app.sqlite`, run the command locally and upload it back.
4. Log in at `/backend`. The dashboard, Tickets, Users, Configuration,
   Audit log and Settings pages should all load.

### 5. Scheduled jobs

cPanel → *Cron Jobs*, every 5 minutes (use the PHP 8.3 binary's full path;
on CloudLinux it's `/opt/alt/php83/usr/bin/php`):

```
/opt/alt/php83/usr/bin/php /home/<cpanel-user>/<folder>/run cron run >/dev/null 2>&1
```

`./run backup run` uses `pg_dump` and **does not work with SQLite yet**.
Until it does, back up by copying `db/app.sqlite` (for example a daily cron
running `sqlite3 db/app.sqlite ".backup db/backup-$(date +\%a).sqlite"`).

### 6. Outgoing mail

Signup verification and password reset send mail through Resend
(`mail.resend_api_key` in `config.local.php`). For a demo, leave it unset
and verify accounts with the SQL in step 4.

## Part 2: internal modules (stack-internal / xtmk style) — planned

The internal modules (directory, requirements, licensing, kpi, marketing)
each ship `migrations/postgresql/` and use Postgres-only SQL in their code
(`ILIKE`, `::` casts, `DISTINCT ON`, materialized views, trigram search).
Each one needs a `migrations/sqlite/` set plus code changes before it runs
here. Tracked separately; this section will list them in install order once
they're done.

## Part 3: scaled-down sample data — planned

A sampler that exports a small, consistent slice of production (a few
hundred contacts, prospects, campaigns and tickets) into the SQLite file,
plus `./run seed run`-style loading. Until then, the demo starts empty.

## Known differences from a Postgres install

- `module_registry.enabled` stores `false` as an empty string on SQLite.
  `enabled = true` queries still work.
- No `./run backup run` (see step 5).
- SQLite allows one writer at a time. That's fine for a demo or training
  site, but not for a busy production instance.
