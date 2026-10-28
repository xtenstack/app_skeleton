# modules-api.yaml notes

Source: read-only snapshots under ~/Developer/xten-work/api-docs-src. Validated OK (40 paths, 45 operations, 4 marked x-unverified).

## Not fully determined (x-unverified or free-form)
- GET /api/catalogue/products (x-unverified): `limit` has an upper cap (100) but no lower bound in code; limit=0 or negative behaviour depends on Phalcon/Postgres and was not verified. `ILIKE` makes `q` PostgreSQL-only.
- GET /api/ai-ssa-phone/calls/{instanceId} (x-unverified): call record shape is the sidecar's; documented as free-form objects.
- GET /api/ai-ssa/audit (x-unverified): `audit_records` / `interactions` bodies are the sidecar's; documented as free-form. Each becomes `[]` (not an object) if only that sidecar call fails.
- POST /store/quote/submit (x-unverified): behaviour depends on whether Transactions is installed and on runtime exceptions; see bugs below.
- `licence_status` vocabulary on AI SSA instances comes from LicenceGate::instanceSummary (not read); typed as string.
- Provisioner::save / Desk::save error messages beyond the ones quoted were not enumerated; they surface as 422 with `errors`.
- Roles: role names seeded per instance; `charter_agent` may not exist on every instance (Roles::idsByNames skips unknown names).
- AuthN note: every ApiControllerBase accepts a browser session cookie as well as an API key; documented under the API-key security schemes only.

## Looks like bugs or inconsistencies
1. Store /store/quote/submit maps QuoteRequestFailed to 503 only when `error === 'no_default_issuer'`, a key Transactions never emits (it uses `not_configured` 503 and `failed` 500). Result: both come back 422 from the Store route.
2. Store /store/quote/submit: any non-QuoteRequestFailed exception in the bridged intake (e.g. DB error) falls through to a fallback that returns 201 with a made-up `BDX-QR-xxxxxxxx` reference and saves nothing. The customer is told the quote was lodged when it was not.
3. Store /store/quote/submit does not enforce the Transactions Origin allow-list or the `allow_no_origin` setting on POST (only uses the list for CORS headers), unlike /transactions/public-quote/create which returns 403. It also drops `po_number` and `role_title`.
4. KPI /api/kpi/entry has no role check: any authenticated key (including `member`) can write any metric's actual; `scope_role` on metrics is not consulted. May be intended (agent/cron push) but undocumented.
5. POST /api/catalogue/quote requires authentication, so it cannot serve as a public RFQ form (the public intakes are the Transactions and Store routes). Does not validate email format or that the SKU exists; returns 200 (not 201).
6. Announcements POST on an existing (source, external_id) row overwrites `source_url` and `expires_at` with null when omitted (title/body always required). Only `audience` is protected from reset. Easy to surprise a feeder that re-posts partial data.
7. ai-ssa-chat PUT /config/{instanceId} always answers 200 even when it creates the widget (email and phone PUT answer 201 on create). PUT 422 branch "That instance already has a chat widget" cannot occur via this route.
8. ai-ssa-chat /message validates session_id/message before checking the embed token, so a missing token yields "Unknown session: start a new one" (422) rather than "embed_token is required".
9. ai-ssa-email POST /baseline: the 415 JSON Content-Type check in ApiControllerBase applies to every non-GET session request, including this body-less POST, so a session caller must still send Content-Type: application/json.
10. Forum POST /events: a replay is detected only after envelope validation, and the stored payload is not compared with the replayed one (first wins silently). 404 for an unknown game is returned before payload validation, so a missing `game_key` gives 404 "Unknown game", not 422.
11. KPI `period_start` check uses DateTimeImmutable::createFromFormat('Y-m-d') which is lenient (2026-02-30 rolls over); a non-existent period then yields the 422 "No <cadence> period starts on ..." anyway.
12. Licensing /api/lice/checkin and KPI /api/kpi/entry, and all Lin routes, register no `via()` method restriction, so any HTTP method reaches the controller (POST-only ones answer 405 in-controller; Lin GET-style `drafts` and `context` accept any verb).
13. ai-ssa-application AuditController comment says session-authenticated staff only but it accepts API keys too (same base class).
14. MAAMA handler allows `status` filter values in list but 422s on invalid ones, while Requirements list silently ignores invalid filters; inconsistent across modules.
15. Requirements create silently coerces an invalid type/priority/status to defaults, while update returns 422.

## In code but not (directly) routable / skipped
- Announcements: /announcements/assets/{name}.js static route (not a JSON API, omitted); /announcements/:controller/:action HTML admin screens (omitted).
- Forum: /forum/events, /forum/events/view/{id}, /forum/events/retry/{id}, /forum/games, /forum/games/save are admin HTML screens, omitted. The doc's planned `POST /api/forum/v1/sso/token` is described in Forum-Service-Interface-v0.1.md as a follow-up and has no route.
- Catalogue: /catalogue/products and /catalogue/products/view/{id} are admin HTML screens, omitted.
- Store: /, /store, /store/category/{slug}, /store/product/{slug}, /store/settings, asset routes are HTML or static, omitted. `/store/search` is documented because it returns JSON.
- Transactions: only /transactions/public-quote/create is a JSON API route; the rest is backend HTML via generic routes.
- Licensing: only /api/lice/checkin; KeysController and IndexController are HTML.
- Router-level: the in-controller 405 branches for chat start/message/widget and transactions/store quote are unreachable (router `via` restricts to POST/OPTIONS or GET/OPTIONS).
- Requirements has no registerRoutes: its JSON API is reachable only through the generic `/requirements/:controller/:action/:params` route as controller `api` (`/requirements/api/index|view/{id}|create|update/{id}`). `/requirements/api/{id}` (no action) is NOT view; it routes to action `index` with params.
- MAAMA: `/maama/api/handovers/{id}` is not a route to view; use `/maama/api/handovers/view/{id}`. Unknown /maama/api/* paths return JSON 404 via Api\IndexController.
- Lin: routes are `/lin/api/...` (module key first), not `/api/lin/...`.
- Not documented: the chat plugin's HTML/backend config screens, ai-ssa admin screens, kpi/lin/maama/requirements backend screens.
