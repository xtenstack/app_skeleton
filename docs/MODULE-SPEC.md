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
| `migrations` | No | Relative path to the module's own `migrations/<adapter>/` tree, applied by the migration runner. |
| `icon` **(planned)** | No | Path to a square SVG/PNG shipped in the package. Engine will apply a default icon when absent so a module can never render icon-less on the dashboard or nav. |
| `license` **(planned)** | Paid modules only | `{ "model": "per-instance", "keyRequired": true }` — declares licensing; `keyRequired: false` for free modules. See the design brief's licensing sections for the check-in/enforcement mechanics this ties into. |
| `dependsOn` **(planned)** | No | Array of other modules' `key`s this module requires to already be installed and enabled (e.g. `["ACC"]`). Engine will refuse to enable a module until every declared dependency is enabled. Data flow between dependent modules happens over the event bus (below), never direct table access — `dependsOn` only gates *whether* a module can run. |

Only `key` and `tier` are actually validated as required fields today
(`ModuleManager::REQUIRED_FIELDS`) — a manifest missing anything else
is discovered but may not function (e.g. no `className` on an
application-tier module means it's silently never routed).

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
}
```

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
`Module::registerServices($di)`. This is the *only* sanctioned channel
for one module to react to another module's state changes — direct
reads/writes into another module's tables are out of scope regardless
of `dependsOn` (see Isolation, below).

## Isolation

- Everything hangs off `user_id`.
- A module defines and owns its own tables if it needs storage — no
  shared/implicit state with another module's schema.
- `dependsOn` **(planned)** gates whether a module can be *enabled*;
  it does not grant schema access. Field-level expectations about
  another module's event payloads are declared explicitly by the
  consuming module, never inferred/auto-discovered from schema
  inspection at runtime.

## Enable/disable state

`module_registry` (migration `010`) is the one shared table — no FKs
into any module's own domain tables, just `module_key` / `code` /
`tier` / `package_name` / `version` / `enabled`. Toggle via
`./run modules sync|list|enable|disable` or the admin Configuration
page.

## Licensing

Not enforced by the engine today. Planned shape (see design brief): a
paid module declares `license` in `module.json`; the installed instance
tracks `last_successful_checkin_at`, advanced only by a successful,
usage-gated (not calendar-gated) check-in against XTen's license
server; a 120-day-since-last-contact grace period surfaces an unmissable
admin modal rather than disabling the module outright. Catalogue modules
ship under a short proprietary EULA; bespoke client-delivered modules
ship MIT once delivered — see the design brief's licensing sections for
the full reasoning.

## What's still genuinely open

- Non-Composer module discovery (a hand-written module not installed as
  a Composer package is currently invisible to `discover()`).
- Everything marked **(planned)** above — agreed design, not yet code.
