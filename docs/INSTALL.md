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

Postgres is the only fully-supported adapter today — this is the
honest state of things, not a "should just work" claim. Two separate
pieces need to exist before either of the other two adapters is
usable, and only the first one does yet:

1. **Connection wiring** — `app/config/services.php`'s `db` service
   builds its connection params generically off
   `config.database.adapter` (`Postgresql`, `Mysql`, or `Sqlite`), so
   pointing `config.local.php` at a different adapter is enough on this
   front alone.
2. **Schema migrations** — `db/migrations/<adapter>/*.sql`, applied by
   `./run migrate run` (or automatically via `bin/install.php` on
   `composer install`/first Docker boot) for whichever adapter is
   configured. **Only `db/migrations/postgresql/` exists right now.**
   Point the config at `Mysql` or `Sqlite` today and the migration
   runner finds zero files for that adapter — nothing gets created, and
   the app has no tables to work with. There is currently no MySQL or
   SQLite migration set to translate the Postgres SQL (`SERIAL`,
   `TIMESTAMP DEFAULT CURRENT_TIMESTAMP`, etc.) into the target
   dialect's syntax and place under a matching
   `db/migrations/mysql/` or `db/migrations/sqlite/` directory,
   filename-ordered the same way `postgresql/`'s files are, before
   `./run migrate run` has anything to apply.

If you're testing on shared hosting with only MySQL/SQLite available
(no Postgres, likely no Docker either — use the Composer path above):

1. Edit `app/config/config.local.php`'s `database` block —
   `adapter` = `Mysql` or `Sqlite`, plus whatever `host`/`port`/
   `username`/`password`/`dbname` your host gives you (`Sqlite` only
   needs `dbname` — the database file's path).
2. Hand-translate `db/migrations/postgresql/*.sql` into the target
   dialect under a new `db/migrations/<adapter>/` directory (same
   filenames, same order) before running `composer install` or
   `./run migrate run` — see point 2 above.
3. Everything else (`./run modules sync`, seeding, the app itself) is
   already adapter-agnostic — it's specifically the schema files that
   are Postgres-only right now.

**Planned, not built**: consolidating the (by-then-frozen) migration
set into one rolled-up schema file per adapter, applied automatically
based on an adapter choice at install time — see `docs/user-guide.md`
and the project's requirements log. Fine to stay Postgres-only until
then; this section exists for the shared-hosting case that can't wait.

## After install

- Default seeded roles: `admin`, `member`, `operator`, `agent` — no
  default user is seeded, sign up through `/backend/signup` and promote
  that account to `admin` directly in the database
  (`UPDATE users SET role_id = (SELECT id FROM roles WHERE name = 'admin') WHERE email = '...'`).
- `./run` with no arguments lists every available CLI task.
- `./run modules sync` after installing any additional module package.
