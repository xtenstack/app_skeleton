# Ticketing Feature — Implementation Plan

## Context

Multiple different AI agents (Microsoft Copilot running tests, Google Gemini watching
application logs, Claude Code doing dev work) need a way to escalate to a human when they
hit something they shouldn't handle alone — a test failure, a concerning log entry, a
blocker, a decision only a human should make. This is the "task hand-off mechanism" item
that's been open since the two-droplet deploy plan (`TASKS.md` vs. GitHub issues vs.
something else) — Ticketing is that something else, specifically for the agent→human
escalation direction (human→agent task assignment is a separate, still-open concern, not
addressed here).

Confirmed requirements from discussion: tickets are always assigned to a **human** operator
(never an agent), and "human operator" means multiple staff/contractors, not just the
account owner. Humans review and can consolidate/merge related tickets, discuss the actual
fix in a separate Claude Code/Desktop session (the ticket system is a tracking/escalation
layer, not a chat/design tool), a fix lands, a retest gets tasked, and the ticket auto-closes
on a passing retest — except auto-closes should be spot-checkable by a human (a sample, not
blind trust of 100%).

**Deliberate scope decision**: built as a direct, built-in backend feature (own controller,
model, migration, menu entry — same shape as `ExternalConnections`), **not** wrapped in the
`ModuleManager`/`module.json` system. `ModuleManager` itself still has real open design
questions (module-scoped routes vs. global route table, event-bus shape, nav-switching) that
the module-system-design-brief says should be resolved *from* a real module's concrete
requirements, not guessed at in the abstract first. Ticketing ships now as a self-contained
built-in feature; once ModuleManager's design is actually settled, Ticketing is the concrete
case that informs it, and extracting Ticketing into a real module at that point is a
mechanical refactor (move files, add a manifest), not a rewrite.

## Design decisions

**1. API-key authentication is a real gap, not a given.** `api_keys.token_hash` is written
by `ApiKeysController` but never read anywhere — `app/modules/api/controllers/ControllerBase.php`
currently only checks session-cookie login (`$this->auth->isLoggedIn()`), confirmed by direct
read. Ticketing needs real key-based auth for agents to call in, so this plan builds it as a
reusable base-engine service (`ApiKeyAuth`), not a Ticketing-only hack — any future API
endpoint benefits.

**2. Agents ARE Users rows — revised 2026-07-30 per Travis's correction.** Original draft of
this plan decoupled agents from `users` entirely (a separate `agent_identities` table). That
was wrong: Travis's actual intent was always for agents to carry a normal `user_id`, and
separately, ticket reporters aren't limited to agents at all — customers and staff can also
report tickets. Both of those point the same direction: reporters (agent, staff, or
customer) are all just `users` rows, distinguished by role, not a parallel identity system.

Revised approach: no new `agent_identities` table, no change to `api_keys.user_id` (stays
`NOT NULL`, unchanged). Agent accounts are ordinary `users` rows with the new `agent` role
(added alongside `operator` — see RBAC section), each issued a normal `api_keys` row the same
way a human's key is issued today. `tickets.reporter_user_id` (nullable FK to `users.id`)
replaces the earlier `reporter_agent_key`/`agent_identities` design and covers agent, staff,
and customer reporters uniformly through the same column.

**Resolved**: possibly either, per Travis — and both are already accommodated without further
design work. A member-role customer can hit the API directly with their own key today
(`createAction` sets `reporter_user_id` from whichever authenticated principal called it,
agent/staff/customer alike); a future customer-facing submission form would just be another
entry point into the same create path, session-authenticated instead of key-authenticated.
Building that form itself is not in this plan's scope — the schema and API just don't
preclude it later.

**3. RBAC gap — flagged, minimally patched, not redesigned.** Current roles are only
`admin`/`member`; neither fits "some non-admin humans triage tickets, others don't."
Rather than reusing `member` (too permissive) or hardcoding admin-only (contradicts "not
just me"), this adds one new seed role, `operator`. This is a pragmatic additive fix, not a
solution to the deeper known gap (`ControllerBase::$allowedRoles` has no per-action or
per-record grain — already called out in `docs/module-system-design-brief.md`). That
structural fix belongs to the module-system RBAC redesign, out of scope here.

## 1. Database migration — `db/migrations/postgresql/011_tickets.sql`

Following established conventions (`SERIAL PRIMARY KEY`, narrow types, `deleted_at`,
`created_at`/`updated_at` defaults, plain `FOREIGN KEY` with no `ON DELETE`, prose comment
per table; schema only, no seed data — seeding lives in `SeedTask.php` per the existing
pattern).

```sql
-- Tickets: escalations/reports raised by an agent, staff member, or
-- customer for a human operator to triage. reporter_user_id covers all
-- three uniformly (agents are ordinary `users` rows with the `agent`
-- role — see RBAC section, no separate identity system). Nullable
-- because it's still possible for a ticket to come in without a
-- resolvable user (e.g. a future anonymous/email-in intake path).
-- reporter_api_key_id additionally records which API key authenticated
-- an API-created ticket, for traceability, independent of who the
-- reporter is (e.g. staff filing on a customer's behalf via the backend
-- UI has no api_key_id at all). assigned_to_user_id is always a human
-- (agents are never a valid assignee — enforced at the controller level,
-- see section 6). consolidated_into_ticket_id is a self-reference for
-- merge/dedupe. retest_ref/last_retest_* are the hook a future
-- task-runner reads/writes (see section 5 — nothing here builds that
-- runner). auto_closed_at plus qa_reviewed_at/by/outcome support
-- spot-checking a sample of auto-closes.
CREATE TABLE tickets (
    id                          SERIAL PRIMARY KEY,
    title                       VARCHAR(200) NOT NULL,
    description                 TEXT,
    severity                    VARCHAR(20) NOT NULL DEFAULT 'normal',
    status                      VARCHAR(20) NOT NULL DEFAULT 'open',
    source_ref                  VARCHAR(255),
    reporter_user_id            INTEGER,
    reporter_api_key_id         INTEGER,
    assigned_to_user_id         INTEGER,
    consolidated_into_ticket_id INTEGER,
    retest_ref                  VARCHAR(255),
    retest_agent_key            VARCHAR(50),
    last_retest_result          VARCHAR(10),
    last_retest_at              TIMESTAMP,
    last_retest_notes           TEXT,
    closed_at                   TIMESTAMP,
    close_reason                VARCHAR(20),
    auto_closed_at              TIMESTAMP,
    qa_reviewed_at              TIMESTAMP,
    qa_reviewed_by              INTEGER,
    qa_outcome                  VARCHAR(20),
    deleted_at                  TIMESTAMP,
    created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reporter_user_id) REFERENCES users(id),
    FOREIGN KEY (reporter_api_key_id) REFERENCES api_keys(id),
    FOREIGN KEY (assigned_to_user_id) REFERENCES users(id),
    FOREIGN KEY (consolidated_into_ticket_id) REFERENCES tickets(id),
    FOREIGN KEY (qa_reviewed_by) REFERENCES users(id)
);
```

No `api_keys` schema change at all in this revision — `user_id` stays `NOT NULL`, unchanged
from today. Agent accounts are seeded as ordinary `users` rows with the `agent` role and
issued a normal `api_keys` row exactly like a human's.

Status lifecycle: `open`, `in_review`, `consolidated`, `closed`. No separate
history/comments table — the requirement rules out in-ticket rich collaboration, and
`Tickets` gets `keepSnapshots(true)` so status/assignment/consolidation changes are already
captured in `audit_log` for free.

Also add `'tickets'` to `Audit::REVERSIBLE_TABLES` in `app/common/library/Audit.php` —
one-line addition, follows the existing pattern, covers accidental wrong-consolidate/wrong-close.

## 2. Models

**`app/modules/backend/models/Tickets.php`** — plain properties matching the schema, `use
SoftDeletes;`, `initialize()` sets source + `keepSnapshots(true)` + `belongsTo` relations for
`Reporter` (Users), `Assignee` (Users), `QaReviewer` (Users), `ReporterApiKey` (ApiKeys),
`ConsolidatedInto` (self), `beforeSave()` bumps `updated_at` — matches the
`ExternalConnections` model pattern exactly.

No changes needed to `ApiKeys.php` or any new model — agents are plain `Users` rows, reusing
the existing model as-is.

## 3. API-key authentication service — `app/common/library/ApiKeyAuth.php`

New `Phalcon\Di\Injectable` service:
- `tokenFromRequest($request)`: reads `Authorization: Bearer <token>` or `X-Api-Key` header.
- `resolve($token)`: looks up `ApiKeys::findFirst` by `token_hash = sha256(token) AND
  revoked_at IS NULL`, updates `last_used_at`, returns a normalized principal array —
  `{type: 'user', api_key_id, user_id, role_id, label}`. (Simplified from an earlier draft
  that had a separate `agent` principal type — agents are just `users` rows now, so every
  API-key-authenticated caller resolves the same way, whether it's an agent, staff, or a
  customer's own key.)

Register as `$di->setShared('apiKeyAuth', ...)` in `app/config/services.php`, same shape as
the existing `audit`/`cronRunner` registrations.

## 4. `app/modules/api/controllers/ControllerBase.php` changes

Current code (confirmed via direct read) only checks `$this->auth->isLoggedIn()` — extend
`onConstruct()` to fall back to API-key auth when there's no session, storing a normalized
`protected ?array $principal`:

```php
protected ?array $principal = null;

protected function onConstruct()
{
    $this->response->setContentType('application/json', 'UTF-8');
    if ($this->dispatcher->getControllerName() === 'session') return;

    if ($this->auth->isLoggedIn()) {
        $auth = $this->session->get('auth');
        $this->principal = ['type' => 'user', 'user_id' => $auth['id'], 'role_id' => $auth['role_id']];
    } else {
        $token = $this->apiKeyAuth->tokenFromRequest($this->request);
        $this->principal = $token ? $this->apiKeyAuth->resolve($token) : null;
    }

    if (!$this->principal) {
        $this->response->setStatusCode(401, 'Unauthorized')
            ->setJsonContent(['error' => 'Not authenticated'])->send();
        exit;
    }

    // Every principal — session or API-key authenticated — is a real user
    // with a role_id now (agents included), so this check applies uniformly.
    if ($this->allowedRoles !== null
        && !in_array($this->principal['role_id'], $this->allowedRoles, true)) {
        $this->response->setStatusCode(403, 'Forbidden')
            ->setJsonContent(['error' => 'Forbidden'])->send();
        exit;
    }
}
```

Also hoist JSON-body parsing (currently inline in `SessionController`) into a protected
`ControllerBase` helper — `TicketsController` needs the same parsing, small dedup not new
behavior.

## 5. API endpoints — `app/modules/api/controllers/TicketsController.php`

Scope: **create + list + view + retest-result reporting only.** Assignment, consolidation,
manual close, and QA review stay human-only via the backend UI — ticket *triage authority*
stays with humans even though agents can create and eventually resolve-via-retest.

- `indexAction()` — GET, `?status=`/`?reporter_user_id=` filters. Lets a caller check for an
  existing open ticket on the same failure before creating a duplicate.
- `createAction()` — POST, JSON body `{title, description?, severity?, source_ref?,
  retest_agent_key?}`. Sets `reporter_user_id`/`reporter_api_key_id` from `$this->principal`,
  never from client-supplied fields (so a caller can't spoof someone else's identity).
- `viewAction($id)` — GET.
- `retestResultAction($id)` — POST, JSON body `{result: pass|fail, notes?}`. On `pass`: sets
  `status = 'closed'`, `closed_at`/`auto_closed_at = now`, `close_reason = 'auto_retest'`. On
  `fail`: sets `status = 'in_review'`, updates `last_retest_*`, stays open.

**This is the entire ticket-side runner hook.** It does not build or assume a specific
task-runner — that's the separate, still-open "task hand-off mechanism" item. This endpoint
is deliberately the whole surface a future runner needs without guessing its shape further.

No routing changes needed — the existing generic per-module route
(`app/config/routes.php`, `/api/:controller/:action/:params`) already covers
`POST /api/tickets/create`, `GET /api/tickets`, `GET /api/tickets/view/{id}`,
`POST /api/tickets/retest-result/{id}`.

## 6. Backend (human-facing) UI — `app/modules/backend/controllers/TicketsController.php`

```php
protected ?array $allowedRoles = null; // resolved at runtime, see RBAC section — not hardcoded ids

indexAction()       // list, ?status=/?assigned_to= filters, QA spot-check banner
viewAction($id)     // detail: reporter, retest fields, consolidation target, assignee dropdown (humans only)
assignAction($id)      // POST: assigned_to_user_id
consolidateAction($id) // POST: consolidated_into_ticket_id, status=consolidated
closeAction($id)       // POST: status=closed, closed_at=now, close_reason=manual
reopenAction($id)      // POST: clear closed_at/auto_closed_at, status=open
qaReviewAction($id)    // POST: {outcome: confirmed|reopened}; qa_reviewed_at/by/outcome; reopened also reopens
```

`assignAction`'s dropdown populates from `Users::find()` **filtered to exclude the `agent`
role** (`role_id != <agent role id>`) — since agents are ordinary `users` rows in this
revision, this exclusion has to be explicit here, unlike the earlier draft where it fell out
for free. Worth getting right: an agent account showing up as an assignable ticket owner
would be a real bug, not cosmetic.

Views under `app/modules/backend/views/tickets/`: `index.phtml`, `view.phtml`, following the
Bootstrap card/table shape of `external-connections/index.phtml`. `index.phtml` shows a
banner computed in `indexAction()`: "N% of auto-closed tickets this week haven't been
spot-checked yet" plus a `?filter=needs_qa` link (`auto_closed_at IS NOT NULL AND
qa_reviewed_at IS NULL`). No automated random-sampling algorithm — a human-driven filtered
queue satisfies "spot-check a sample" without inventing a sampler; automatic sampling is a
reasonable v2, not required now.

## 7. Menu entry — `app/modules/backend/config/menu.php`

```php
['label' => 'Tickets', 'icon' => 'fas fa-ticket-alt', 'controller' => 'tickets', 'url' => 'backend/tickets', 'roles' => /* operator + admin role ids, resolved at runtime */],
```

## 8. RBAC — `operator` and `agent` roles

`SeedTask::seedRoles()` (confirmed current code: `foreach (['admin', 'member'] as $name)`) —
extend to `['admin', 'member', 'operator', 'agent']`. `operator` is who can triage tickets;
`agent` is the role given to Microsoft Copilot/Gemini/Claude Code's own accounts (never
logged into interactively, never a valid ticket assignee — see section 6's dropdown filter).

**Important implementation detail**: don't hardcode either role's id (e.g. `3`/`4`) in
`TicketsController::$allowedRoles` or the assignee-dropdown filter the way existing code
hardcodes `[1]` for admin — role 1 is guaranteed by `001_initial_schema.sql`'s literal insert
order, but `operator`/`agent` have no such guarantee from `SeedTask` alone. Resolve ids by
name (`Roles::findFirst(['conditions' => "name = 'operator'"])`) at controller construction
or cache them, rather than assuming specific ids.

**Explicitly flagged, not fixed by this plan**: this only adds a third bucket, not real
grain — no per-action permissions, no assignee-scoped visibility, no separation of
"can triage" from "can close" from "can QA-review." That's the module-system RBAC redesign
item, out of scope here.

## 9. Self-containment / future extraction

Ticketing-owned (would mechanically lift into a real module later): `011_tickets.sql`,
`Tickets.php`, both `TicketsController.php`s, `tickets/` views, the `SeedTask`
operator/agent-role additions, the menu entry, the `Audit::REVERSIBLE_TABLES` entry.

Deliberately **base-engine**, not Ticketing-owned, even though Ticketing is what's driving it
into existence: `ApiKeyAuth`. API-key authentication was a real pre-existing gap — keeping it
in the base engine means future modules benefit too, rather than needing to duplicate or
re-extract it later. (This revision no longer touches `api_keys`' schema at all, so there's
less base-engine surface area than the original draft — only the new service, not a table
alteration.)

## 10. What this plan explicitly does not do

- Does not build the task-runner or retest-dispatch mechanism — only the ticket-side hook.
- Does not touch `ModuleManager`, `module.json`, or nav-switching.
- Does not build automated QA-sampling — a human-facing filtered queue instead.
- Does not redesign RBAC beyond the additive `operator` role.
- **Does not build inbound-email-to-ticket processing** — captured 2026-07-30 as a real,
  wanted future use case (someone emails `support@`/`stack@xten.au`, it becomes a ticket,
  auto-matched to `reporter_user_id` if the sender's address is a known user, otherwise a new
  user gets created alongside the ticket). The schema already accommodates the "no matched
  user" half (`reporter_user_id` is nullable). The auto-create-a-user-from-an-unrecognized-
  sender half is genuinely new scope, not covered here — needs its own design pass: how
  inbound mail actually gets parsed (IMAP polling against `mail.xten.au`? a webhook if the
  mail provider supports inbound parsing?), and real spam/abuse handling before letting
  arbitrary incoming email auto-create accounts. Don't build this opportunistically inside
  an unrelated change — it deserves the same scoping this plan itself got.

## Verification

1. `db/migrate run` applies `011_tickets.sql` cleanly against the local dev Postgres, `db/migrate status` shows it applied.
2. Seed a test `users` row with the `agent` role, issue it an `api_keys` row via the existing `ApiKeysController` flow, then `curl -X POST /api/tickets/create -H "X-Api-Key: <token>" -d '{"title":"test"}'` returns 201 with a ticket id, and a second call with a bad/revoked key returns 401.
3. Log into the backend as a seeded `operator`-role user, confirm the Tickets menu entry appears and `/backend/tickets` lists the ticket created above; confirm a `member`-role user gets 403, and confirm the `agent`-role account cannot log into the backend session-wise at all (or if it can, does not appear in the assignee dropdown).
4. `POST /api/tickets/retest-result/{id}` with `{"result":"pass"}` closes the ticket and sets `auto_closed_at`; confirm it shows in the `needs_qa` filter until `qaReviewAction` is called.
5. Confirm existing `api_keys`-dependent code (`ApiKeysController`, the account page's key list) is entirely unaffected — this revision makes no schema change to `api_keys` at all.
