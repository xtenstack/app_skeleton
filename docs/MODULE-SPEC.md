# Module Spec

The technical contract a module package must satisfy to be discovered,
enabled, and run by `ModuleManager`. Companion to
`module-system-design-brief.md` (private, `stack.xten.au/docs/`) — that
doc is the decision history and *why*; this one is the *what*, kept in
the public repo since it's what a module builder (internal or DIY/
community tier) actually needs open in front of them.

Fields/behavior marked **(planned)** are agreed design, not yet read or
enforced by `ModuleManager` — don't rely on them until this note is
removed. Everything else reflects real code as of 2026-08-12
(`app/common/library/ModuleManager.php`, `app/config/routes.php`, a real
shipped module — `xtenstack/requirements-module` — used as the reference
example throughout).

## Two module tiers

1. **Application-defining** (`tier: "application"`) — anchors what an
   instance *is*. One or more can be installed and enabled on the same
   instance without cross-contamination. Registered with Phalcon as a
   real module (`registerModules()`), gets its own `/<key>/...` route
   namespace.
2. **Plugin** (`tier: "plugin"`) — small, portable, usable across any
   application module. Not registered as a Phalcon module or routed the
   same way; consumed by application modules that depend on it.

## Directory layout

A module is a normal Composer package. Minimum shape (from the
requirements-module reference):

```
your-module/
  composer.json         # standard Composer metadata; "type": "library"
  module.json           # the manifest — see below
  src/
    Module.php           # Phalcon\Mvc\ModuleDefinitionInterface
    controllers/
    models/
  migrations/
    postgresql/
      001_*.sql           # applied by the module-aware migration runner
  views/
    partials/
      sidenav.phtml        # optional, only if the module needs a distinct chrome
      topnav.phtml
      footer.phtml
  menu.php               # returns the array `mergedMenu()` merges in
```

`composer.json`'s package `name` (e.g. `xtenstack/requirements-module`)
is what Composer discovers — `module.json`'s `key` is what the engine
uses internally, and the two do not need to match.

## `module.json`

```json
{
    "key": "requirements",
    "code": "reqs",
    "tier": "application",
    "className": "XtenRequirements\\Module",
    "surface": "backend",
    "menu": "menu.php",
    "migrations": "migrations"
}
```

| Field | Required | Meaning |
|---|---|---|
| `key` | **Yes** | Internal identifier — used in `module_registry.module_key`, routing (`/<key>/...`), and everywhere the engine refers to this module. Unique across all installed modules. |
| `tier` | **Yes** | `"application"` or `"plugin"` — see above. |
| `className` | For application- and plugin-tier | Fully-qualified `Module` class implementing `Phalcon\Mvc\ModuleDefinitionInterface`. Required for `registeredPhalconModules()`/routing to pick the module up at all — omitting it silently leaves the module discovered but never actually registered with Phalcon. |
| `surface` | No (defaults to `'backend'` in `mergedMenu()`) | `"backend"`, `"frontend"`, or `"both"` — which nav surface(s) the module's menu contribution applies to. |
| `menu` | No | Relative path (from the module's install root) to a PHP file returning an array in the same `{label, icon, controller, url, roles}` shape the built-in `menu.php` uses. No menu items → omit this field entirely, not an empty file. |
| `code` | No | Short display code (e.g. `"reqs"`); not currently read by the engine, informational/reserved. |
| `routes` | No | `false` for a headless (service-only) module with no controllers or views: the engine then adds none of the generic `/<key>/...` routes, so those URLs 404 like any other unknown path instead of dispatching into a module that cannot render. Defaults to `true`, except that a module with no `src/controllers` (or `controllers`) directory is treated as headless even without the flag. A `registerRoutes()` method is still honoured either way. See Headless modules, below. |
| `migrations` | No | Relative path to the module's own `migrations/<adapter>/` tree, applied by the migration runner. |
| `icon` **(planned)** | No | Path to a square SVG/PNG shipped in the package. Engine will apply a default icon when absent so a module can never render icon-less on the dashboard or nav. |
| `license` | Paid modules only | `{ "model": "per-instance", "keyRequired": true }` for a module that needs a licence key of its own, `{ "sharesKeyWith": "<module key>" }` for one that uses another module's key, `{ "keyRequired": false }` (or no `license` at all) for a free one. See Licensing, below. |
| `dependsOn` | No | Array of other modules' `key`s this module requires to already be installed and enabled (e.g. `["acc"]`). The engine refuses to enable a module until every declared dependency is enabled, and disabling a module disables its dependents with it. See Dependencies, below. Data flow between dependent modules happens over the event bus, never direct table access — `dependsOn` only gates *whether* a module can run. |
| Bundling | N/A — real | Not a manifest field at all — a module bundles another by naming it in its own `composer.json` `require` (every module already is a real Composer package). `ModuleManager::enableModule()` (used by both `./run modules enable <key>` and the admin Configuration page — never write `module_registry.enabled` directly) reads the enabling module's own `composer.json` and also enables any other discovered module it requires. One direction only: enabling `ai-ssa-application` enables its `ai-ssa-chat`/`ai-ssa-phone`/`ai-ssa-email` plugins too; disabling it does **not** cascade-disable them, since a plugin stays fully usable standalone even after the module that first brought it in is turned off. Adopted 2026-09-13 for the AI-SSA product family. |

Only `key` and `tier` are actually validated as required fields today
(`ModuleManager::REQUIRED_FIELDS`) — a manifest missing anything else
is discovered but may not function (e.g. no `className` on an
application-tier module means it's silently never routed). `dependsOn`
is validated when present — see Dependencies, below.

## `src/Module.php`

Implements `Phalcon\Mvc\ModuleDefinitionInterface`:

```php
class Module implements ModuleDefinitionInterface
{
    public function registerAutoloaders(?DiInterface $di = null)
    {
        $loader = new Loader();
        $loader->setNamespaces(['YourModule\Controllers' => __DIR__ . '/controllers/']);
        $loader->setDirectories([__DIR__ . '/models/']); // bare/global model classes
        $loader->register();
    }

    public function registerServices(DiInterface $di)
    {
        // Only needed if the module wants its own view path/engines.
        // Everything else (db, session, auth, flash, moduleManager, audit,
        // eventsBus...) already comes from the shared app-level DI —
        // don't re-register services that already exist globally.
    }

    // Optional — see Routes below.
    public function registerRoutes(Router $router) { /* ... */ }

    // Optional — see Shared services below.
    public function registerSharedServices(DiInterface $di): void { /* ... */ }
}
```

## Shared services

Phalcon calls `registerServices()` **only for the one module a request is
dispatched to**. Anything a module registers there does not exist while
any other module is handling a request. That is right for per-module
things (its `view`), and wrong for anything the rest of the app is meant
to use.

A module that offers a service to other modules, or attaches `eventsBus`
listeners that must fire whichever module is handling the request,
defines:

```php
public function registerSharedServices(DiInterface $di): void
{
    $di->setShared('yourService', fn () => new YourService($di->getShared('db')));
}
```

`ModuleManager::registerSharedServices()` calls it for every **enabled**
module on every web request and every CLI run (so cron tasks see the same
services), before dispatch. Rules:

- Register lazily (`setShared` with a closure). The hook runs on every
  request, so it must do no work beyond registration: no queries, no I/O.
- Use a service name unique to the module. Do not override core services.
- A hook that throws is logged and skipped; the rest of the instance
  keeps working, but that module's services will be missing.
- Consumers must not assume the service exists, since the module may be
  disabled or not installed: check `$di->has('yourService')`. A consumer
  that declares the providing module in `dependsOn` (see Dependencies,
  below) is only ever loaded when the provider is, and after it.

## Headless modules

A service-only plugin (no controllers, views or menu) sets
`"routes": false` in `module.json`, registers what it offers in
`registerSharedServices()`, and leaves `registerServices()` empty. It is
still enabled, migrated and licensed like any other module; it just has
no URL space of its own.

## Dependencies

A module that cannot work without another declares it in `module.json`:

```json
{
    "key": "ap",
    "tier": "application",
    "className": "XtenAp\\Module",
    "dependsOn": ["acc"]
}
```

`dependsOn` is an array of other modules' `key`s — the `key`, matched
exactly, not the `code` or the Composer package name. Keys only: there
is no version-constraint form. Omit the field when there is nothing to
declare. The built-in modules (`backend`, `api`, `cli`, `frontend`) are
always on and are not module keys; don't list them.

What the engine does with it (`ModuleManager`):

- **Enable guard.** `enableModule()` refuses to enable a module while
  any module it declares is not installed or not enabled, and says
  which: `ap cannot be enabled: it requires acc (not enabled).` Both
  enable paths — `./run modules enable <key>` and the admin
  Configuration page — go through that one method, so neither can
  bypass it.
- **Cascading disable.** `disableModule()` also disables every enabled
  module that depends on the one being disabled, directly or through
  another module, and reports which. Disabling `acc` disables `ap`, and
  anything that depends on `ap`. A dependent is therefore never left
  running against a dependency that is off. Re-enabling `acc` does
  **not** re-enable what was cascaded off it; that stays a deliberate
  admin action. The Configuration page shows what a Disable will take
  with it ("Also disables: ...") before the button is pressed.
- **Load order.** Dependencies are registered before the modules that
  declare them — `registerSharedServices()` hooks, route registration
  and menu contributions all run in that order (otherwise in discovery
  order). A service a dependency registers in its
  `registerSharedServices()` exists by the time a dependent's own hook
  runs.
- **Inconsistent state.** Enabling and disabling keep `module_registry`
  consistent, but a hand-edited row or a removed package can still
  leave a module enabled above a dependency that is disabled or gone.
  Such a module is **not loaded** for the request — no routes, no menu,
  no shared services — and the reason is written to the error log once
  per request. Nothing fatals and no other module is affected. The
  Configuration page shows it as "Enabled, not loaded" and
  `./run modules list` as `not loaded`, each with the dependency that
  is missing; enabling the dependency (or disabling the module) clears
  it.
- **Manifest errors.** A `dependsOn` that isn't an array of non-empty
  strings, or one that forms a cycle (`a` needs `b` needs `a`,
  including a module naming itself), is an error for the module(s)
  concerned only. The module stays discovered — its migrations still
  apply — but it is never loaded and can't be enabled until the
  manifest is fixed; the error is logged and shown against the module
  on the Configuration page and in `./run modules list`.

`dependsOn` and bundling (the `module.json` table, above) are separate
things. Bundling is "enabling me also enables these" and never cascades
a disable. `dependsOn` is "I can't run without these": it never enables
anything by itself, and it does cascade a disable. A bundled module
whose own `dependsOn` isn't satisfied is left disabled when its bundle
is enabled.

Two rules come with `dependsOn` that are conventions for module
authors, not something the engine checks:

1. **Cross-module data flow goes over the event bus or a service the
   other module publishes** (`registerSharedServices()`), never another
   module's tables. `ap` emits `ap:invoice_posted`; `acc` listens and
   writes its own journal rows. Declaring `dependsOn` gates whether a
   module can run; it grants no access to the dependency's schema.
2. **Field mapping is declared explicitly by the consuming module.** A
   module that consumes another module's event payload or service
   states which fields it expects, in its own code or docs. It is never
   inferred or auto-discovered by inspecting the other module's schema.

## Routes

Application-tier modules get a generic route set for free, once enabled
and registered:

- `/<key>/:params` → `IndexController::indexAction()`
- `/<key>/:controller/:params` → that controller's `indexAction()`
- `/<key>/:controller/:action/:params`

For anything outside that shape (e.g. a public certificate-validation
URL with no `:controller/:action` structure), implement the optional
`registerRoutes(Router $router)` method on the `Module` class —
`app/config/routes.php` calls it automatically via `method_exists()` if
present, no registration step needed elsewhere.

## Menu contribution

`ModuleManager::mergedMenu($surface)` includes the built-in menu plus
every enabled application-tier module's own `menu` file (matched by
`surface`), each returning the same shape the built-in menu already
uses:

```php
return [
    ['label' => 'Requirements', 'icon' => 'fas fa-list-check', 'controller' => 'requirements', 'url' => 'requirements', 'roles' => [...]],
];
```

**Current behavior**: both application- and plugin-tier enabled modules
get a real route namespace (`registeredPhalconModules()`) and a menu
contribution (`mergedMenu()`) — fixed 2026-08-12, Modules Session #1,
verified with a throwaway plugin-tier module (routed correctly, showed
up in the merged menu output). Today this still merges every enabled
module's menu into one combined sidebar — no per-module nav *switching*
yet. **Planned** (see design brief "v1.2 direction"): a left-nav
"Modules >" collapsible listing every enabled module (app or plugin
tier), where selecting a module name does a full nav takeover
(everything but Dashboard replaced by that module's own menu), and a
top-nav shortcut does the same for application-tier modules
specifically — plugin-tier modules are reachable via the left-nav route
only, never the top-nav switcher. The takeover UI itself is not yet
implemented in code; only the underlying routing/menu-eligibility fix
is.

## RBAC

Whole-controller only today — `ControllerBase::$allowedRoles` is a
role-list-or-null gate on an entire controller, no per-action or
per-record grain. This is a deliberate, not-yet-fixed gap: a module
needing finer permission control is expected to compose with a future,
separate on-hold **permissions module** (REQ-064), not to invent its
own grain.
Declare `$allowedRoles` per controller the same way the base engine's
own controllers do.

## Event bus

Shared `eventsBus` service (Phalcon `EventsManager`, colon-namespaced
events like `payment:completed`, `user:created`). Attach listeners in
`Module::registerSharedServices($di)` (see Shared services above — a
listener attached in `registerServices()` only exists while that module
itself is handling the request, so it would miss every event fired from
another module). This is the *only* sanctioned channel
for one module to react to another module's state changes — direct
reads/writes into another module's tables are out of scope regardless
of `dependsOn` (see Isolation, below).

## External Credentials

A module that needs to call a third-party API (an email/SMS provider,
an AI model API, a payment processor, anything outside this instance)
looks the credential up from `external_connections`
(`App_skeleton\ExternalConnections::findActiveByName('provider-name')`,
lowercase, matches case-insensitively) — it does **not** invent its own
encrypted column, plaintext config field, or bespoke env var for this.
`revealCredential()` decrypts it (via `App_skeleton\Crypto`) for one
call only; never cache or log the plaintext value.

This is the one sanctioned exception to Isolation's "no shared state
with another module's schema" rule below — `external_connections` is a
shared table by design, the same way `module_registry` is, not an
implicit coupling. A module still owns and manages its own *rows* in it
(create/edit through the admin UI or a migration-time seed) — it just
doesn't own a private copy of the table.

If nothing's configured yet, `findActiveByName()` returns `null` —
handle that the same way `Mailer` does (log and no-op, or fall back to
config for one deprecation window if migrating an existing credential
off an older storage path), never a hard failure over a missing
integration.

Adopted 2026-09-13 after `Mailer`'s own Resend key was found still
living in `config.local.php` despite `external_connections` already
existing exactly for this — every AutoClaudeDev module plan going
forward should assume this table, not propose its own credential
storage (the AI-SSA application module's `sidecar_auth_pass text not
null (store as-is)` is the gap that prompted writing this down).

### Encrypting a module's own secrets

A module that has to store a secret of its own (an OAuth token for one of
its users, say) rather than a shared integration credential encrypts it
with `App_skeleton\Crypto::encrypt()` and **declares the column in
`module.json`** so `./run crypto rekey` re-encrypts it along with core's:

```json
"encrypted_columns": [{"table": "lin_connections", "column": "access_token_enc"}]
```

The table's primary key must be `id`. A column that isn't declared here is
left on the old key by a rekey and becomes unreadable.

## Isolation

- Everything hangs off `user_id`.
- A module defines and owns its own tables if it needs storage — no
  shared/implicit state with another module's schema.
- `dependsOn` gates whether a module can be *enabled* (see
  Dependencies, above); it does not grant schema access. Field-level
  expectations about another module's event payloads are declared
  explicitly by the consuming module, never inferred/auto-discovered
  from schema inspection at runtime.

## Enable/disable state

`module_registry` (migration `010`) is the one shared table — no FKs
into any module's own domain tables, just `module_key` / `code` /
`tier` / `package_name` / `version` / `enabled`. Toggle via
`./run modules sync|list|enable|disable` or the admin Configuration
page. Both go through `ModuleManager::enableModule()` /
`disableModule()`, which is where bundling and the `dependsOn` guard
and cascade live — never write `module_registry.enabled` directly.
Dependencies themselves are not stored: `module.json` is the only
source for them.

## Licensing

A paid ("catalogue") module is licensed per instance by a licence key.
The engine stores the keys, checks them with the licence server, and
keeps the answer locally; the module asks the engine whether it is
licensed and decides for itself what to restrict. Implemented in
`App_skeleton\LicenseManager` (the `licenseManager` service, on web and
CLI) and `App_skeleton\LicenseCheckinClient`.

### Declaring it

In `module.json`:

| `license` | Meaning |
|---|---|
| absent, or `{ "keyRequired": false }` | Free. Always licensed; the engine never makes a call for it. |
| `{ "model": "per-instance", "keyRequired": true }` | Needs a licence key. `model` is informational; `per-instance` is the only model there is. |
| `{ "sharesKeyWith": "<module key>" }` | Needs a licence, and can use the key stored for the named module: the engine sends *that module's key* with *this module's own key as the module code*. A key ticked for this module directly is tried too, which is how a plugin bought separately from its bundle is licensed. |

Only a literal `true` for `keyRequired`, or a non-empty `sharesKeyWith`,
makes a key required. Entitlement is per module: a bundle key that the
licence server says covers `ai-ssa` and `ai-ssa-chat` licenses those two
and not `ai-ssa-phone`, even though all three share the one key.

The module code sent to the licence server is the module's `key`, not
its `code`. Whoever issues a licence key must list the modules it covers
by `key`.

### What a module calls

```php
$licenseManager = $this->getDI()->getShared('licenseManager');

if (!$licenseManager->isLicensed('your-module-key')) {
    // restrict whatever this module restricts when unlicensed
}
```

`isLicensed()` is a local read (a few small queries on first use in a
request, none at all for a free module). It never touches the network and never throws, so it is
safe in a controller, a view or a cron task. For more than yes/no:

```php
$entitlement = $licenseManager->entitlement('your-module-key');
// ['module', 'state', 'licensed', 'required', 'sharesKeyWith', 'hasKey',
//  'licenseKeyId', 'lastSuccessfulCheckinAt', 'lastAttemptAt',
//  'lastResult', 'graceDaysLeft']
```

`licenseKeyId` is the id of the stored key (`license_keys.id`) that last
validated the module. The key itself is never available to a module.

**Nothing is unloaded.** The engine does not disable, unload or hide an
unlicensed module, and `ModuleManager` treats it exactly like any other
enabled module. Enforcement is the module's own: it asks, and restricts
what it chooses. What the engine does by itself is tell the admin (see
"What the admin sees").

### States

| `state` | `licensed` | Meaning |
|---|---|---|
| `not_required` | yes | The module needs no key. |
| `valid` | yes | The most recent check-in succeeded, and that was within the last 120 days. |
| `grace` | yes | A check-in has succeeded within the last 120 days, but the most recent attempt did not: the server refused the key (`lastResult` `rejected`) or could not be reached (`unreachable`). |
| `expired` | no | The last successful check-in is more than 120 days old. |
| `unvalidated` | no | A key is stored for the module, but no check-in has ever succeeded. |
| `no_key` | no | The module needs a key and none is stored for it. |
| `not_installed` | no | No module with that key is installed (so a mistyped key never reads as licensed). |

The grace period is 120 days from `lastSuccessfulCheckinAt`
(`LicenseManager::GRACE_DAYS`), evaluated locally every time the state
is read: `now - lastSuccessfulCheckinAt > 120 days` is `expired`, and
exactly 120 days is still `grace`. Only a successful check-in moves that
timestamp. A check-in that is refused, times out or cannot connect
records the attempt and changes nothing else, so an outage at either end
never costs a licensed module its licence; a revoked key likewise keeps
working until the 120 days are up.

Check-in history belongs to the key that earned it. Removing a key
returns every module it had validated to `no_key` (or `unvalidated`, if
another key is stored for it) at once. Replacing a key in place keeps
the history, and the new key is what is sent from then on.

### When a check-in happens

One `POST <licence server>/api/lice/checkin` per module, body
`{"key": "...", "module": "<module key>"}`. The server answers
`{"valid": true}` or a 403 `{"valid": false}` that is deliberately the
same for an unknown key, a revoked key and a module the key does not
cover. Nothing else is ever sent. The exact exchange is under "The
check-in contract", below.

- **Usage-gated, not on a calendar.** The first authenticated request of
  each calendar day (a signed-in page or an API-key call; the engine
  asks `currentPrincipal`, so a keyed request, which has no session,
  counts) triggers one check-in of every enabled module that needs a
  key, after the response has been sent. What that check-in changes is
  audited with no actor: it is the instance's doing, not the caller's. A cron pass (`./run cron run`) counts as use too and makes the day's check-in if no request has yet, so an instance that only runs scheduled jobs does not run out of grace. An instance nobody uses and nothing runs on makes no calls. The day is
  marked as taken before anything is sent, so a failed check-in is not
  retried until the next day. There is no cron job. Under PHP-FPM (the
  Docker image) and LiteSpeed the response is finished before the call
  is made. On a SAPI that cannot do that (PHP's built-in server,
  mod_php) the page is flushed to the browser first, but its connection
  stays open until the check-in returns, which the timeouts below bound.
- **When a key is saved**, for the modules it is tried for, and **when a
  module is enabled** (Configuration page or `./run modules enable`).
  Enabling is never refused or delayed over the result.
- **On demand:** "Check now" on the Licences screen, or
  `./run license checkin [<module-key>]` (which can be scheduled by an
  instance that wants to). `./run license status` prints the local state.

The licence server is `licensing.server_url` in `config.local.php`
(`LICENSE_SERVER_URL` in a Docker `.env`); empty means XTen's own. It
must be `https://`. Timeouts are short (3 s to connect, 6 s in all), a
redirect is not followed, and once the server proves unreachable the
remaining modules of that run are recorded as unreachable without being
tried.

**An instance with no paid module installed does nothing.** The
after-response hook returns once it has looked at the module manifests
(already in memory): no query, no principal or session lookup, no
settings row, no outbound request. No notice or modal is rendered. All
that shows is the *Licences* menu entry, with an empty screen behind it,
and a *Licence* column on Configuration reading "No key needed".

### The check-in contract

What `LicenseCheckinClient` sends and accepts. The server side is the
`licensing` module in the private internal-modules repo
(`XtenLicensing\Controllers\Api\CheckinController`), which is installed
on XTen's own instance only.

| | |
|---|---|
| Request | `POST /api/lice/checkin`, `Content-Type: application/json`, no cookie, no API key: the licence key in the body is the only credential. |
| Body | `{"key": "<licence key>", "module": "<module key>"}`. One module per request. |
| Covered | `200` with `{"valid": true, "expires_at": "YYYY-MM-DD"}`, or `"expires_at": null` when the licence has no end date. The only answer that moves `last_successful_checkin_at`. `expires_at` is the last day the module is covered (UTC); a server that omits it is read as no end date. |
| Not covered | `403` with `{"valid": false}`: unknown key, key not active, key that does not cover that module, a module past its `expires_at`, or an empty `key` or `module`. Recorded as `rejected`. |
| Anything else | A timeout, a refused connection, a `5xx`, a `405` (the server's answer to a non-POST), a redirect, a body that is not that JSON, or a `valid` that does not match its status. Recorded as `unreachable`: the question was not answered, which is not a no. |

The answer carries nothing else. In particular the server does not say
which other modules the key covers, so the engine asks per module.

**Expiry.** The end date lives on the licence server, per module on the
key, and the server is the authority: past that day it answers `valid:
false`, and the instance then runs out its 120 days of grace like any
other refusal. The instance keeps a copy of the date
(`license_entitlements.expires_on`, `entitlement()['expiresOn']` and
`['expiresInDays']`) only to tell admins: the Licences screen shows it,
and from `LicenseManager::EXPIRY_NOTICE_DAYS` (30) days out every backend
page carries a "Licence ending soon" notice for admins. The copy never
switches a module off by itself. Reminder emails to the customer are the
licence server's job (it knows the client and the invoice); none are sent
yet.

### The `license:changed` event

Fired on `eventsBus` whenever a module's saved state changes, after the
new state is in the database:

```php
$di->getShared('eventsBus')->attach('license:changed', function ($event, $licenseManager, array $data) {
    // $data = [
    //     'module'       => 'ai-ssa-chat',
    //     'state'        => 'valid',        // the new state, as in the table above
    //     'previous'     => 'unvalidated',  // null the first time a state is recorded
    //     'licensed'     => true,
    //     'licenseKeyId' => 3,              // key that last validated it, or null
    // ]
});
```

Ids and states only; the key is never in the payload. Attach the
listener in `registerSharedServices()` (see Shared services): changes
are saved from web requests, from the CLI and from the check-in that
runs after a response. A listener that throws is logged and does not
undo the change, but listeners queued after it do not see that event, so
catch your own exceptions.

A module that keeps its own copy of licence state (AI SSA's per-channel
licence rows, for one) feeds it from this event and from
`entitlement()`, read-only, rather than having staff type it in.

One caveat: a state that changes with the passage of time alone
(`valid` or `grace` becoming `expired`) is always *read* correctly, but
the event for it fires at the next check-in run, which is the next day
the instance is used, or the next `./run license checkin`.

### What the admin sees

On every backend page, admins only:

- **In grace:** a warning naming the module, why the last check-in did
  not succeed, and the days of grace left. Nothing is restricted.
- **Not licensed** (`expired`, `unvalidated` or `no_key`, for an enabled
  module): a modal on every page load that has to be closed to carry on,
  and a standing alert in the page (which is also what shows with
  JavaScript off). The module is not disabled. The modal is not shown
  on the Licences screen itself.

These are rendered by the backend layout. A module with its own layout
can show the same thing from `$licenseManager->adminNotice()`.

### Storage

Core tables, migration `024`: `license_keys` (the key encrypted with
`App_skeleton\Crypto`, plus its last four characters for display;
included in `./run crypto rekey`), `license_key_modules` (which modules
a key is tried for) and `license_entitlements` (the local record:
state, last successful check-in, last attempt and its result, and the
key that validated the module). They belong to the engine. A module
reads licence state through `licenseManager`, not from these tables.

The audit log records that a key was added, replaced or removed, never
the key itself, encrypted or not (`LicenseKeys::auditRedactedFields()`),
so removing a key leaves no copy of it on the instance.

### Bundles

A module that's *bundled* by another (its Composer package named in the
bundling module's own `require` — see "Bundling" in the `module.json`
table above) declares `{ "sharesKeyWith": "<bundling module's key>" }`
instead of its own `model`/`keyRequired` pair. `ai-ssa-application` is
the first real case: `ai-ssa-chat`/`ai-ssa-phone`/`ai-ssa-email` each
declare `sharesKeyWith: "ai-ssa"`, so a client who bought the bundle
enters one key, ticked for `ai-ssa`, and each channel is validated with
that key under its own module key. A channel bought without the bundle
gets a key of its own, ticked for that channel; no change to its
`module.json` is needed for that.

Catalogue modules ship under a short proprietary EULA; bespoke
client-delivered modules ship MIT once delivered — see the design
brief's licensing sections for the reasoning.

## What's still genuinely open

- Non-Composer module discovery (a hand-written module not installed as
  a Composer package is currently invisible to `discover()`).
- Everything marked **(planned)** above — agreed design, not yet code.
