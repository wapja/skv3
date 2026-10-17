---
name: add-tenant-resource
description: "Use when adding a model, table or resource that belongs to one organisation (it has an organisation_id): the migration, the model with BelongsToOrganisation and SoftDeletes, the tenant_models entry, the factory, the archive cascade and the purge, the policy, the pages that read it and its tests. Covers tenant-scoped CRUD on tenant hosts and any-host pages that read tenant rows."
---

# Add a tenant resource

A tenant resource is a table with an `organisation_id` whose rows only the organisation's own host may see. The rules
are in `AGENTS.md` › *Tenancy and authorization*; this skill is the checklist that applies them. Read those rules
first and follow them where this list is shorter.

## 1. Migration

- Create it with `php artisan make:migration --no-interaction`.
- `$table->foreignId('organisation_id')->constrained()->restrictOnDelete();` plus `$table->softDeletes();`. Restrict, not
  cascade: a purge deletes the rows itself (step 4), and the foreign key is the safety net.
- Index the columns the pages filter on, starting with `organisation_id`. No driver-specific SQL (`AGENTS.md` › kit rules).
- Never run `migrate`, `migrate:fresh` or `db:wipe` against the development database: tests use their own databases
  through `RefreshDatabase`.

## 2. Model

- `use BelongsToOrganisation, HasFactory, SoftDeletes;` (`App\Tenancy\Concerns\BelongsToOrganisation`). The trait adds
  the tenant scope, fills `organisation_id` from the bound tenant and refuses a row without one. Copy the shape of
  `app/Models/Invitation.php` (attributes, `@property` docblock, relations).
- Never make `organisation_id` fillable (`tests/Arch/TenancyRulesTest.php`).
- Add the class to `tenant_models` in `config/tenancy.php`. That list drives the archive cascade
  (`App\Tenancy\SoftDeleteCascade`), and `tests/Feature/Tenancy/TenantModelsTest.php` fails for a model with an
  `organisation_id` that is missing from it.

## 3. Factory

- `php artisan make:factory --no-interaction`. `'organisation_id' => Organisation::factory()`, and build related tenant
  rows in the same organisation (see `database/factories/InvitationFactory.php`).

## 4. Archive, restore and purge

- Archive and restore need nothing more: `SoftDeleteCascade` reads `tenant_models`.
- Purge does: add one query-builder delete with an explicit `where('organisation_id', …)` to
  `App\Actions\Organisations\PurgeOrganisation`, before the users are deleted, in the lock order of `AGENTS.md`
  (*a transaction locks rows in one order*). Never Eloquent's `forceDelete()` on a builder: it runs without the
  global scopes.

## 5. Authorization

- A permission per ability: see the `add-permission` skill. Never a permission string.
- Policy methods check the permission and the organisation: `$actor->can(Permission::…) && $actor->organisation_id ===
  $model->organisation_id` (see `app/Policies/InvitationPolicy.php`). A method named like an always-checked ability
  grants super admins itself (`App\Authorization\SuperAdminGate::ALWAYS_CHECKED`).
- Administrative actions take the acting user first and call `Gate::forUser($actor)->authorize(…)`.

## 6. Pages

- Administration pages go into `routes/admin.php`, inside its `auth` + `EnsureSecondFactor` group, with
  `->can(Permission::…)`. A tenant-host-only page sits in `Route::tenant()`, where the tenant scope does the
  filtering; an any-host page reads through `App\Tenancy\HostScope::read()`. Never `Tenant::bypass()` or
  `withoutGlobalScope()` in a Livewire page.
- Livewire components authorise in `mount()` and again before each action, keep `#[Locked]` ids, never an Eloquent
  model property, and re-resolve the model on every request.
- A sidebar entry goes into `App\Support\Navigation\Navigation::items()`. Every new named GET route goes into
  `matrixRoutes()` in `tests/Feature/Tenancy/RouteHostMatrixTest.php`, with its outcome in `routeMatrixOutcome()`.
- Audit writes go through `App\Actions\Audit\RecordActivity` with a new `App\Enums\ActivityEvent` case and its label.
- New copy goes into both `lang/nl.json` and `lang/en.json`.

## 7. Tests

Use the `write-tenant-test` skill. At least:
- the scope: a row of another organisation is invisible on this host and visible inside `Tenant::bypass()` (model
  `tests/Feature/Tenancy/TenantScopeTest.php`);
- the cascade: archive soft-deletes the rows with the organisation's timestamp, restore brings back only those
  (`tests/Feature/Organisations/OrganisationCascadeTest.php`);
- the purge deletes them (`tests/Feature/Organisations/ArchiveRestorePurgeTest.php`);
- the policy for each role, and the pages over HTTP on the tenant host and on a central host.

Then `composer fix`, `composer check`, `composer test:pgsql` and `composer test:browser`.
