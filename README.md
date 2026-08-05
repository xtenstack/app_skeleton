# App Skeleton

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%5E8.3-777bb4.svg)](composer.json)
[![Phalcon](https://img.shields.io/badge/Phalcon-%5E5.8-2196f3.svg)](composer.json)
[![Build](https://github.com/xtenstack/app_skeleton/actions/workflows/build.yml/badge.svg)](https://github.com/xtenstack/app_skeleton/actions/workflows/build.yml)

A Phalcon 5.8 multi-module PHP application skeleton: a server-rendered
AdminLTE backend plus a JSON API module, sharing one codebase. Comes with
authentication, RBAC, audit logging (with reversal), soft deletes, a cron
system, signup/verification/password-reset flows, and a Composer-package
based module system for adding features without forking the base app.

The Build badge covers a real build + boot + smoke-test of the actual
Docker image every push/PR — it is **not** a test-suite badge; there's
still no PHPUnit suite, see [CONTRIBUTING.md](CONTRIBUTING.md) for that
gap.

## What's included

- **Backend module** — server-rendered AdminLTE admin/user interface.
- **Frontend module** — the public-facing guest landing page and a
  non-admin member dashboard, separate from the admin backend.
- **API module** — JSON-only, session or API-key authenticated.
- **CLI module** — `./run <task> <action>` for migrations, seeding,
  module management, route listing, cron, and more (`./run` with no
  arguments lists everything).
- **Auth & RBAC** — session-based login, role-gated controllers, an
  API-key path for machine callers.
- **Audit logging** — opt-in per model (`keepSnapshots(true)`), captures
  before/after values with reversal support.
- **Soft deletes** — a shared trait, not a bespoke column per table.
- **Module system** — Composer-installed packages with a `module.json`
  manifest are discovered and toggleable from the admin Configuration
  page, without touching core code.

See [CODING-STANDARDS.md](CODING-STANDARDS.md) for the conventions the
existing code follows.

## Quickstart

```bash
git clone <your fork or this repo>
cd app_skeleton
cp .env.example .env   # fill in DB_PASSWORD at minimum
mkdir -p data && touch data/encryption_key
docker compose up -d --build
```

Full install options (Docker, Composer-only, plain download) are in
[INSTALL.md](INSTALL.md).

## Known limitations

- **File uploads picked directly from Photos in Safari on macOS can
  fail.** Selecting a file via a `<input type="file">` picker's "Photos"
  source (rather than choosing an already-saved file) requires Safari to
  export the asset from the Photos library first — which can involve
  downloading the full-resolution original from iCloud if "Optimize Mac
  Storage" is enabled — before it can build the upload request. That
  export isn't always complete by the time the form submits, which can
  result in a malformed multipart request reaching the server and a
  misleading "session expired" error, independent of file size or type
  (confirmed with both JPEG and PNG). This isn't fixable server-side.
  **Workaround**: export/save the photo to disk first (Finder or the
  Photos "Export" menu), then upload the saved file.

## Documentation

- [INSTALL.md](INSTALL.md) — setup instructions.
- [CODING-STANDARDS.md](CODING-STANDARDS.md) — conventions used
  throughout the codebase.
- [CLAUDE.md](CLAUDE.md) — module boundaries and forbidden patterns, for
  Claude Code (or any AI-assisted) sessions working in this repo.
- [docs/user-guide.md](docs/user-guide.md) — modules (what ships
  built-in vs. optional, how to build your own) and cron (how scheduled
  jobs work, how to add one).
- [CONTRIBUTING.md](CONTRIBUTING.md) — how to propose a change.
- [SECURITY.md](SECURITY.md) — how to report a vulnerability.
- [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) — community expectations.

## License

MIT — see [LICENSE](LICENSE).
