# Contributing

Thanks for considering a contribution. This project is early — expect
some rough edges in process as well as code.

## Before you start

For anything beyond a small fix, open an issue first to discuss the
approach. It's much cheaper to align on direction before writing code
than to rework a finished PR.

For questions, ideas, and discussions (as distinct from bug reports),
use GitHub Discussions (github.com/xtenstack/app_skeleton/discussions) —
that's the space for broader conversation, separate from Issues (bugs)
and PRs (code changes).

## Workflow

Standard GitHub fork-and-PR: fork the repo, branch off `main`, open a
pull request back against `main`. There is no separate long-lived
"contributors" branch (an `openstack`/`openstack-development` branch was
considered and set aside) — `main` plus branch protection (required
review, no direct pushes) gives the same safety without adding a branch
contributors have to track and rebase against separately.

**Branching strategy** (REQ-022, finalized): trunk-based development.
`main` is the only ongoing branch; a short-lived `release` branch is cut
only when actually preparing to ship a tagged version, frozen except
for bugfixes. Name your branch `feature/<REQ-id>-short-name` or
`fix/<issue>` — matches the XTen Coding Standard's convention.

1. Fork and branch from `main` as `feature/<REQ-id>-short-name` or
   `fix/<issue>`.
2. Make your change, following [CODING-STANDARDS.md](CODING-STANDARDS.md).
3. If you touched anything the browser can exercise, actually run it
   locally and check it (see [INSTALL.md](INSTALL.md)) — don't rely on
   reading the diff.
4. Open a pull request against `main` using the PR template.

## Testing

`vendor/bin/phpunit` (`tests/Unit/`, `tests/Feature/`) covers the three
patterns this codebase leans on most: CSRF rejection, RBAC denial, and
soft-delete exclusion — see `tests/*/README` intent in each test file's
own docblock for what each one actually protects and why. Both suites
test the real thing, not mocks:

- `tests/Unit/` connects to a real Postgres database (via the ORM/models,
  same as the app itself) — no mocked query results.
- `tests/Feature/` makes real HTTP requests (via `tests/Feature/HttpClient.php`)
  against the actually-running Docker stack — no in-process dispatch,
  deliberately (`HttpClient`'s own docblock explains why: several
  controllers call `exit;` after an early-return response, which would
  kill the PHPUnit process itself if dispatched in-process instead of
  over real HTTP).

Run them the same way `.github/workflows/build.yml` does in CI — against
a running stack, not standalone:

```bash
docker compose up -d --build
# one-time per container: dev deps aren't in the runtime image (see
# build.yml's "Install PHPUnit..." step for the exact commands)
docker compose exec app vendor/bin/phpunit
```

On macOS, a locally-installed native Postgres can also bind to :5432
alongside Docker's container, causing E2E test fixture cleanup
(e.g. Playwright's global-teardown.ts `psql -h localhost` calls) to
silently hit the wrong database instead. This doesn't affect CI.
If local E2E test cleanup seems to not be working, check for a port
conflict first.

The production Docker image is built with `composer install --no-dev`,
so a fresh `docker compose up -d --build` has no PHPUnit available until
you redo the full install inside the running container. Check
`.github/workflows/build.yml` for the exact commands CI uses, and run
that same pattern: `docker compose exec app composer install` (full,
not `--no-dev`).

**`docker compose restart app` does not pick up source changes.** The
`app` service doesn't bind-mount the application code — only `public/`,
`.encryption_key`, and a few data/log directories are mounted; everything
else is baked in via the Dockerfile's `COPY . .` at build time. A restart
re-runs the same already-built image. If you've edited PHP and want the
container to actually run it, you need `docker compose build app &&
docker compose up -d app` (or `docker compose up -d --build`) — a plain
restart proves nothing about whether your change works. Don't treat "still
fails after a restart" as evidence against staleness; it isn't one.

Coverage beyond those three patterns is thin — this is a baseline, not
comprehensive coverage, and growing it is a legitimate contribution.
`.github/workflows/build.yml` ("Build" badge on the README) runs this
same suite on every push/PR to `main`, alongside its existing build +
boot + smoke-test check.

The manual-verification standard in
[CODING-STANDARDS.md](CODING-STANDARDS.md#testing-changes-before-calling-them-done)
— actually drive the change (browser or `curl`/`./run`) — still applies
for anything a test doesn't cover; passing PHPUnit isn't a substitute
for that on a PR touching untested territory.

If you're adding PHPStan or PHP_CodeSniffer, open an issue first (see
Before you start) — this is exactly the kind of cross-cutting addition
worth aligning on before writing code.

## Commit messages

[Conventional Commits](https://www.conventionalcommits.org/) —
`feat:`, `fix:`, `docs:`, `refactor:`, `chore:`, `test:` prefixes.
Explain *why*, not just *what* in the body — the diff already shows what
changed. Keep the subject line short; use the body for context a future
reader (including future you) will need.

## Reporting bugs / requesting features

Use the issue templates under `.github/ISSUE_TEMPLATE/`. For security
vulnerabilities, do **not** open a public issue — see
[SECURITY.md](SECURITY.md).

## Code of conduct

Participation in this project is governed by the
[Code of Conduct](CODE_OF_CONDUCT.md).
