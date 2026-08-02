# Contributing

Thanks for considering a contribution. This project is early — expect
some rough edges in process as well as code.

## Before you start

For anything beyond a small fix, open an issue first to discuss the
approach. It's much cheaper to align on direction before writing code
than to rework a finished PR.

## Workflow

Standard GitHub fork-and-PR: fork the repo, branch off `main`, open a
pull request back against `main`. There is currently no separate
long-lived "contributors" branch (an `openstack`/`openstack-development`
branch was considered for Session 11, but decided against for now — see
below) — `main` plus branch protection (required review, no direct
pushes) gives the same safety without adding a branch contributors have
to track and rebase against separately.

**Branching strategy is still being finalized** (tracked as REQ-022):
the working plan is trunk-based development — `main` as the only
ongoing branch, with a short-lived `release` branch cut only when
actually preparing to ship a tagged version, frozen except for
bugfixes. This will be documented properly once it's settled; treat
`main` as the correct base for now.

1. Fork and branch from `main`.
2. Make your change, following [CODING-STANDARDS.md](CODING-STANDARDS.md).
3. If you touched anything the browser can exercise, actually run it
   locally and check it (see INSTALL.md) — don't rely on reading the
   diff.
4. Open a pull request against `main` using the PR template.

## Commit messages

Explain *why*, not just *what* — the diff already shows what changed.
Keep the subject line short; use the body for context a future reader
(including future you) will need.

## Reporting bugs / requesting features

Use the issue templates under `.github/ISSUE_TEMPLATE/`. For security
vulnerabilities, do **not** open a public issue — see
[SECURITY.md](SECURITY.md).

## Code of conduct

Participation in this project is governed by the
[Code of Conduct](CODE_OF_CONDUCT.md).
