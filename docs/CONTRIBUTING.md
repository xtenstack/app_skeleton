# Contributing

Thanks for considering a contribution. This project is early — expect
some rough edges in process as well as code.

## Before you start

For anything beyond a small fix, open an issue first to discuss the
approach. It's much cheaper to align on direction before writing code
than to rework a finished PR.

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

There's no PHPUnit suite yet — that's a known, tracked gap (backlog),
not something quietly skipped. Two things do exist today:

- `.github/workflows/build.yml` ("Build" badge on the README) builds the
  real Docker image, boots the full stack, and smoke-tests a few real
  endpoints on every push/PR to `main`. It proves the app builds and
  serves traffic, not that any particular feature's logic is correct.
- The manual-verification standard in
  [CODING-STANDARDS.md](CODING-STANDARDS.md#testing-changes-before-calling-them-done)
  — actually drive the change (browser or `curl`/`./run`) — is what
  "tested" means for a PR until a real test suite exists.

If you're adding PHPStan/PHP_CodeSniffer or a PHPUnit suite, open an
issue first (see Before you start) — this is exactly the kind of
cross-cutting addition worth aligning on before writing code.

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
