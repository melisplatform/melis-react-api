---
title: MelisReactApi module — React back-office
package: melisplatform/melis-react-api
doc_type: module-documentation-react
audience: [users, developers, ai]
language: en
module_version: unversioned
last_reviewed: 2026-08-20
maintainer: Melis Technology
keywords: [react, brick, back-office, react-api, json-api, infrastructure, menu, capabilities, react-modules, discovery, dashboard, authentication]
screenshots_dir: ./images/react
---

# MelisReactApi (React back-office) — Functional & Technical Documentation (for AI)

> **What this is.** MelisReactApi is the **JSON API backbone** of the new React
> back-office (`/melis-react`). It is **infrastructure, not a tool**: it draws **no UI**,
> ships **no brick**, and adds **no sidebar entry**. It exposes the generic
> `/melis/react-api/…` endpoints the React shell calls to bootstrap itself (current user,
> navigation menu, platform assets, brick discovery, dashboard data, back-office
> languages), and it hosts the **capability resolver** (`Capabilities` +
> `CapabilityGuardTrait`) that per-module tool controllers reuse to gate their "advanced
> rights". Individual tools' own endpoints/routes/capabilities live in **their own**
> modules (e.g. `/users`, `/roles` are declared in MelisCore / MelisSmallBusiness), not
> here. There are **no React screens and no screenshots** for this module — the sections
> below describe the API contract an AI must use when talking to the React BO.
>
> **How this document is organised — two clearly separated parts:**
> - **[Part A — Functional Guide](#part-a--functional-guide)** — a plain-language framing:
>   what this layer does and where its data shows up in the React back-office.
> - **[Part B — Technical Reference](#part-b--technical-reference)** — for developers and
>   AI building inside the React UI, with the real endpoints, the `{ success, data, error }`
>   contract, auth, the capability resolver and the discovery/menu builders.
>
> **Audience**: consumed by the **MelisAI** MCP. **Status**: reviewed 2026-08-20.

---

## 0. Where this lives in the React back-office — read this first

**Brick kind: none — this is an *infrastructure* module.** MelisReactApi has:

- **no** `public/ui-react/brick.manifest.json` (so it never appears in
  `GET /melis/react-api/react-modules` and never surfaces as a brick),
- **no** `ui-react/` source (no Vite project, no React components),
- **no** `config/react-api.php` of its own — it declares its generic routes directly in
  `config/module.config.php`,
- **no** `config/react.capabilities.php` — it is the **engine** that reads other modules'
  capability declarations, it declares none itself.

Because it renders nothing, **there is nothing to navigate to**. Instead, the React shell
(served by MelisReactOverride at `/melis-react`, source in `melis-core/ui-react/`) calls
this module's endpoints at boot and on navigation. Its data appears *inside the shell*:

- the **left menu** (`GET /menu`), the **header**'s user/avatar and language switcher
  (`GET /me`, `GET /langs`), the **dashboard** tiles (`/dashboard/*`), the **modular tool
  discovery** that loads every active module's brick (`GET /react-modules` +
  `/bricks-bundle.js`), and the **Users → Rights** editor's advanced-rights matrix
  (`GET /rights/capabilities`, `GET /rights/dashboard-plugins`, `GET /menu?full=1`).

**Loading.** MelisReactApi is **not** composer-installed in the Docker vendor volume; it is
loaded via `config/application.config.php` `module_paths` (branch `melis-react`), and
`src/Module.php` autoloads its classes via `StandardAutoloader`. It pairs with
**MelisReactOverride** (the iframe/tool mechanism + SPA shell route) and with
**melis-core/ui-react** (the React app that consumes this API — see its `src/lib/*-api.ts`
clients).

There is no legacy doc for this module (it is React-only infrastructure) and no coupled
`-react.md` sibling to cross-link yet.

---
---

# PART A — Functional Guide

## A1. What this layer does for you in the new back-office

You never click MelisReactApi directly — but everything you see in `/melis-react` is
assembled from its answers:

- **Who you are** — your name, avatar, e-mail and admin flag in the top-right, plus the
  set of tool "capabilities" you are allowed to use.
- **The left menu** — the whole navigation tree, already **filtered by your rights**, with
  each entry knowing which tool it opens.
- **The language switcher** — the list of back-office languages and the one you're in.
- **The dashboard** — the top notification/message bubbles, the KPI counters
  (users / sites / pages / languages), the recent-login activity, and your saved tile
  layout.
- **The modular tools** — the shell discovers *which modules are active* and loads their
  React bricks, so a tool appears **if and only if** its module is enabled.
- **Advanced rights** — in **Users → Rights**, the fine-grained "List / Create / Edit /
  Delete / tab" checkboxes per tool are driven by this layer's capability engine.

> Rule of thumb: if the React shell shows it (menu, header, dashboard, tool list, rights
> matrix) but it isn't a specific tool's own screen, it came from a MelisReactApi endpoint.

## A2. Finding it in /melis-react

It has **no screen and no menu entry** — it is the plumbing behind the shell. There are no
screenshots for this module.

## A3. Key words explained

- **Endpoint** — one JSON URL under `/melis/react-api/…` the shell calls (e.g.
  `/melis/react-api/menu`). Every response is `{ success, data, error? }`.
- **Brick** — a module's runtime-loaded React UI. This layer only *discovers and serves*
  bricks (`/react-modules`, `/bricks-bundle.js`); it is not a brick itself.
- **melisKey** — the identifier of a tool node in Melis' rights tree; used both to gate menu
  entries and to key a tool's capabilities.
- **Capability** — a fine-grained "advanced right" inside an already-authorised tool
  (`list`, `create`, `edit`, `delete`, or a nested tab like `variants.list`). Default is
  **allow**; a user/role can be *denied* specific capabilities.

## A4. Common tasks — "How do I…?"

- **…know what the shell fetched at boot?** It calls `GET /me`, `GET /menu`, `GET /langs`,
  `GET /assets`, `GET /react-modules` (+ `/bricks-bundle.js`) — see §B3.
- **…find a specific tool's data endpoint?** It's **not here** — look in that tool's own
  module (`<module>/config/react-api.php`). This module only serves the generic set.
- **…understand why a tool's action is forbidden (403)?** The tool's controller called the
  capability guard (`denyUnlessCan`) and this layer resolved a *deny* for that capability —
  see §B4.

---
---

# PART B — Technical Reference

## B1. React presence at a glance

| Property | Value |
|---|---|
| Brick id / manifest | **none** — ships no `public/ui-react/brick.manifest.json` |
| `ui-react/` source | **none** — no Vite project, no React components |
| `config/react-api.php` | **none** — routes declared directly in `config/module.config.php` |
| `config/react.capabilities.php` | **none** — it *reads* others' declarations, declares none |
| Controller | `MelisReactApi\Controller\MelisReactApiController` (invokable alias `MelisReactApi\Controller\MelisReactApi`) |
| Base route | `melis-backoffice` → `react-api` (i.e. `/melis/react-api/…`) |
| Auth | `isAuthenticated()` (MelisCoreAuth) per action, **except** `/langs` and `/assets` errors return JSON, `/langs` is public |
| Response contract | `{ success: bool, data: T, error?: string }` (JSON, no layout) |
| Brick kind | **infrastructure — none** |
| Activation-gated | n/a (always loaded as a core module) |

## B2. The module — anatomy

```
melis-react-api/
├─ config/module.config.php          # declares the /melis/react-api/* routes + the invokable
└─ src/
   ├─ Module.php                     # getConfig() includes module.config.php; StandardAutoloader
   ├─ Controller/
   │  ├─ MelisReactApiController.php  # the 12 generic actions (extends MelisAbstractActionController)
   │  └─ CapabilityGuardTrait.php     # denyUnlessCan($cap) — reused by per-module tool controllers
   └─ Service/
      └─ Capabilities.php             # the capability resolver (declare/flatten/deny/isAllowed)
```

`MelisReactApiController` extends `MelisCore\Controller\MelisAbstractActionController`; each
action returns a raw JSON `Response` (via the inherited `jsonResponse()`), so Laminas never
wraps a layout around it. Read-only actions call `releaseSessionLock()` early so boot
requests sharing the session cookie don't serialise.

## B3. React API — generic endpoints (served HERE)

All under `/melis/react-api/…`. Twelve generic endpoints, all mapping to
`MelisReactApiController`. Every one returns `{ success, data, error? }` (401 when the
`isAuthenticated()` guard fails, except `/langs`).

| Method + path | Action | `data` shape (verified) |
|---|---|---|
| `GET /me` | `meAction` | `{ id, name, login, email, picture, isAdmin, capabilities }` — `capabilities` = map `melisKey → string[]` of allowed caps (admin ⇒ everything). |
| `GET /menu` | `menuAction` | `NavNode[]` — the rights-filtered nav tree (see §B5). `?full=1` returns the **unfiltered** tree (rights editor only; requires `canAccess('meliscore_tool_user')`). |
| `GET /langs` | `langsAction` | `{ current: { id, locale }, langs: [{ id, locale, short, label }] }` — **public** (loaded on the login screen before auth). |
| `GET /assets` | `assetsAction` | CSS/JS URLs + inline JS globals for bootstrapping tool iframes — delegates to `MelisReactOverride\Service\PlatformAssetsService::build()`. |
| `GET /react-modules` | `reactModulesAction` | `{ data: BrickDef[], bundle: { url } }` — the active modules' bricks (discovery); `bundle.url` = the concatenated JS (below). |
| `GET /bricks-bundle.js` | `bricksBundleAction` | **not JSON** — every active brick's IIFE concatenated into one immutable-cached JavaScript response. |
| `GET /dashboard/bubbles` | `dashboardBubblesAction` | `{ news, updates, notifications:{count,items}, messages }` counts (degrade to 0, never 404). |
| `GET /dashboard/stats` | `dashboardStatsAction` | `{ kpis:{ users, sites, pages, languages }, activity:[{ id, name, loginDate }] }`. |
| `GET /dashboard/legacy-plugins` | `legacyDashboardPluginsAction` | `[{ pluginName, title, icon, section, w, h }]` — legacy dashboard plugins as iframe widgets. |
| `GET /rights/dashboard-plugins` | `rightsDashboardPluginsAction` | `[{ key, title, module }]` — authoritative dashboard-plugin list for the rights editor (`?userId=`). |
| `GET /rights/capabilities` | `rightsCapabilitiesAction` | map `melisKey → capability tree` **declared** by modules, with `tr_*` labels translated to the session locale (feeds the rights editor). |
| `GET \| POST /dashboard/layout` | `dashboardLayoutAction` | GET → saved tiles `[{ pluginName, pluginId, x, y, w, h }]`; POST → same JSON, saved back to `melis_core_dashboards` (same record the classic dashboard uses). |

> **In short:** `/me`, `/menu`, `/langs`, `/assets`, `/react-modules` (+ `/bricks-bundle.js`)
> are the **boot set**; the `/dashboard/*` and `/rights/*` endpoints back the dashboard and
> the Users→Rights editor.

### What is NOT here (delegated to modules)

A tool's own data endpoints live in **that tool's module**, not here. Notably
`/melis/react-api/users…`, `/melis/react-api/users/stats`, `/melis/react-api/roles` are
declared in **MelisCore**'s `config/react-api.php`; `/melis/react-api/roles-list`
(`/user-roles`), `/melis/react-api/roles/save|stats|:id`, `/melis/react-api/workflow/roles`
in **MelisSmallBusiness**. This module hosts **no AI routes** — those live in MelisAI's own
`config/react-api.php`. The `melisplatform/melis-core` clients that call the generic set are
`melis-core/ui-react/src/lib/melis-api.ts` (me / menu / assets / react-modules / langs /
dashboard) — cross-reference it for the exact TS request/response types.

### Real fetch example — the shell's `/me` boot call

```ts
// mirrors melis-core/ui-react/src/lib/melis-api.ts
const res = await fetch('/melis/react-api/me', {
  headers: { 'X-Requested-With': 'XMLHttpRequest' },
  credentials: 'include',
})
const json = await res.json()          // { success, data, error? }
if (!json.success) throw new Error(json.error)   // 401 → { success:false, error:'Unauthenticated' }
const { name, isAdmin, capabilities } = json.data
// capabilities: Record<melisKey, string[]>  — e.g. { melis_core_announcement_tool: ['list','create','edit'] }
```

## B4. Capabilities — the resolver (the reusable engine)

This is the substance of what MelisReactApi provides to *other* modules. It is the
"advanced rights" system that gates the **internal components** of an already-authorised
tool (list / create / edit / delete / nested tabs), *subordinate to* the tool-access check
(`MelisCoreRights::canAccess`, unchanged).

**Declaration (in each module, not here).** A module declares, per tool `melisKey`, the
capabilities that EXIST, via the merged config key `melisReactToolCapabilities`
(`config/react.capabilities.php` in the module, merged in its `Module::getConfig()`):

```php
// <module>/config/react.capabilities.php  (example — declared BY the tool's module)
return [
  'melisReactToolCapabilities' => [
    'melis_core_announcement_tool' => ['list', 'create', 'edit', 'delete'],
    // or a TREE for nested tabs with their own actions:
    // 'some_tool' => ['actions' => ['list'], 'tabs' => [['key' => 'variants', 'actions' => ['list','create']]]],
  ],
];
```

**Resolver — `MelisReactApi\Service\Capabilities`** (all static; `const CONFIG_KEY =
'melisReactToolCapabilities'`, `const SECTION = 'meliscore_tool_capabilities'`):

- `declared($appConfig)` → the raw declared map (the rights editor renders it, labels and
  all).
- `flatten($node)` → flattens a flat list OR a `{ actions, tabs }` tree into dotted
  capability strings (e.g. `variants.list`; the tab key itself is also a cap).
- `deniedFor($rightsXml, $toolKey)` → the deny-list read from the user/role rights XML.
- `isAllowed($appConfig, $rightsXml, $toolKey, $cap)` → **default-allow**: allowed unless
  the cap is both declared for the tool *and* present in the deny-list.
- `allowedForUser($appConfig, $rightsXml)` → the `melisKey → allowedCaps[]` map returned in
  `/me` (`$rightsXml = null`, e.g. admin, ⇒ everything).

**Storage.** Denials live in a **dedicated section** of the user/role rights XML, separate
from the legacy deny-list so the classic BO ignores it:

```xml
<meliscore_tool_capabilities>
  <tool key="melis_core_announcement_tool"><deny>delete</deny></tool>
</meliscore_tool_capabilities>
```

Preserving this section on a **legacy** save is done by the concerned modules
(MelisCore for USER, MelisSmallBusiness for ROLE) — see the note in `src/Module.php`.

**Guard — `MelisReactApi\Controller\CapabilityGuardTrait`.** A per-module tool controller
`use`s this trait, defines `const MELIS_KEY = '<the tool's melisKey>'`, and calls
`denyUnlessCan($cap)` **after** its tool-access check:

```php
use MelisReactApi\Controller\CapabilityGuardTrait;

class MelisReactApiFooController extends MelisAbstractActionController
{
    use CapabilityGuardTrait;
    const MELIS_KEY = 'melis_core_announcement_tool'; // the rights-bearing tool node

    public function saveAction()
    {
        if ($resp = $this->denyUnlessCan('edit')) return $resp;   // 403 JSON if denied
        // …call the module's Laminas service…
    }
}
```

`denyUnlessCan` reads the **effective** rights (`MelisCoreAuth::getAuthRights()` → user OR
role), **admins bypass**, and it is **default-allow** — a tool with no declaration keeps
full CRUD. On the client side, the same allow-map arrives in `/me` `data.capabilities` and
gates the UI (see `melis-core/ui-react/src/lib/caps.ts`).

## B5. Host integration

- **Discovery / bricks:** `GET /react-modules` scans **active** modules for
  `public/ui-react/brick.manifest.json` (a single object or a `bricks: [...]` array) and
  returns `BrickDef` = `{ id, module, route, label, forwardKey, melisKey, subTabs,
  persistent, bundleUrl }`, plus `bundle.url = /melis/react-api/bricks-bundle.js?v=<sig>`.
  The shell loads the **single concatenated bundle** (each brick is an IIFE self-registering
  by id on `window.__MELIS_BRICK_COMPONENTS__` via `window.__melisRegisterBrick`, wrapped in
  try/catch) instead of N per-brick URLs. The `?v=` signature (name+mtime+size of every
  bundle) makes a 1-year immutable cache safe. A brick exists **iff** its module is active —
  that is the modularity rule. Consumed by `melis-core/ui-react/src/lib/bricks.ts`.
- **Menu builder (`GET /menu`):** walks the leftmenu interface, applies section/tool
  ordering, and emits a `NavNode[]` where each node is
  `{ key, name, icon, melisKey, isTool, forward, hasNavChild, configChildCount, children }`.
  Rights: a top **section** is a container (never `canAccess`-gated — pruned only if empty);
  a directly-clickable `is_parent_tool` section **is** gated by `canAccess(target)`;
  unknown (newly-installed) module sections use permissive rights so they stay visible.
  Two module hooks extend it: `melisReactSidebarHostSections` (keep an empty section as a
  bare sidebar-host container if `canAccess(requires)`, carrying `sidebarModule`) and
  `melisReactRightsTools` (inject rights-only synthetic tool nodes, `?full=1` only). The
  React shell maps each brick's `forwardKey → route` so a dynamic menu entry routes to the
  React page.
- **Auth / assets bridge:** `/assets` returns the same CSS/JS the legacy `layoutCore.phtml`
  loads (delegated to `MelisReactOverride\Service\PlatformAssetsService`), so tool iframes
  bootstrap identically inside the React shell.
- **i18n:** `tr_*` labels (menu section names, capability tab labels) are translated to the
  current session locale (`meliscore` session container `melis-lang-locale`) via
  `MelisCoreTranslation`; modules declare **keys**, never hard-coded text.

> **In short:** MelisReactApi is the **generic bridge** — auth/menu/assets/discovery/dashboard
> endpoints + the capability engine. Everything domain-specific (a tool's own endpoints,
> routes and capability declarations) lives in that tool's own module.

## B6. Quick code map

```
melis-react-api/
├─ config/module.config.php                 # routes: assets, me, menu, langs, bricks-bundle.js,
│                                            #         react-modules, dashboard/{bubbles,stats,
│                                            #         legacy-plugins,layout}, rights/{dashboard-plugins,
│                                            #         capabilities}; invokable MelisReactApi\Controller\MelisReactApi
└─ src/
   ├─ Module.php                             # getConfig(); StandardAutoloader; capability-preserve note
   ├─ Controller/
   │  ├─ MelisReactApiController.php         # the 12 generic actions (JSON, {success,data,error})
   │  └─ CapabilityGuardTrait.php            # denyUnlessCan($cap) — reused by tool controllers
   └─ Service/
      └─ Capabilities.php                    # declared/flatten/deniedFor/isAllowed/allowedForUser
```

No `ui-react/`, no `public/ui-react/`, no `config/react-api.php`, no
`config/react.capabilities.php` — by design (infrastructure, not a tool).

---

*Document for AI consumption (MelisAI MCP) — React back-office infrastructure module
`melisplatform/melis-react-api`. Part A = functional framing; Part B = technical reference
with examples for developers/AI. This module ships no brick and no screenshots. Last
reviewed 2026-08-20.*
