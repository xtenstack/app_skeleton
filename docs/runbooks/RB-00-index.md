# RB-00 Index

One line per runbook — find by **trigger**, not by memory. Update this
file in the same PR as any runbook added, renamed, or retired. Numbering
and layout mirror deploy.xten.au's runbook catalogue (RB-NN + status
column), scoped to this repo's own dev-process runbooks rather than
client operations — see [deploy.xten.au's
Runbooks Guide](../../../../deploy.xten.au/runbooks/README.md) for the
company-wide original this pattern is drawn from (not part of this repo,
referenced for convention only).

## Process & workflow

| ID | Runbook | Trigger | Status |
|---|---|---|---|
| [RB-01](RB-01-branching-and-release-strategy.md) | Branch, name, and commit a change | Starting any change; cutting a release | Draft |
| [RB-03](RB-03-list-view-conventions.md) | List-view convention: row actions, New, and bulk operations | Building or reviewing any backend list view | Draft |

## Environment

| ID | Runbook | Trigger | Status |
|---|---|---|---|
| [RB-02](RB-02-keep-local-dev-droplet-github-in-sync.md) | Keep local / dev droplet / GitHub in sync | Before launching an AutoClaudeDev run on the dev droplet | Draft |

See [module-system-design-brief.md](../module-system-design-brief.md)
and [ticketing-module-plan.md](../ticketing-module-plan.md) for
feature-plan documents — those aren't runbooks (one-off build plans, not
repeatable procedures) and live outside this numbering.
