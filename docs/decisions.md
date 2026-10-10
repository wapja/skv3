# Decisions

Owner decisions from the design spec (`docs/superpowers/specs/2026-10-10-skv3-design.md` §2) and decisions made during the build. Newest last.

## Owner decisions

| ID | Topic | Decision |
|---|---|---|
| DEC-01 | Multi-tenancy | Always on (subdomain per organisation), as in skv2. |
| DEC-02 | Flux Pro | **Optional layer.** The kit works fully on free Flux. Pro features switch on when `livewire/flux-pro` is installed. The owner's licence tier is unconfirmed, so Pro is not a hard dependency (§6.7). |
| DEC-03 | Database | PostgreSQL by default (Herd Pro). SQLite is a fully supported fallback when no Postgres is available. Both run in CI. |
| DEC-04 | Language | UI defaults to `nl`, with complete `en`. Code, comments, README, spec and agent guidelines are in English. The end-user manual is in Dutch under `docs/`. |
| DEC-05 | Livewire component format | Multi-file components (skv2 D34), no ⚡ emoji. Component PHP is analysed by Larastan and Rector (§7.1). |
| DEC-06 | Strictness | Larastan level 8, no baseline (aim for max in v1.x). Pint = laravel preset + `declare_strict_types`, `strict_comparison`, `ordered_class_elements`. No blanket `final` rule; no Pest `strict()` preset. |
| DEC-07 | Debug tooling | Debugbar + Pail + Herd Dumps/Log Viewer. No Telescope. |
| DEC-08 | Optional modules | Passkeys, backups, impersonation are runtime modules (§6.8). Demo data is a setup choice. Everything else is core. |
| DEC-09 | Distribution | Public GitHub + Packagist. Entry points: Herd wizard / deep link, `laravel new --using`, `composer create-project`, `git clone` + `composer setup`. |
| DEC-10 | Setup approach | Browser setup wizard + CLI counterpart, both driven by one set of idempotent setup steps (§5). |
| DEC-11 | v1 scope extras | Sessions list + "log out other devices", "second factor required" per organisation, audit log CSV export, bulk invitations, avatar upload — all in v1 (milestone M7, §6.6). |
| DEC-12 | Appearance | Light / dark / system, default system. Persisted on the user so it is the same on every subdomain (§6.5). |
| DEC-13 | Organisation membership | One organisation per user; email globally unique among non-deleted users. |
| DEC-14 | Audit retention | `ACTIVITYLOG_RETENTION_DAYS` (default 365), clean command scheduled (§4.9). |
| DEC-15 | PHP floor | `^8.4` (Pest 5 / PHPUnit 13 / activitylog 5.1). CI also runs PHP 8.5. |
| DEC-16 | AI files | Commit a hand-written `CLAUDE.md`, `boost.json`, `.claude/settings.json`, `.ai/**`. Gitignore `AGENTS.md` (Boost-generated), `.mcp.json`, `.claude/settings.local.json`. |
| DEC-17 | Starting point | Scaffold from the latest official Laravel Livewire starter kit, then reshape (as skv2 D3). |
| DEC-18 | Release channel | `main` is the release channel: the installer always installs the default branch (§5.2). `main` is protected; work happens on feature branches; semver tags are added for `composer create-project` users. |

## Spike outcomes

### DEC-19 — Passkeys package pairing (spike S5)

**Question:** Which `laravel/passkeys` (PHP) / `@laravel/passkeys` (npm) pair do we ship?
**Answer:** The official Livewire starter kit's pairing: PHP `laravel/passkeys v0.2.1` (via `laravel/fortify v1.41.0`, which requires `laravel/passkeys ^0.2.0`), npm `@laravel/passkeys 0.2.0` with constraint `^0.2.0`.
**Evidence:** `composer show laravel/passkeys` (`versions : * v0.2.1`), `composer show laravel/fortify` (`versions : * v1.41.0`), `composer why laravel/passkeys` (`laravel/fortify v1.41.0 requires laravel/passkeys (^0.2.0)`), `node_modules/@laravel/passkeys/package.json` (version `0.2.0`) and `package.json` (`"@laravel/passkeys": "^0.2.0"`) of a fresh `laravel new --livewire` on 2026-10-10. The npm registry already lists `0.4.0`, which the official kit's constraint does not allow.
**Consequence:** Do not bump `@laravel/passkeys` beyond the official kit's constraint until the official kit does.

### DEC-20 — Pro-only Flux components (spike S4)

**Question:** Which Flux components exist only in `livewire/flux-pro`?
**Answer:** Top-level Pro-only components (22): `accordion`, `autocomplete`, `calendar`, `carousel`, `chart`, `color-picker`, `command`, `composer`, `context`, `date-picker`, `editor`, `file-item`, `file-upload`, `kanban`, `phone`, `pillbox`, `popover`, `slider`, `tab` (with `tab.group`, `tab.panel`), `tabs`, `time-picker`, `timeline`. Pro-only files inside the shared `select` folder (17): the `combobox`, `custom` and `listbox` variants (`select/variants/combobox`, `select/variants/custom`, `select/variants/listbox`) plus their parts: `select/button`, `select/empty`, `select/group/variants/custom`, `select/indicator/index`, `select/indicator/variants/check`, `select/indicator/variants/checkbox`, `select/indicator/variants/radio`, `select/input`, `select/option/create`, `select/option/empty`, `select/option/variants/custom`, `select/options`, `select/search`, `select/selected`. The free `select` only has the `default` variant (native select). No other shared folder contains Pro-only files; every other Pro file lives in one of the Pro-only top-level folders above.
**Evidence:** `comm` of `flux` v2.20.1 vs `flux-pro` 2.20.1 stub folders (`vendor/livewire/{flux,flux-pro}/stubs/resources/views/flux`) in the official Livewire starter kit scaffold, 2026-10-10.
**Consequence:** These names may only be used inside `@if (Skv::hasFluxPro())` branches (spec §6.7). The lint test in M5 uses the free stub folder as its allow-list, so this list is informational.

Appearance persistence: confirmed `flux.appearance` key in `vendor/livewire/flux/src/AssetManager.php`.

### DEC-21 — Organisation admin role model (spike S2)

**Question:** Can `organisation_admin` be one global template role (`team_id = NULL`) assigned per team, with pivot primary keys that exclude `team_id` (skv2 D28), on PostgreSQL?
**Answer:** Option A (one global template role) — all three `TemplateRoleTest` tests passed on PostgreSQL: one `organisation_admin` row is assigned in team 1 and team 2 independently, a role can be attached with a NULL team, and a permission added to the template is effective in every team.
**Evidence:** `TemplateRoleTest` on PostgreSQL 18.0 (Herd), spatie/laravel-permission 8.3.0 with `teams => true` and `model_has_roles` / `model_has_permissions` primary keys of (role or permission id, model id, model type) and a nullable `team_id`: all 3 tests passed (9 assertions): assigns one global template role per team; lets a global role be attached with a null team; propagates template permission changes to every team.
**Consequence:** Spec §6.3 option A applies to `UpdateUserRoles`, `permissions:sync`, the doctor check and the roles index (M2).
