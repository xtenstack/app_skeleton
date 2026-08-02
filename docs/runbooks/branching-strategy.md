# Runbook: Branching Strategy

**Status: stub — the strategy below is the Session 10 recommendation,
not yet a confirmed decision (REQ-022).** Fill in real dates/version
numbers once a first release is actually being prepared, and remove this
status line once it's confirmed.

## Model: trunk-based development

- **`main`** is the only ongoing branch. All feature work branches off
  `main` and merges back via PR. This is the branch CI/deploy pipelines
  track by default (see `azure-test`, mirrored automatically — not a
  branching-strategy branch itself, just a deploy target).
- **`release`** is cut only when actually preparing to ship a tagged
  version to a client instance. It is:
  - Short-lived — not a permanent parallel branch continuously merged
    back and forth with `main`.
  - Frozen except for bugfixes once cut — no new feature work lands on
    it.
  - Tagged (`v1.0.0`, etc.) when it actually ships.
  - Deleted (or left to go stale, team's call) once superseded by the
    next release branch.

Rejected: full git-flow (`develop`, per-feature long-lived branches,
`release/*`, `hotfix/*` as permanent categories) — more process than
this project's size currently justifies. Revisit if/when multiple
concurrent client versions need to be supported at once.

## Contributor branches

External contributors fork and PR against `main` directly — no separate
"contributors" branch. See [CONTRIBUTING.md](../../CONTRIBUTING.md) for
why a dedicated `openstack`/`openstack-development` branch was
considered and set aside for now (branch protection on `main` gives the
same safety without an extra branch to keep in sync).

## When a release is actually being prepared

1. Cut `release` from `main` at the commit you're shipping.
2. Only bugfix commits land on `release` from here — cherry-pick from
   `main` or commit directly and back-port to `main`, don't diverge.
3. Tag the release commit (`git tag v1.0.0`) once it's confirmed good.
4. Next release cuts a fresh `release` branch from `main` at that point
   — don't reuse or keep merging the old one forward.
