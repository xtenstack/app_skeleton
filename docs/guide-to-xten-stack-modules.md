# Guide to XTen.Stack Modules

This is the map for anyone who's just installed App Skeleton and wants
to understand what's already there before extending it: what a
"module" means in this codebase, which ones ship with the base product,
and how to build your own.

## Two kinds of module

### Core (application-defining) modules

`backend`, `api`, `cli`, and `frontend` — always on, wired directly into
`app/bootstrap_web.php`'s `$builtInModules` (and `bootstrap_cli.php` for
`cli`). They have no `module.json`, never appear in the admin
Configuration page's module list, and can't be disabled through the
UI. That's deliberate, not an oversight: disabling `backend`, for
example, would break the very Configuration page you'd use to disable
it. These are the app, not features bolted onto it.

| Module | What it is |
|---|---|
| `backend` | Server-rendered AdminLTE admin UI — staff/admin-facing. |
| `api` | JSON-only, session- or API-key-authenticated. |
| `cli` | `./run <task> <action>` — migrations, seeding, module management, and anything else scripted. |
| `frontend` | The public-facing side: the guest landing page and the non-admin member's own dashboard. Separate from `backend` on purpose — an admin's dashboard and a customer's dashboard are different products wearing the same login form. |

### Optional (plug-in) modules

Composer packages carrying a `module.json` manifest, discovered by
`App_skeleton\ModuleManager` and toggled per-instance from the admin
Configuration page — `./run modules sync|list|enable|disable` is the
CLI side of the same system. An instance that doesn't need a feature
simply doesn't install (or disables) the package; core code never
changes.

Ticketing (customer support tickets) currently ships as a *built-in*
feature rather than a real optional module — a deliberate, documented
exception (see `docs/ticketing-module-plan.md`'s Context section):
`ModuleManager`'s own design was still unsettled when Ticketing was
built, so guessing at module boundaries from nothing was avoided in
favor of shipping a working feature first. The Requirements module
(`docs/requirements-module-plan.md`) is the first feature built as a
*real* optional module, now that `ModuleManager` v1 is proven — treat it
as the current worked example for the pattern below, not Ticketing.

## Building your own module

1. A Composer package (its own repo, or a local `path` repository during
   development) with a `module.json` manifest — see
   `docs/module-system-design-brief.md` for the manifest shape
   `ModuleManager::discover()` expects.
2. Your own `Module.php` implementing `registerAutoloaders()` /
   `registerServices()`, the same shape every module here already uses
   (`app/modules/*/Module.php` are all real examples to copy from).
3. Controllers/models under your package, autoloaded the same
   per-module way — see CODING-STANDARDS.md's PHP section for why
   models are unqualified globals (`Tickets::find(...)`) rather than
   namespaced.
4. A `migrations/<adapter>/*.sql` directory if you need schema — the
   module-aware migration runner (`./run migrate run`,
   `app/modules/cli/tasks/MigrateTask.php`) applies any installed
   module's migrations alongside the base engine's own, tracked
   separately.
5. A menu entry your `Module.php` (or a `registerRoutes()`/menu hook —
   see the design brief) contributes; `ModuleManager::mergedMenu()`
   merges every enabled module's items into the sidebar automatically.
6. `composer require` it, `./run modules sync`, enable it from the
   Configuration page (or `./run modules enable <key>`).

Follow `docs/requirements-module-plan.md`'s shape when drafting a plan
for your own module before building it — context, design decisions,
schema, controllers, menu entry, "what this plan explicitly does not
do," and a verification checklist. That structure is what makes an
autonomous or human build reviewable before code exists, not just after.

## Standard conventions your module should follow

- List views: `docs/runbooks/RB-03-list-view-conventions.md` — row
  actions, bulk operations, the checkbox/form pattern.
- Branching/commits: `docs/runbooks/RB-01-branching-and-release-strategy.md`.
- Soft deletes, audit logging, CSRF, RBAC: CODING-STANDARDS.md.

## Why the split

Every optional module can be removed with zero trace in core code —
that's the whole point of the Composer-package boundary. Core modules
can't be, because they *are* what "App Skeleton" means. If you're
unsure which kind your feature is, ask: "would every single instance of
this product need this to function at all?" If yes, it's core (and
probably belongs in a conversation with the base engine's maintainers,
not a plug-in). If no, it's a module.
