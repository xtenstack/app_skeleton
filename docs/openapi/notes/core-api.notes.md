# core-api notes

Scope: app_skeleton `api` module. 29 paths, 31 operations (2 are OPTIONS preflights), 2 x-unverified.

## Could not fully determine (x-unverified)
- `GET /api/tickets/view/{id}`, `GET /api/kb-articles/view/{id}`: `{id}` is passed uncast to `findFirstById`; behaviour for a non-numeric id (probably a Postgres type error, 500) not checkable from code.
- Ticket `status` values and `ticket_type` stored values: no model enum; only open / in_review / consolidated / closed are visible from code and views. Model validation rules (title length etc.) were not found in the models, so no length limits are documented for ticket title/description or KB title/body.
- Watson `dolibarr` result shape taken from the DolibarrClient::createClose docblock, not exercised.

## Possible bugs / inconsistent behaviour
- Tickets list/view/ and KB-less ticket endpoints (`index`, `view`, `retest-result`, `self-assign`, `create`) have no role or ownership check: any authenticated principal, including a `member` or any agent key, can list and read ALL tickets and call retest-result (which can close any ticket as `auto_retest`) and self-assign. Only `messages` is scoped.
- `retest-result` lets any caller close any ticket (pass), by design for retest agents, but unrestricted by role.
- `rotate-keys` is registered with `gate('POST', false)` (not admin-gated); docs/PROVISIONING-API.md says admin. Authorisation is only `mayManageKeysOf`, so a non-admin in the rotate-any email list would pass. Its 403 message claims admins may rotate "their own" keys, but an admin who is not in the rotate-any list is refused for themselves (target holds admin).
- `PROVISIONING-API.md` table says `api-key` follows the same rule; code message "Only Code and Travis can manage an administrator's keys" is consistent.
- `provision/users`: user save then `UserRoles::syncForUser` are not in a transaction; a sync failure returns 500 after the user row is saved/updated.
- Generic route side effects: because `/api/:controller/:action/:params` still exists, explicit routes are not exclusive. Actions are reachable by any HTTP method unless the action checks (many list/view/match/me/logout actions have no method check), e.g. `/api/provision/issue-key/42`, `/api/provision/rotate-keys/42`, `/api/provision/deactivate/42` (camelised action names), `/api/public-kb/faq` with POST, and non-numeric ids on provision routes (cast to 0, 404).
- `PublicKbController` and `PublicIntakeController` handle OPTIONS themselves via explicit `via([...,'OPTIONS'])`; the 204 preflight does not check Origin (header only set when origin is allowed).
- `session/login` accepts form-encoded or JSON; failed-login rate limit (5 per email OR IP per 15 min) returns the same 401 as a wrong password, so callers cannot distinguish lockout. `session/logout` and `session/me` have no method check, and logout is CSRF-able (GET).
- Unknown `/api` URLs return 404 `{"error":"Not Found"}` while per-record misses return `{"error":"Not found"}` (different capitalisation). Unauthenticated callers get 401 for unknown URLs too (cannot discover 404s).
- `watson/close-offer`: `price_aud` is only compared to the first offer code; response `price_aud` is the sum of created orders but `offer_name` is the first only. If a later code's Dolibarr order fails it is silently dropped (logged only), so the caller must compare `offer_codes` with `orders[]`. Not idempotent. No role gate (any authenticated caller).
- Duplicate offer entries: OFFERS contains `DRA` and `DEP-RESTORE` as the same product.
- `kb-articles` create/update silently coerce invalid `visibility` rather than 422.
- `tickets/create` silently coerces unknown `severity` to normal and unknown `ticket_type` to bug.
- `serialize` for tickets never returns staff-only `notes`, but `close` overwrites them (not append).
- A duplicate `external_ref` on reply/note returns HTTP 200 with `duplicate: true`; new messages return 201.

## Exists in code but not (directly) routable / not documented as paths
- `IndexController::notFoundAction` and `serverErrorAction`: internal forward targets; technically reachable via the generic route as `/api/index/not-found` and `/api/index/server-error` (authenticated), not documented as paths. Their bodies are described under `GET /api`.
- `ControllerBase::currentUserPayload()` is session-only; irrelevant to key callers.
- Module `controllers/` (lowercase, autoload path) and `Controllers/` (capitalised) both exist in the snapshot with identical file names; on a case-sensitive FS Phalcon autoloads from lowercase `controllers/`. The two directories were diffed and are identical.
- Session-cookie auth is not a declared securityScheme in the merged doc; it is described in operation text only.
