# Module System Design Brief

Status: **v1 built and live** (`App_skeleton\ModuleManager`, commit `5192d5f`,
2026-07-29) — Composer-package discovery, a `module_registry` table, a shared event
bus, and an admin Configuration page toggle, verified end-to-end with a throwaway test
package. This brief was originally written 2026-07-27, **two days before** that build
landed, and was never updated afterwards — the rest of this document (written
2026-07-27) is the original design discussion and is now partially superseded. See
"What v1 actually built" below (added Session 10, 2026-08-01) for what's real today and
what's still genuinely open.

## What v1 actually built (added Session 10, 2026-08-01)

Reconciling this brief against `app/common/library/ModuleManager.php`,
`app/modules/cli/tasks/ModulesTask.php`, `app/config/routes.php`, `app/config/services.php`,
and `app/modules/backend/controllers/{ConfigurationController,IndexController}.php`:

- **Discovery**: Composer-only — `Composer\InstalledVersions::getInstalledPackages()`,
  filtered to packages with a `module.json` manifest (`key`, `tier`, `className`,
  `surface`, `menu`, `code`). **Gap**: a module that isn't an installed Composer package
  (someone hand-writing one directly in the app tree, per this brief's own "someone who
  installs the base may wish to write their own module" requirement) is invisible to
  discovery as it stands. Likely cleanest fix: point `composer.json`'s `repositories` at
  a local `path` repo and `require` it like any other package — live-editable locally,
  still "a package" for discovery — rather than adding a second, parallel filesystem-scan
  code path. Not yet decided.
- **Enable/disable state**: `module_registry` table (migration `010`) — confirms this
  brief's own open question 4 (a thin shared table is unavoidable) the way it predicted:
  no FKs into any module's domain tables, just key/tier/package/version/enabled.
- **Routes**: module-scoped, resolving open question 1 in the direction this brief
  leaned — Phalcon's `registerModules()` gives every module the generic
  `/module/:controller/:action/:params` pattern automatically, plus an opt-in
  `registerRoutes(Router $router)` extension point per module class for anything
  non-generic (e.g. a public URL with no controller/action shape).
- **Event bus**: built — shared `eventsBus` service (Phalcon `EventsManager`),
  colon-namespaced (`payment:completed`, `user:created`), modules attach listeners in
  their own `Module::registerServices($di)`. Resolves the event-bus half of open
  question 2.
- **Menu**: `ModuleManager::mergedMenu($surface)` **merges** the base menu with every
  *enabled* module's declared menu file into one combined, always-visible sidebar.
  **This is not the same thing as open question 3** (nav *switching* when a user selects
  one module — a full reload with a rebuilt sidebar, or something more dynamic). v1 only
  merges; it doesn't switch. Still open.
- **Dashboard**: `IndexController::indexAction()` already builds a **single per-user
  dashboard** with each enabled/permitted module's menu entry rendered as a tile —
  confirming the user's Session 10 clarification (one dashboard per user, modules
  widgetized on it) was already how this got built, not the "one dashboard per module"
  reading. The "Per-module structure" section below, which still says "every module
  should have a dashboard view," is the stale part — superseded by this.
- **Guest-visible module content**: not built. `index/guest.phtml` today is a static
  splash (logo, welcome copy, login/signup links) with no module surface at all — the
  Session 10 ask (e.g. guest-visible LMS courses) needs its own pass whenever the LMS
  module is scoped.
- **Blank starter module**: still not built, correctly per this brief's own sequencing
  ("after the base structure is proven with at least one real module") — v1's throwaway
  test package during development already satisfies that gate, so this is unblocked
  whenever it's wanted.

## Why this exists

App Skeleton is the reusable base for three monetization plans: an LMS, a SaaS CRM, and
the skeleton itself sold as a service (full-service bespoke builds, pay-per-functionality,
and DIY/community). The module system is the literal product catalog for the
pay-per-functionality tier — modules need to be cleanly sellable, independently licensable
units, not just internally reusable code. See the licensing note at the bottom: modules are
expected to ship under a **different license** than the MIT-licensed base engine.

## Two module tiers (agreed, not yet built)

1. **Application-defining modules** — big, define what an app_skeleton instance *is*.
   Only one of these usually anchors a given deployment, though the stated requirement is
   that **one or more can be installed on the same instance without cross-contamination or
   conflict**. Planned: SSO, LMS, CRM, DIR (directory — individual + group entities), PGW
   (payment gateway, both to sell access and for internal use), ACC (accounting), LICE
   (licensing/DRM enforcement — see below), SOC (social/networking).
2. **Plugin modules** — small, portable, usable across *any* application module. Mostly
   CRUD + scaffold. Planned: various payment processors, OpenMaps API (global
   addresses/mapping), abr.gov.au API (Australian business lookup), Groups (organisation-style
   grouping — a supervisor/creator manages subordinate users, invite-by-email), Messaging, an
   AI chat plugin (users chat with Claude via their own or a bundled subscription).

**Isolation constraint** (explicit requirement): everything hangs off `user_id`, and most
modules need to define their own tables if they need storage — no shared/implicit state
between application modules.

**Naming convention** (also already applied to the GitHub repo taxonomy — see
[[reference-app-skeleton-github]]): 3-digit short code for paid/public modules, 4-digit for
private/internal-only. Repos already exist as placeholders: `XTenDeploy/applications` and
`XTenDeploy/plugins`, each just a taxonomy README until real module repos land.

## Per-module structure (agreed shape, not yet implemented)

Each module should define, at minimum:

- **RBAC**: which roles can access it, and at what grain — view/edit/delete, not just
  presence/absence. This is finer-grained than the current `ControllerBase::$allowedRoles`
  (role-list-or-null), which only gates whole controllers.
- **Surface**: frontend, backend, or both.
- **Settings**: its own settings, reachable via a settings icon when the module is
  widgetized on a dashboard, or from a settings menu.
- **Menu**: its own menu (probably a partial), which replaces/extends the active nav when a
  user selects that module — open question below on exactly how nav-switching should work.
- **Routes**: open design question — module-scoped routes (each module defines and registers
  its own) vs. one global route table. Leaning module-scoped since it matches the
  self-contained/no-cross-contamination requirement, but not decided.
- **Dashboard**: ~~every module should have a dashboard view~~ — **superseded, see "What
  v1 actually built" above**: this was a miscommunication clarified in Session 10. It's
  one dashboard per *user*, with enabled modules appearing as tiles on it (already how
  `IndexController` works today), not a dashboard per module.

**Not yet decided, needs a design pass**: a `ModuleManager` that discovers/registers plugin
modules (routes/models/migrations/menu entries) plus a lightweight event bus (e.g.
`payment.completed`, `user.created`) so plugins can react to each other without direct
coupling — modeled on how the existing audit events-listener already works
(`App_skeleton\Audit` as the default models-manager events listener, see
[[project-app-skeleton-architecture]]). This is the actual engineering core of the whole
system and deserves the bulk of the new session's design time.

## Dashboard / landing page

- `BASE_URL/` should be a default landing screen accessible to all roles (logged in or not):
  header, logo, welcome copy, "you have successfully installed [product name]" — similar to
  Phalcon's own default "Congratulations" screen. Login/signup links live here.
- Once modules are installed, they should default to showing as tiles on this screen —
  **with a show/hide toggle** and **RBAC baked into the module definition** so a role
  without access to a module never sees its tile at all (not just greyed out).
- The per-user dashboard should be built with **future customization in mind** — leave
  layout slack for a draggable/widget-based dashboard later rather than a fixed grid, even
  though drag-and-drop widgetization itself is out of scope for the first pass.

## API module

- Ship with the base product (not a bolt-on).
- Auto-generate docs — Swagger/OpenAPI suggested, not confirmed.
- Views for *defining* routes/models should be admin-only; every user still needs to be able
  to *use* the API itself (via their existing API keys — `api_keys` table already exists,
  see [[project-app-skeleton-architecture]]).
- Open question raised but not resolved: would it be worth having devtools auto-generate
  models/scaffolding when a newly defined route doesn't have them yet? Worth scoping as a
  devtools convenience once the ModuleManager exists, not before.

## Ship a blank starter module

New idea (2026-07-27, folded in here): once the base ModuleManager/module structure is
confirmed and stable, ship app_skeleton itself with a **blank/template module** — a minimal
example implementing the full per-module contract (RBAC, settings, menu partial, routes,
dashboard) with no real functionality. Purpose: a concrete, correct starting point for
building new modules internally, and for the DIY/community tier (a "here's how you build a
module" reference alongside documentation). Do this *after* the base structure is proven
with at least one real module, not before — otherwise the template will just encode
guesses.

## LMS (folded into this session, was previously a separate track)

The user's second monetization plan, and now explicitly scoped as the first real
application-defining module to build once the ModuleManager exists. Business context: AU
vocational qualification framework data exists at training.gov.au, and OER course material
exists at opencourselibrary.org — both could be packaged and marketed, potentially via
becoming a Registered Training Organisation (RTO) in Australia. Licensing terms on that
sourced content need checking separately (not a code problem). Market/packaging validation
is being handled by the user via "Claude Cowork," not by this Claude Code session.

Requested functionality, roughly in the shape of the domain model:

- **Curriculum/Qualification** structures matching the vocational-training framework shape
  (suggest looking at how training.gov.au itself models qualifications/units of competency
  as a starting reference for table design, without assuming API/data access is available).
- **Courses**: can be as simple as a title + description + a pile of uploaded PDFs, or as
  rich as a full structured course with any number of units attached.
- **Units**: definable independently of a course, attachable to *any* course, and
  detachable/standalone (a unit isn't owned by one course). Instructors should be able to
  add an existing unit to a new or existing course at any time.
- **Drag-and-drop ordering** of units/materials within a course.
- **Grading**, **quizzes**, **SCORM** support.
- **Certificates** with URL-based validation: each issued certificate gets a generated
  validation URL printed on it; visiting the URL shows a copy of the original certificate
  on success, or a clear not-found/invalid result on failure (e.g. revoked or never issued).
- Suggested (not mandated) to look at industry-standard table/data definitions for LMS
  domains (e.g. SCORM's own data model, common LTI/xAPI shapes) rather than inventing course/
  unit/enrollment schemas from scratch.

## Open questions for the new session to resolve early

1. ~~Module-scoped routes vs. a global route table~~ — **resolved, built**: module-scoped,
   see above.
2. ~~Exact ModuleManager discovery mechanism ... and the event-bus shape~~ — **resolved,
   built**: Composer package discovery + `module.json`; event bus shipped. Still open:
   the Composer-only discovery gap for hand-written, non-packaged modules (see above).
3. **Still open**: how nav/menu actually switches when a user selects a different module —
   v1 only merges all enabled modules' menus into one sidebar; it doesn't implement
   per-module switching (full page reload with a rebuilt sidebar, or something more
   dynamic).
4. ~~Whether application modules can genuinely coexist with zero shared schema~~ —
   **resolved, built**: no, `module_registry` is the thin shared table, exactly as
   predicted.
5. LMS-specific: exact certificate validation URL shape and whether validation pages need to
   be public (no login) — almost certainly yes, since third parties (employers) need to
   check a certificate without an account. **Still open** — LMS moved to its own session.

## Licensing note (ties to the T&Cs draft and the MIT decision)

App Skeleton's base repo is now MIT-licensed (2026-07-27 — see repo root `LICENSE`,
`composer.json`). That's deliberately the *engine*, not the product catalog: paid
application/plugin modules are expected to ship under a separate, proprietary license with
license-key validation and reporting obligations (see the LICE module concept above and the
Terms & Conditions draft prepared alongside this brief). Each module repo
(`XTenDeploy/applications/...`, `XTenDeploy/plugins/...`) can carry its own license
independent of the base — that's the standard "open core" shape and is why splitting them
into separate repos now (already done) matters.
