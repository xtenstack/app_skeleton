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

## After install

- Default seeded roles: `admin`, `member`, `operator`, `agent` — no
  default user is seeded, sign up through `/backend/signup` and promote
  that account to `admin` directly in the database
  (`UPDATE users SET role_id = (SELECT id FROM roles WHERE name = 'admin') WHERE email = '...'`).
- `./run` with no arguments lists every available CLI task.
- `./run modules sync` after installing any additional module package.
