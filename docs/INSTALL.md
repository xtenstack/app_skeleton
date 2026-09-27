# Install

Three ways to get a running instance, in order of how most people should
actually do this.

## Docker (recommended)

Requires Docker and Docker Compose.

```bash
git clone <your fork or this repo>
cd app_skeleton
cp .env.example .env
# edit .env — DB_PASSWORD is required, everything else has a working default
mkdir -p data && touch data/encryption_key
docker compose up -d --build
```

This builds and starts three services: `app` (PHP-FPM), `caddy`
(reverse proxy — automatic HTTPS if `SITE_DOMAIN` is a real domain,
self-signed for local/IP testing), and `db` (Postgres). Migrations,
seed data, and module registry sync all run automatically on first
`composer install` inside the image (see `bin/install.php`) — there's
nothing extra to run by hand.

Visit `http://localhost:8080` (or whatever `HTTP_PORT` you set).

## Composer, against your own PHP + Postgres

For local development without Docker. Requires PHP 8.3+ with the
`phalcon`, `pdo_pgsql`, and `openssl` extensions, and a reachable
Postgres database.

```bash
git clone <your fork or this repo>
cd app_skeleton
composer install
```

`composer install` runs `bin/install.php` automatically (migrations →
seed → module sync), same as the Docker path — it needs
`app/config/config.local.php` pointing at a real, already-created
database first (gitignored; copy the shape from `app/config/config.php`'s
`database` block).

Serve it with the built-in dev server:

```bash
php -S localhost:8080 -t public bin/dev-router.php
```

## Plain download

Download or extract a release tarball instead of cloning, then follow
the Composer path above from that directory. There's no separate
"packaged" distribution — the repository itself is the install source.

## MySQL or SQLite instead of Postgres

All three adapters work: set `config.database.adapter` in
`app/config/config.local.php` to `Postgresql` (the default), `Mysql`
(MySQL 8.0+ or MariaDB 10.6+) or `Sqlite`, and `./run migrate run` applies
the matching `db/migrations/<adapter>/` set (`postgresql/`, `mysql/`,
`sqlite/`, one port of every base migration each) plus each module's
`migrations/<adapter>/`. Everything else (seeding, `./run modules sync`,
the app) is adapter-agnostic. Postgres is the reference: it's what the
Docker setup and CI run.

For a step-by-step build-locally, upload-to-shared-hosting install with
any of the three, see [RUNBOOK-SHARED-HOST.md](RUNBOOK-SHARED-HOST.md).
It covers the config for each adapter, what differs between them, and
porting a module's migrations to MySQL or SQLite.

## Shared hosting (no command line, no Docker)

The tested, step-by-step version of this (SQLite, MySQL/MariaDB or
PostgreSQL) is [RUNBOOK-SHARED-HOST.md](RUNBOOK-SHARED-HOST.md).

Most shared hosts don't give you SSH, Composer, or Docker — just FTP/SFTP
and a database. The pattern is: build the instance somewhere that *does*
have Composer (your own machine, a VPS, a CI runner), then upload the
already-built result.

1. On a machine with Composer + PHP 8.3 (the Composer path above, minus
   actually serving it there): `git clone`, install any optional
   modules you want (see below), `composer install`, but **point
   `app/config/config.local.php` at your shared host's real database**
   before running it — `bin/install.php` (migrations/seed/module sync)
   needs to run against the database it'll actually use, not a local
   throwaway one, since seeded data and migration state travel with
   that database, not with the uploaded files.
2. Upload the whole tree — including the now-populated `vendor/`
   directory `composer install` created — to the shared host via
   FTP/SFTP. `vendor/` is what makes this work without Composer ever
   running on the host itself.
3. Point the shared host's document root at `public/`, same as any
   other install.

### Installing optional modules this way

Same idea, one extra step, and it's the same pattern paid modules use:

1. Download the module package (a Composer package with its own
   `module.json` — see [`docs/MODULE-SPEC.md`](MODULE-SPEC.md)) to your
   build machine. A paid module means getting a license key first —
   the download/install mechanics afterward are identical to a free
   one.
2. `composer require <module-package-name>` before your `composer
   install`/upload cycle, so it lands in `vendor/` along with
   everything else.
3. `./run modules sync` **before uploading** — this registers the
   module in the database (same database `config.local.php` points at
   in step 1 above) so it shows up on the admin Configuration page
   already discovered, not just physically present in `vendor/`.
4. Upload as normal. Enable the module from the admin Configuration
   page once it's live.

### Shared hosting with no remote database access

The approach above assumes your build machine can reach the shared
host's database directly (a real, if not universal, shared-hosting
capability). **Plenty of shared hosts don't allow remote database
connections at all** — the database is only reachable from the host
itself, often only through a bundled tool like phpMyAdmin/Adminer. If
that's the case, build the database side locally instead of remotely:

1. On your build machine, point `app/config/config.local.php` at a
   **local, throwaway** database (Docker Postgres is fine) instead of
   the host's real one, then `composer install` as normal — this runs
   migrations/seed/module-sync against that local database, which
   you're about to discard; its only job is generating a known-good
   schema to copy from.
2. Export that local database's schema (and seed data, if you want the
   default roles/module registry pre-populated rather than re-syncing
   later) — `pg_dump --schema-only` for structure, `pg_dump --data-only`
   for seed rows, or the adapter-appropriate equivalent if you've
   translated migrations to MySQL/SQLite (see above).
3. On the shared host, create the actual database and import that
   dump through whatever tool the host provides — phpMyAdmin, Adminer,
   a cPanel database wizard, or a raw SQL import if you have that much
   access. This is the step that makes remote connectivity from your
   build machine irrelevant: the schema arrives as a file upload/import,
   not a live connection.
4. Update `app/config/config.local.php` (or `.env`, for the Docker
   path) to point at the host's real database credentials — same file
   you'll upload — before packaging the tree for upload.
5. Upload the tree (including `vendor/`) as in the main shared-hosting
   steps above. Since the database already has its schema and seed
   data from the import, there's no `bin/install.php` migration step
   left to run on first request against it — the app should just
   connect and work.

This is more manual than the direct-connection path (a schema dump and
a host-side import tool, instead of one `composer install` run talking
straight to the real database) but it's the only option on hosts that
block remote database access entirely — a real and common shared-hosting
constraint, not an edge case.

## Planned deployment targets

The Docker install above works on any host with Docker + Compose —
Digital Ocean and Azure are the two verified so far (see the README
badges). **IBM Cloud is planned but not yet trialled** — no
IBM-specific instructions exist yet beyond the same generic Docker
install.

## After install

- Default seeded roles: `admin`, `member`, `operator`, `agent` — no
  default user is seeded, sign up through `/backend/signup` and promote
  that account to `admin` directly in the database
  (`UPDATE users SET role_id = (SELECT id FROM roles WHERE name = 'admin') WHERE email = '...'`).
- `./run` with no arguments lists every available CLI task.
- `./run modules sync` after installing any additional module package.
- **Template modules** (optional) — `xtenstack/application-template` and
  `xtenstack/plugin-template` are blank scaffolding for building your own
  module, not features with value on their own (their demo entities,
  "Widgets" and "Tags", are deliberately trivial). Both are public
  packages in the `xtenstack/module-templates` repo. Install either with
  `composer require xtenstack/application-template` and/or
  `composer require xtenstack/plugin-template`, then `./run modules sync`
  and enable from the Configuration page (or `./run modules enable
  widgets`/`tags`) if you want to see the demo running; more commonly
  you'll copy the package directory as a starting point instead of
  enabling it at all — see "Building your own module" in
  `docs/user-guide.md`. Safe to leave uninstalled if you're not about to
  build a module.
- **Outgoing mail (signup verification, password reset, etc.) is your
  responsibility to get working.** By default the app calls PHP's
  native `mail()`, which relies on a local MTA (sendmail/postfix/etc.)
  already being configured on the host — this is not something the app
  can set up for you, and most fresh hosts/containers don't have one
  ready out of the box. If `mail()` has no MTA, sending fails silently
  (logged via `error_log()`, the triggering request still succeeds —
  e.g. signup completes even if the verification email never sends).
  Set the four `SMTP_*` variables in `.env` to relay through an
  authenticated SMTP account instead (see `.env.example`'s comments) if
  you don't want to rely on local MTA configuration — this is the
  simpler path on most hosting providers. Either way, test that a real
  email actually arrives before relying on signup/password-reset in
  production.
