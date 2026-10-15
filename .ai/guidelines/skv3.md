# skv3 kit rules

- Livewire components are multi-file (`<name>/<name>.php` + `<name>.blade.php`), never single-file, never with ⚡ in the path. Create them with `php artisan make:livewire` (config defaults to MFC without emoji).
- Full-page components live in `resources/views/pages/**` (`pages::` namespace); reusable ones in `resources/views/components/**`.
- Component classes never call `$this->view()`; pass data with `with(): array` or `#[Computed]` properties.
- Every PHP file declares `strict_types=1`. Run `composer fix` after editing PHP.
- Tests are Pest functions (`it(...)`). Feature tests use `RefreshDatabase` via `tests/Pest.php`. Arch rules live in `tests/Arch`.
- Test files (`*Test.php`) never declare classes, anonymous ones included, and never spell a qualified class name outside their imports: fixtures go in `tests/Fixtures`, because the installer's Pest Drift step (DEC-22) rewrites every class in a `*Test.php` and imports every qualified name (`tests/Arch/TestFileClassesTest.php`).
- Before claiming work is done: `composer check` (SQLite), `composer test:pgsql` (PostgreSQL) and `composer test:browser` (Chromium) must all pass.
- Never use driver-specific SQL (`ilike`, raw `ALTER TABLE ... CONSTRAINT`); use the query builder (`whereLike(..., caseSensitive: false)`).
- Never call `env()` outside `config/`.

## Tenancy and authorization

- Hosts: `{slug}.{apex}` is an organisation, `{apex}` and `admin.{apex}` are central. Group single-host routes with `Route::tenant()` / `Route::central()`; never use `Route::domain()` or a `{tenant}` route parameter.
- Models with `organisation_id` use `App\Tenancy\Concerns\BelongsToOrganisation` and `SoftDeletes` and are listed in `config('tenancy.tenant_models')`.
- Strict mode: reading a tenant model without a bound tenant throws. Bind one with `Tenant::run($organisation, fn () => …)`; read across organisations with `Tenant::bypass(fn () => …)` only in `App\Console`, `App\Setup`, `App\Tenancy`, `App\Actions\Audit` and `Database\Seeders`; Livewire pages are never added to that list. Never remove `TenantScope` with `withoutGlobalScope(s)` / `newQueryWithoutScopes` outside those namespaces; use `Tenant::bypass()`.
- Permissions: use the `App\Authorization\Permission` enum (`$user->can(Permission::UsersView)`, `->can(Permission::UsersView)` on routes); never a permission string. Switch teams only with `withPermissionsTeam($teamId, fn () => …)`.
- Super admin = `users.is_super_admin`, changed only by `App\Authorization\SetSuperAdmin` (pages call `App\Actions\Users\UpdateSuperAdmin`, which authorises first). Organisation roles change only through `App\Actions\Users\UpdateUserRoles`; custom roles only through `App\Actions\Roles\{CreateRole,UpdateRole,DeleteRole}`.
- Tests: `onTenant($organisation)`, `onApex()`, `onAdminHost()`, `asSuperAdmin()`, `asOrgAdmin($organisation)`, `asMember($organisation)`. `Livewire::test()` skips all middleware, so test host rules with HTTP requests. Tests that use `Event::fake()` must keep the tenancy events real (`Event::fake()->except([TenantResolved::class, TenantForgotten::class])`), or the permissions team stays null.
- Sign-in rules live in `App\Tenancy\Auth\HostLoginRule`; never re-implement "may this user sign in here".
- Links that leave the current request (mails, redirects to another host) are built for `Hosts::homeOf($user)`; signed links use `URL::temporarySignedRoute(..., absolute: false)` with `signed:relative`.
- Moving a signed-in user to another host goes through `App\Actions\Auth\IssueHandoff`; sessions are host-only.
- `password.confirm` on a route does not cover Livewire updates: a Livewire action that changes or reveals a second factor, purges an organisation, switches super admin or changes another user's address starts with `if ($this->passwordConfirmationRequired($returnUrl)) { return; }` (`App\Concerns\ConfirmsPasswords`; build `$returnUrl` with `route(..., absolute: false)` on the server, never from the Referer; it defaults to the security page).
- Any-host pages read tenant models only through `App\Tenancy\HostScope::read(fn () => …)`. `HostScope` scopes tenant models only: filter central tables (organisations, Spatie roles and permissions, `model_has_*`, `sessions`) with `HostScope::organisationId()` and ignore organisation ids sent by the client on a tenant host.
- Administrative actions (users, invitations, organisations, roles, impersonation start) take the acting user as their first argument and authorise themselves with `Gate::forUser($actor)`; session-level actions (`AcceptInvitation`, `StopImpersonation`) and the audit writer take no actor. An action that takes an `Organisation` calls `HostScope::lockForWrite($organisation)` inside its transaction before `Tenant::run()`.
- Livewire components authorise in `mount()` and again before calling an action, keep `#[Locked]` ids instead of Eloquent model properties, and re-resolve the model on every request. Actions that only make sense on a tenant host carry `#[App\Tenancy\Attributes\RequiresTenant]`.
- A non-super administrator acts on a user only when the user's effective permissions are a subset of their own: read them with `App\Authorization\EffectivePermissions` (never Spatie relations on a collection) and decide with `App\Authorization\UserAbilities`, also for index rows.
- A policy method named like an always-checked ability (`impersonate`, `delete`, `forceDelete`, `updateStatus`, `updateRoles`, `updateSuperAdmin`) grants super admins itself; other abilities rely on `Gate::before`.
- Audit entries are written only through `App\Actions\Audit\RecordActivity` with an `App\Enums\ActivityEvent` case; never `activity()`, the activitylog facade or the `LogsActivity` trait. Never log a password, a token or an accept URL.
- Invitations are created only through `App\Actions\Invitations\InviteUser`; their links come from `Invitation::acceptUrl()`, built when the queued mail renders. Mails implement `ShouldQueue` and call `afterCommit()`; test them on the sync queue with the `array` mailer, not `Mail::fake()`.
- Impersonation runs only through `App\Actions\Users\{StartImpersonation,StopImpersonation}`; check it with `App\Tenancy\Auth\Impersonation::isActive()`. Tests that depend on the module call `useImpersonationModule(true)` or `useImpersonationModule(false)` first, because CI also runs the suite with `MODULE_IMPERSONATION=false`.
- Every new `__()` key goes into both `lang/nl.json` and `lang/en.json` (`LangCoverageTest`); Dutch copy uses the informal "je".

## UI

- Pages render through `layouts/base` only (the app and auth layouts wrap it); never write `<html>` elsewhere. Two exceptions: error pages extend the self-contained `errors.layout`, and the setup pages use `layouts/setup`, which must never use `@vite`, `@fonts` or `public/build`, because the wizard runs before the first build (§5.3.4).
- Navigation entries go into `App\Support\Navigation\Navigation::items()`, never into a Blade file.
- Pro-only Flux components appear only inside `@if (Skv::hasFluxPro())`, with a complete free `@else` that binds the same `wire:model` properties. A feature without a free variant (§6.7: the ⌘K palette, the sign-in trend) has a `{{-- pro-only … --}}` comment right before its `@if` instead. `tests/Arch/FluxProLayerTest.php` checks where Pro tags sit, that the `@else` exists and renders something, and the `wire:model` names.
- User preferences go through `App\Actions\Settings\{UpdateLocale,UpdateAppearance}`.
- Read the default language with `Locale::default()`, never `config('app.locale')`.
- Tests that need passkeys call `usePasskeysModule(true)`, because CI also runs the suite with `MODULE_PASSKEYS=false`.
- Browser tests live in `tests/Browser` and pick a host with `->withHost()`; after the first page, navigate with `sameOrigin($page->url(), '/path')`. Run them with `composer test:browser`.

## Setup

- Setup steps (`App\Setup\Steps\*`) resolve every file through `SetupContext::path()`, never `base_path()`, so tests run them against a `setupSandbox()`.
- External commands (`herd`, `npm`, `npx`, `git`, `claude`, `composer`) run through `Process` with `App\Setup\Support\Toolchain::environment()`, and `herd` by its absolute path (`Toolchain::herd()`); under php-fpm there is no PATH. Tests fake every command (`Process::preventStrayProcesses()` + `Process::fake([])` + a sandbox `Toolchain`).
- Never branch on a Herd exit code alone: parse its output or read the state back.
- The demo seeder (`Database\Seeders\DemoSeeder`) runs only in the `local` and `testing` environments; `skv:install` refuses `start_with=demo` elsewhere.
- A new doctor check is one class per check under `App\Setup\Doctor\Checks`, implementing `App\Setup\Doctor\Check`, and is listed in `Doctor::checks()`.
- The wizard and the CLI drive the same steps through `SetupRunner::runStep()`. A new step implements `SetupStep` and sets `isLong()` honestly: a long step runs detached in the browser (`skv:run-step`, polled by the page).
- Wizard code that reads tenant models lives in `App\Setup` (strict mode, `Tenant::bypass()`); the wizard page (`resources/views/pages/setup/**`) and the setup controllers call those services.
- Never write the setup state files by hand. Use `App\Setup\Support\StateFile` and the classes' constants (`SetupToken::PATH`, `WizardState::PATH`/`STEPS`, `HerdState::PATH`/`EXIT`/`LOG`, `Marker::FILE`).
- Wizard tests start with `useSetupWizard()` and never spawn a process: it binds `Tests\Fixtures\InlineStepLauncher`, which runs detached steps inline. Test the page over HTTP (`pageSnapshot()` + `callPageAction()`), because `Livewire::test()` skips the gate and the setup runtime. `useSetupWizard()` switches to the local environment, where CSRF checks run, so forged updates send `X-CSRF-TOKEN`.
- Run `npm run build:setup` and commit `resources/setup/setup.css` after changing `resources/views/{layouts/setup.blade.php,pages/setup,setup}` or `resources/css/{theme,setup}.css`; CI checks the file is current.

## Ops

- A health check is a doctor check (`App\Setup\Doctor\Check`): list it in `App\Support\Health\HealthReport::checks()`, and in `Doctor::checks()` only when a fresh install passes it. `HealthReport` and the System page run checks through `DoctorContext::withoutDetection()`, never `forApplication()`, in web code: detection starts `herd` processes and a PostgreSQL probe. A doctor check the System page runs is listed in `App\Support\Health\SystemChecks::KEYS` and starts no process.
- Ops caches (the health report, the health-check throttle, the scheduler heartbeat) use `Cache::store(config('skv.ops_cache_store'))`, never the default store, so `/health-check` still answers while the database is down.
- Audit entries written by Artisan commands are tagged automatically (`App\Actions\Audit\ConsoleOrigin`). Never fake a causer for a command.
- Secrets never travel in argv: use `--password-stdin` or `--answers=@file|-`. A new command that takes a secret reads it with `App\Console\Concerns\ReadsStandardInput`.
- Backup tests use `Storage::fake('backups')` and a sandbox `backup.backup.temporary_directory`; only `BackupRunTest` runs a real `pg_dump`. A test that renders the System page binds a sandboxed `HealthReport` and fakes the `backups` disk.
- Tests that depend on the backups module call `useBackupsModule(true)` or `useBackupsModule(false)` first (`true` also sets a test archive password), because the suite runs on SQLite, where the module is off, and on PostgreSQL, where it is on.
- Tests that assert what a command's `CommandStarting` / `CommandFinished` listeners do use `Illuminate\Foundation\Testing\WithConsoleEvents`: without it the console kernel fires no command events under Pest.
