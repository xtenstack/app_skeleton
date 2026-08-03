# RB-01 Branch, name, and commit a change

> **Category:** Process & workflow · **Status:** DRAFT · **Last executed:** n/a — this documents a standing convention, not a one-time procedure

## Purpose

The confirmed answer to REQ-022: this repo's branching model, branch
naming, and commit-message convention, so there's one place to check
instead of re-deciding it every session.

## Trigger

Starting any change — feature, fix, or docs — or cutting an actual
release.

## Prerequisites

None.

## The convention

### Branching model: trunk-based

- `main` is the only ongoing branch, always releasable.
- Feature/fix work happens on short-lived branches off `main`, merged
  back via PR.
- `release` is cut only when actually preparing to ship a tagged version
  to a client instance — frozen except for bugfixes, tagged (`vX.Y.Z`),
  then superseded by the next release branch rather than reused or kept
  merging forward.
- Rejected: full git-flow (`develop`, long-lived `release/*`/`hotfix/*`
  categories) — more process than this project's size currently
  justifies. Revisit if/when multiple concurrent client versions need to
  be supported at once.

### Branch naming

Per the XTen Coding Standard (deploy.xten.au §5, "Git workflow"):
`feature/<REQ-id>-short-name` or `fix/<issue>` — e.g.
`feature/REQ-020-frontend-module`, `fix/cli-main-task-banner`. `<REQ-id>`
cross-references `Requirements-List.md`'s `REQ-NNN` entries directly,
same ID space used everywhere else this project logs a requirement.

### Commit messages: Conventional Commits

`feat:`, `fix:`, `docs:`, `refactor:`, `chore:`, `test:` prefixes — same
convention as the master XTen Coding Standard, so changelog-generation
tooling works identically across every XTen repo rather than needing a
per-repo variant.

### Contributor branches

External contributors fork and PR against `main` — no dedicated
long-lived contributor branch (an `openstack`/`openstack-development`
branch was considered and set aside). `main`'s branch protection
(required review, no direct pushes) gives the same safety without an
extra branch to keep in sync. See [CONTRIBUTING.md](../../CONTRIBUTING.md).

### When a release is actually being prepared

1. Cut `release` from `main` at the commit being shipped.
2. Only bugfix commits land on `release` from here — cherry-pick from
   `main` or commit directly and back-port to `main`, don't diverge.
3. Tag the release commit (`git tag vX.Y.Z`) once it's confirmed good.
4. Next release cuts a fresh `release` branch from `main` at that point
   — don't reuse or keep merging the old one forward.

## Verification

A PR's branch name and commit messages follow the convention above. If
this becomes worth enforcing mechanically rather than by review, a
commit-lint CI step is the natural next addition to the build workflow
(`.github/workflows/`) — not built yet.

## Changelog

| Date | Author | Change |
|---|---|---|
| 2026-08-01 | Travis Saron / Claude | Stub written as the Session 10/11 recommendation (REQ-022) |
| 2026-08-03 | Travis Saron / Claude | Finalized: branch-naming and commit-message convention pulled in from the XTen Coding Standard; renumbered into the RB-NN scheme; REQ-022 marked Done |
