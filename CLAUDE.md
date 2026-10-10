# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

@AGENTS.md

## What this is

skv3 is a multi-tenant Laravel starter kit (successor of skv2). The design spec is `docs/superpowers/specs/2026-10-10-skv3-design.md`; decisions live in `docs/decisions.md`; milestone plans in `docs/superpowers/plans/`.

## Commands

- `composer check` — Pint (test), Rector (dry run), Larastan level 8, Pest in parallel on SQLite. Must pass before any commit claims success.
- `composer test:pgsql` — the same Pest suite on PostgreSQL (Herd Pro, user `root`, empty password). Must also pass.
- `composer fix` — Rector, then Pint.
- `php artisan test --compact --filter=<name>` — run one test; `php artisan test --compact tests/Arch` — arch rules only.
- `composer dev` — Vite, queue, Pail and the scheduler (Herd serves the site; never run `artisan serve`).

## Conventions

- Multi-file Livewire components only, no ⚡ in paths, no `$this->view()` (enforced by `tests/Arch/LivewireConventionsTest.php`).
- `declare(strict_types=1)` everywhere; Larastan level 8 with no baseline — fix errors, never ignore them.
- No hard-coded kit or site names (`skv`) in app-identity values; derive them from `APP_URL` / the folder name.
- `livewire/flux-pro` must never be added to the committed `composer.json` or `composer.lock`; Pro features are an optional layer.
- UI copy defaults to Dutch (`nl`) with English (`en`); code, comments and docs are English.

## Tenancy and authorization

- Hosts: `{slug}.{apex}` is an organisation, `{apex}` and `admin.{apex}` are central. Group single-host routes with `Route::tenant()` / `Route::central()`; never use `Route::domain()` or a `{tenant}` route parameter.
- Models with `organisation_id` use `App\Tenancy\Concerns\BelongsToOrganisation` and `SoftDeletes` and are listed in `config('tenancy.tenant_models')`.
- Strict mode: reading a tenant model without a bound tenant throws. Bind one with `Tenant::run($organisation, fn () => …)`; read across organisations with `Tenant::bypass(fn () => …)` only in `App\Console`, `App\Setup`, `App\Tenancy`, `App\Actions\Audit`, `Database\Seeders` and central components.
- Permissions: use the `App\Authorization\Permission` enum (`$user->can(Permission::UsersView)`, `->can(Permission::UsersView)` on routes); never a permission string. Switch teams only with `withPermissionsTeam($teamId, fn () => …)`.
- Super admin = `users.is_super_admin`, changed only by `App\Authorization\SetSuperAdmin`. Organisation roles change only through `App\Actions\Users\UpdateUserRoles`.
- Tests: `onTenant($organisation)`, `onApex()`, `onAdminHost()`, `asSuperAdmin()`, `asOrgAdmin($organisation)`, `asMember($organisation)`. `Livewire::test()` skips all middleware, so test host rules with HTTP requests.

## Safety

- Never run `herd sites` or the Herd MCP `get_all_sites`: they print every local site's `.env`, including secrets.
- Never run `migrate:fresh` or `db:wipe` against the development database; tests use their own databases.
- `php artisan tinker`, `migrate` and `db:seed` are not pre-approved; Claude must ask before running them.

## Superpowers

Use brainstorming / writing-plans for new features, systematic-debugging for real bugs, and verification-before-completion before committing. Skip skills for questions, small copy or formatting changes, git and read-only work.
