# skv3 kit rules

- Livewire components are multi-file (`<name>/<name>.php` + `<name>.blade.php`), never single-file, never with ⚡ in the path. Create them with `php artisan make:livewire` (config defaults to MFC without emoji).
- Full-page components live in `resources/views/pages/**` (`pages::` namespace); reusable ones in `resources/views/components/**`.
- Component classes never call `$this->view()`; pass data with `with(): array` or `#[Computed]` properties.
- Every PHP file declares `strict_types=1`. Run `composer fix` after editing PHP.
- Tests are Pest functions (`it(...)`). Feature tests use `RefreshDatabase` via `tests/Pest.php`. Arch rules live in `tests/Arch`.
- Before claiming work is done: `composer check` (SQLite) and `composer test:pgsql` (PostgreSQL) must both pass.
- Never use driver-specific SQL (`ilike`, raw `ALTER TABLE ... CONSTRAINT`); use the query builder (`whereLike(..., caseSensitive: false)`).
- Never call `env()` outside `config/`.

## Tenancy and authorization

- Hosts: `{slug}.{apex}` is an organisation, `{apex}` and `admin.{apex}` are central. Group single-host routes with `Route::tenant()` / `Route::central()`; never use `Route::domain()` or a `{tenant}` route parameter.
- Models with `organisation_id` use `App\Tenancy\Concerns\BelongsToOrganisation` and `SoftDeletes` and are listed in `config('tenancy.tenant_models')`.
- Strict mode: reading a tenant model without a bound tenant throws. Bind one with `Tenant::run($organisation, fn () => …)`; read across organisations with `Tenant::bypass(fn () => …)` only in `App\Console`, `App\Setup`, `App\Tenancy`, `App\Actions\Audit`, `Database\Seeders` and central components. Never remove `TenantScope` with `withoutGlobalScope(s)` / `newQueryWithoutScopes` outside those namespaces; use `Tenant::bypass()`.
- Permissions: use the `App\Authorization\Permission` enum (`$user->can(Permission::UsersView)`, `->can(Permission::UsersView)` on routes); never a permission string. Switch teams only with `withPermissionsTeam($teamId, fn () => …)`.
- Super admin = `users.is_super_admin`, changed only by `App\Authorization\SetSuperAdmin`. Organisation roles change only through `App\Actions\Users\UpdateUserRoles`.
- Tests: `onTenant($organisation)`, `onApex()`, `onAdminHost()`, `asSuperAdmin()`, `asOrgAdmin($organisation)`, `asMember($organisation)`. `Livewire::test()` skips all middleware, so test host rules with HTTP requests. Tests that use `Event::fake()` must keep the tenancy events real (`Event::fake()->except([TenantResolved::class, TenantForgotten::class])`), or the permissions team stays null.
- Sign-in rules live in `App\Tenancy\Auth\HostLoginRule`; never re-implement "may this user sign in here".
- Links that leave the current request (mails, redirects to another host) are built for `Hosts::homeOf($user)`; signed links use `URL::temporarySignedRoute(..., absolute: false)` with `signed:relative`.
- Moving a signed-in user to another host goes through `App\Actions\Auth\IssueHandoff`; sessions are host-only.
- `password.confirm` on a route does not cover Livewire updates: a Livewire action that changes or reveals a second factor starts with `if ($this->passwordConfirmationRequired()) { return; }` (`App\Concerns\ConfirmsPasswords`).
