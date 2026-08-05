---
name: rbac-decision
description: Decide $allowedRoles for a new backend/API endpoint in app_skeleton. Use whenever adding a new controller or action that needs an explicit role decision — CLAUDE.md forbids shipping "temporarily public" endpoints, so this is not optional.
---

# Deciding `$allowedRoles` for a new endpoint

CLAUDE.md's forbidden patterns list: *"A new endpoint with no explicit `$allowedRoles` decision. 'Temporarily public' is not a state this project ships."* This skill is the checklist for making that decision correctly and consistently, not guessing per-controller.

## Where it's set

`ControllerBase::$allowedRoles` (backend) — a protected property, either:

- `null` — any authenticated backend user, no further gate. Set at the class level (e.g. `protected ?array $allowedRoles = null;`) when the property is static per-controller.
- An array of role ids — resolved **at runtime**, not hardcoded, unless the role really is fixed forever:
  - Hardcoded `[1]` is correct only for admin-only screens where "1" is a stable, load-bearing assumption (Roles/Users/Settings/Configuration controllers all do this — admin's role id is effectively part of the schema).
  - Runtime-resolved (`\Roles::idsByNames(['admin', 'operator'])`, set in `onConstruct()` before calling `parent::onConstruct()`) is correct whenever the actual *set* of roles allowed might reasonably change, or when readability matters more than a micro-optimization — `TicketsController` is the reference example: `protected ?array $allowedRoles = null; // resolved at runtime, see RBAC section` as a placeholder, then `$this->allowedRoles = \Roles::idsByNames(['admin', 'operator']);` inside `onConstruct()`.

The frontend module's `ControllerBase` doesn't use `$allowedRoles` at all — it has no admin-only surface, just an authenticated/unauthenticated split (`UNAUTHENTICATED_CONTROLLERS`) plus, where it matters, explicit per-record ownership scoping in the query itself (see `frontend\TicketsController` — `WHERE reporter_user_id = :uid:` on every single lookup, not a role check at all, since "is this yours" isn't a role question).

## The actual decision

Ask, in order:

1. **Does a logged-out guest need this?** If yes, it doesn't belong behind `ControllerBase`'s auth gate at all — it's either on `frontend`'s guest-reachable surface (`UNAUTHENTICATED_CONTROLLERS`), or a themed error page (`notFoundAction`/`serverErrorAction`, the two `UNAUTHENTICATED_INDEX_ACTIONS` exceptions), or an API endpoint with its own API-key auth path.
2. **Is this an admin-only concern** (system configuration, user/role management, secrets, module toggles, audit trail)? → hardcoded `[1]`, admin only.
3. **Is this staff-facing but not admin-only** (ticket triage, anything an operator does day-to-day)? → `\Roles::idsByNames([...])` resolved at runtime, listing the actual roles.
4. **Is this "any logged-in backend user, no further restriction"** (My Profile, the dashboard itself)? → `null`.
5. **Is this actually about record ownership, not role at all** (a customer's own data)? → this isn't a `$allowedRoles` question — it belongs on `frontend`, scoped by `reporter_user_id`/`user_id` in every query, the same pattern `frontend\TicketsController` uses. Don't try to force an ownership check into the role system.

## Don't

- Don't leave `$allowedRoles` unset (defaults to `null` on `ControllerBase` — silently becomes "any authenticated user," which may not be the intended decision, just the accidental one).
- Don't hardcode role ids for anything except the fixed admin-only case — role ids are seed data, not guaranteed stable, and `\Roles::idsByNames()` exists precisely so this doesn't have to be guessed at.
- Don't gate an action's *visibility in the menu* (`menu.php`'s own `roles` field) as a substitute for the controller's own `$allowedRoles` check — the menu hides a link; it does not stop a direct request. Both need to agree, and the controller's check is the one that actually matters for security.
