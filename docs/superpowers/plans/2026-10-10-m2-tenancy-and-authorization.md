# M2: Tenancy + Authorization — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make skv3 a working single-database multi-tenant app: organisations resolved from the subdomain, a strict tenant scope, host-scoped route groups, tenant-aware queues and a team-scoped permission model with a super-admin flag, green on SQLite and PostgreSQL.

**Architecture:** A scoped `CurrentTenant` service (facade `Tenant`) is bound by a `web`-group middleware that asks a `TenantResolver` (subdomain) for the organisation; `TenantResolved` / `TenantForgotten` events keep the Spatie team id in sync. Tenant models carry the `BelongsToOrganisation` trait and its `TenantScope`, which throws in strict mode when no tenant is bound outside the console. Authorization is spatie/laravel-permission in teams mode with one global `organisation_admin` template role (DEC-21), a `Permission` / `Role` enum catalogue synced by `permissions:sync`, `users.is_super_admin` as the only super-admin source, and actions (`SetSuperAdmin`, `UpdateUserRoles`) that carry the escalation and lock-out guards.

**Tech Stack:** PHP 8.4, Laravel 13.35, Livewire 4.4, Fortify 1.41, spatie/laravel-permission 8.3 (new), Pest 5.3, Larastan 3 level 8, Rector 2, Pint, PostgreSQL 18 (Herd), SQLite.

**Spec:** `docs/superpowers/specs/2026-10-10-skv3-design.md` — read §4.0, §4.1, §4.4, §4.6, §6.1, §6.2, §6.3, §7.2, §11.2 (M2 row), §13 and Appendix A (W1, W2, W6, W7, W8, W11, W12) before starting. Decisions: `docs/decisions.md` DEC-13, DEC-21, DEC-25 (M2 follow-ups).

## Global Constraints

- PHP floor `^8.4`; `laravel/framework ^13.35`; Pest `^5.3`; Larastan level **8**, **no baseline**, no new `ignoreErrors` entries.
- Pint: `laravel` preset + `declare_strict_types`, `strict_comparison`, `ordered_class_elements`. Every PHP file starts with `declare(strict_types=1);`. Run `composer fix` after editing PHP.
- Livewire components are multi-file (MFC) with no ⚡ in any path; components never call `$this->view()`.
- Every milestone ends with `composer check` green on SQLite **and** `composer test:pgsql` green on PostgreSQL.
- Never use driver-specific SQL (`ilike`, `ALTER TABLE … CONSTRAINT`); use the query builder. Partial unique indexes via one portable `CREATE UNIQUE INDEX … WHERE deleted_at IS NULL` are allowed (§4.4).
- Never call `env()` outside `config/`.
- No hard-coded kit or site name (`skv`) in app-identity values; derive hosts from `APP_URL` / `TENANCY_APEX_DOMAIN`.
- `livewire/flux-pro` and `composer.fluxui.dev` never appear in `composer.json` / `composer.lock`.
- No permission string literals in app code: use the `App\Authorization\Permission` enum (§4.6).
- `setPermissionsTeamId()` is called only inside `App\Authorization\PermissionsTeam` (§6.3).
- `Tenant::bypass()` only in `App\Console`, `App\Setup`, `App\Tenancy`, `App\Actions\Audit`, `Database\Seeders`, the central Livewire components and the health controller (§6.2).
- Code, comments, docs: English. UI copy: `nl` default with `en`; every new `__()` key goes into **both** `lang/nl.json` and `lang/en.json` (Dutch uses informal "je").
- Tests are Pest functions (`it(...)`), each file starts with a `/** covers §x.y */` docblock as its first statement. Feature tests get `RefreshDatabase` from `tests/Pest.php`.
- Commits: Conventional Commits, lower case, imperative; end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- A test that expects a database error wraps the failing statement in `DB::transaction(...)`: the savepoint keeps the RefreshDatabase transaction usable on PostgreSQL, which otherwise rejects every later statement with SQLSTATE 25P02.
- Never run `herd sites`, `migrate:fresh` or `db:wipe` against the development database; `tinker`, `migrate`, `db:seed`, `composer require` and `vendor:publish` prompt for approval — that is expected.

## Conventions used in this plan

- All M2 work happens on branch `m2-tenancy-authorization`, created from `main` in Task 1.
- Test hosts: phpunit pins `APP_URL=http://localhost` and `TENANCY_APEX_DOMAIN=localhost`, so the apex is `localhost`, the admin host `admin.localhost` and a tenant host `{slug}.localhost`. HTTP tests never need DNS.
- Host helpers (`onTenant()`, `onApex()`, `onAdminHost()`, `asSuperAdmin()`, `asOrgAdmin()`, `asMember()`) exist from Task 6 on and set the URL origin with `URL::useOrigin()` (`URL::forceRootUrl()` is deprecated in Laravel 13; `withServerVariables(['HTTP_HOST' => …])` is overwritten by the request URL).
- `Livewire::test()` runs with **all middleware disabled**. Anything that depends on middleware (tenant resolution, membership, host groups, persistent middleware) is tested with real HTTP requests.
- `CommandStarting` is **not** fired in unit tests; console behaviour is tested by dispatching the event directly.
- Spatie quirk: inside team X, `Role::findByName()` / `findById()` also return null-team roles. Every lookup that must stay inside a team adds an explicit `where('team_id', …)`. After switching the team id, call `$user->unsetRelation('roles')` on models that may have loaded roles.
- Known limitation (DEC-21, DEC-25): the pivot primary keys exclude `team_id`, so one user cannot hold the same role in two teams. DEC-13 (one organisation per user) makes this acceptable; `UpdateUserRoles` only ever attaches roles in the target user's own team.

## Scope

**In M2:** spec §6.2 (tenancy core, host scoping, strict mode, queue context, `tenant_url`, cascade), §6.3 (enums, `permissions:sync`, `withPermissionsTeam()`, `SetSuperAdmin`, `UpdateUserRoles`, `Gate::before`), §4.1 (except the tenant name in the brand), the organisation model (§4.3 model only), the users schema of §4.4 (status, soft deletes, `is_super_admin`, partial unique email), §4.0 `Model::shouldBeStrict`, §7.2 tenancy testing API and kit arch rules, and the DEC-25 M2 follow-ups.

**Deferred (recorded in DEC-26, Task 17):**
- M3: host-scoped login rule (incl. `Passkeys::authorizeLoginUsing()`), passkey origin listener (`passkeys.allowed_origins`), handoff (§6.4), `AuthenticateSession`. (M2 already loads two-factor and passkey users without the tenant scope, so strict mode breaks no login.)
- M4: organisations/users/roles UI, super admin on a central `/` → organisations index, `DeleteUser` / `UpdateUserStatus` actions, `RolePolicy`, impersonation (incl. the impersonation-aware membership check), activity logging of role and super-admin changes, `#[RequiresTenant]` for component actions, a slug validation rule.
- M5: tenant name in the brand, per-user locale column and middleware.
- M6: doctor checks for the template role and badge drift, `skv:create-super-admin`, demo seeder.

## File map

| Path | Responsibility |
|---|---|
| `database/migrations/0001_01_00_000000_create_organisations_table.php` | organisations (sorts before users so the FK can be created inline) |
| `database/migrations/0001_01_01_000000_create_users_table.php` | users + `organisation_id`, `is_super_admin`, `status`, soft deletes, partial unique email |
| `database/migrations/0001_01_01_000003_create_permission_tables.php` | spatie tables, DEC-21 pivot keys |
| `app/Models/Organisation.php`, `database/factories/OrganisationFactory.php` | organisation model |
| `app/Models/User.php`, `database/factories/UserFactory.php`, `app/Enums/UserStatus.php` | user model, states, status enum |
| `config/permission.php`, `config/tenancy.php`, `config/auth.php` | package + tenancy config, unscoped provider driver |
| `app/Authorization/{Permission,PermissionGroup,Role,PermissionsTeam,SyncPermissions,SyncResult,SetSuperAdmin,SuperAdminGate}.php`, `app/Authorization/helpers.php` | authorization core |
| `app/Console/Commands/SyncPermissionsCommand.php` | `permissions:sync` |
| `database/seeders/{EssentialsSeeder,DatabaseSeeder}.php` | catalogue seeding |
| `app/Tenancy/{CurrentTenant,CentralContext,Hosts,SoftDeleteCascade}.php` | tenancy core |
| `app/Tenancy/Facades/Tenant.php` | facade |
| `app/Tenancy/Events/{TenantResolved,TenantForgotten}.php`, `app/Tenancy/Listeners/SetPermissionsTeam.php` | team-id sync |
| `app/Tenancy/Scopes/TenantScope.php`, `app/Tenancy/Concerns/BelongsToOrganisation.php` | scope + trait |
| `app/Tenancy/Exceptions/{TenantNotResolvedException,TenantNotFoundException}.php` | errors |
| `app/Tenancy/Contracts/TenantResolver.php`, `app/Tenancy/Resolvers/SubdomainResolver.php` | host → organisation |
| `app/Tenancy/Http/Middleware/{InitializeTenancy,VerifyTenantMembership,EnsureTenantHost,EnsureCentralHost}.php` | middleware |
| `app/Tenancy/Auth/{UnscopedUserProvider,UnscopedTwoFactorLoginRequest}.php` | auth lookups without the tenant scope |
| `app/Tenancy/Queue/JobTenancy.php` | queue tenant context |
| `app/Tenancy/Observers/OrganisationObserver.php` | slug cache + cascade |
| `app/Providers/TenancyServiceProvider.php`, `bootstrap/providers.php`, `bootstrap/app.php` | wiring |
| `app/Support/helpers.php` | `tenant_url()` |
| `app/Policies/{UserPolicy,OrganisationPolicy}.php` | policies |
| `app/Actions/Users/UpdateUserRoles.php` | role assignment (W1, W12) |
| `resources/views/errors/tenant-not-found.blade.php` | friendly tenant 404 |
| `lang/nl.json`, `lang/en.json` | UI copy |
| `tests/Pest.php`, `tests/TestCase.php`, `phpunit.xml` | tenancy testing API, seeding once per process, host pins |
| `tests/Feature/{Tenancy,Authorization,Organisations,Users}/*`, `tests/Arch/*`, `tests/Fixtures/*` | tests |
| `docs/decisions.md`, `CHANGELOG.md`, `.ai/guidelines/skv3.md`, `CLAUDE.md` | docs |

---

### Task 1: Organisations and the users schema

**Files:**
- Create: `database/migrations/0001_01_00_000000_create_organisations_table.php`, `app/Models/Organisation.php`, `database/factories/OrganisationFactory.php`, `app/Enums/UserStatus.php`, `lang/nl.json`, `lang/en.json`
- Modify: `database/migrations/0001_01_01_000000_create_users_table.php`, `app/Models/User.php`, `database/factories/UserFactory.php`, `app/Concerns/ProfileValidationRules.php`
- Test: `tests/Feature/Organisations/OrganisationModelTest.php`, `tests/Feature/Users/UserModelTest.php`, `tests/Feature/Settings/ProfileUpdateTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Models\Organisation` (`id`, `name`, `slug`, `description`, `require_second_factor`, `deleted_at`; `users(): HasMany<User, $this>`); `App\Enums\UserStatus` (`PendingActivation`, `Active`, `Disabled`, `label(): string`); `User` attributes `organisation_id: ?int`, `is_super_admin: bool`, `status: UserStatus`, `deleted_at`; `User::organisation(): BelongsTo<Organisation, $this>`, `User::isSuperAdmin(): bool`, `User::isActive(): bool`; factory states `UserFactory::pending()`, `disabled()`, `superAdmin()`; `OrganisationFactory`.

- [ ] **Step 1: Create the branch**

```bash
git switch main && git pull --ff-only 2>/dev/null; git switch -c m2-tenancy-authorization
```

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Organisations/OrganisationModelTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.3 */

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('creates an organisation with a valid slug from the factory', function (): void {
    $organisation = Organisation::factory()->create();

    expect($organisation->slug)->toMatch('/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])$/')
        ->and($organisation->require_second_factor)->toBeFalse();
});

it('requires a unique name and a unique slug', function (): void {
    $organisation = Organisation::factory()->create();

    expect(fn () => DB::transaction(fn (): Organisation => Organisation::factory()->create(['name' => $organisation->name])))
        ->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => DB::transaction(fn (): Organisation => Organisation::factory()->create(['slug' => $organisation->slug])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('soft deletes organisations', function (): void {
    $organisation = Organisation::factory()->create();

    $organisation->delete();

    expect($organisation->trashed())->toBeTrue()
        ->and(Organisation::query()->find($organisation->id))->toBeNull()
        ->and(Organisation::withTrashed()->find($organisation->id))->not->toBeNull();
});

it('has users', function (): void {
    $organisation = Organisation::factory()->create();
    $user = User::factory()->for($organisation)->create();

    expect($organisation->users()->pluck('id')->all())->toBe([$user->id]);
});
```

`tests/Feature/Users/UserModelTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.4, §6.3 */

use App\Enums\UserStatus;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('belongs to an organisation and is active by default in the factory', function (): void {
    $user = User::factory()->create();

    expect($user->organisation_id)->not->toBeNull()
        ->and($user->organisation?->id)->toBe($user->organisation_id)
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->isActive())->toBeTrue()
        ->and($user->isSuperAdmin())->toBeFalse();
});

it('has pending, disabled and super admin factory states', function (): void {
    expect(User::factory()->pending()->create()->status)->toBe(UserStatus::PendingActivation)
        ->and(User::factory()->disabled()->create()->isActive())->toBeFalse();

    $superAdmin = User::factory()->superAdmin()->create();

    expect($superAdmin->isSuperAdmin())->toBeTrue()
        ->and($superAdmin->organisation_id)->toBeNull();
});

it('never infers super admin from a missing organisation (W8)', function (): void {
    $user = User::factory()->make(['organisation_id' => null]);

    expect($user->isSuperAdmin())->toBeFalse();
});

it('defaults the status column to pending activation', function (): void {
    $id = DB::table('users')->insertGetId([
        'name' => 'Raw',
        'email' => 'raw@example.com',
        'password' => 'x',
        'organisation_id' => Organisation::factory()->create()->id,
    ]);

    expect(DB::table('users')->where('id', $id)->value('status'))->toBe('pending_activation')
        ->and(DB::table('users')->where('id', $id)->value('is_super_admin'))->toBeFalsy();
});

it('keeps emails unique among non-deleted users only', function (): void {
    $user = User::factory()->create(['email' => 'same@example.com']);

    expect(fn () => DB::transaction(fn (): User => User::factory()->create(['email' => 'same@example.com'])))
        ->toThrow(UniqueConstraintViolationException::class);

    $user->delete();

    expect(User::factory()->create(['email' => 'same@example.com'])->email)->toBe('same@example.com');
});

it('soft deletes users', function (): void {
    $user = User::factory()->create();

    $user->delete();

    expect($user->trashed())->toBeTrue();
});

it('labels every status', function (UserStatus $status): void {
    expect($status->label())->not->toBe('');
})->with(UserStatus::cases());
```

Add to `tests/Feature/Settings/ProfileUpdateTest.php` (keep its existing style, `use App\Models\User; use Livewire\Livewire;` are already imported):

```php
test('email address of a deleted user can be reused', function (): void {
    User::factory()->create(['email' => 'former@example.com'])->delete();
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::settings.profile')
        ->set('name', $user->name)
        ->set('email', 'former@example.com')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->refresh()->email)->toBe('former@example.com');
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Organisations tests/Feature/Users tests/Feature/Settings/ProfileUpdateTest.php`
Expected: FAIL — `Class "App\Models\Organisation" not found` and unknown factory states.

- [ ] **Step 4: Create the organisations migration**

`database/migrations/0001_01_00_000000_create_organisations_table.php` (the file name sorts before `0001_01_01_000000_create_users_table.php`, so `users.organisation_id` can reference it inline without an `ALTER TABLE` on SQLite):

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('require_second_factor')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisations');
    }
};
```

- [ ] **Step 5: Reshape the users table**

In `database/migrations/0001_01_01_000000_create_users_table.php`, replace the `users` table definition with the block below (keep the `password_reset_tokens` and `sessions` tables unchanged) and add `use Illuminate\Support\Facades\DB;`:

```php
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_super_admin')->default(false)->index();
            $table->string('status')->default('pending_activation')->index();
            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        // §4.4: email is unique among non-deleted users only. One portable partial index
        // (PostgreSQL and SQLite), never an ALTER TABLE ... CONSTRAINT.
        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE deleted_at IS NULL');
```

The `status` default is a literal on purpose: migrations must not depend on app classes that may change later.

- [ ] **Step 6: Add the status enum**

`app/Enums/UserStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum UserStatus: string
{
    case PendingActivation = 'pending_activation';
    case Active = 'active';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::PendingActivation => __('Pending activation'),
            self::Active => __('Active'),
            self::Disabled => __('Disabled'),
        };
    }
}
```

Create `lang/nl.json`:

```json
{
    "Active": "Actief",
    "Disabled": "Uitgeschakeld",
    "Pending activation": "Wacht op activatie"
}
```

Create `lang/en.json` with the same keys mapped to themselves:

```json
{
    "Active": "Active",
    "Disabled": "Disabled",
    "Pending activation": "Pending activation"
}
```

Keep both files sorted by key; every later task adds its keys to both.

- [ ] **Step 7: Add the Organisation model and factory**

`app/Models/Organisation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrganisationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $require_second_factor
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'slug', 'description', 'require_second_factor'])]
class Organisation extends Model
{
    /** @use HasFactory<OrganisationFactory> */
    use HasFactory, SoftDeletes;

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'require_second_factor' => 'boolean',
        ];
    }
}
```

`database/factories/OrganisationFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Organisation> */
class OrganisationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => trim(Str::limit(Str::slug($name), 63, ''), '-'),
            'description' => fake()->optional()->sentence(),
            'require_second_factor' => false,
        ];
    }
}
```

- [ ] **Step 8: Extend the User model**

In `app/Models/User.php`:
1. Add imports `App\Enums\UserStatus`, `Illuminate\Database\Eloquent\Relations\BelongsTo`, `Illuminate\Database\Eloquent\SoftDeletes`.
2. Add `SoftDeletes` to the `use` trait list (keep it alphabetical: `HasFactory, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable`).
3. Add to the class docblock:

```php
 * @property int|null $organisation_id
 * @property bool $is_super_admin
 * @property UserStatus $status
 * @property Carbon|null $deleted_at
 * @property-read Organisation|null $organisation
```

4. Add the methods (Pint orders them):

```php
    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** §6.3: the column is the only source of truth; a missing organisation never implies it (W8). */
    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin === true;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }
```

5. Extend `casts()`:

```php
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'status' => UserStatus::class,
        ];
```

`#[Fillable(['name', 'email', 'password'])]` stays as it is: `organisation_id`, `is_super_admin` and `status` are written by actions with `forceFill()` only.

- [ ] **Step 9: Extend the user factory**

In `database/factories/UserFactory.php` add imports `App\Enums\UserStatus`, `App\Models\Organisation`, add these keys at the top of the array returned by `definition()`:

```php
            'organisation_id' => Organisation::factory(),
            'is_super_admin' => false,
            'status' => UserStatus::Active,
```

and add the states:

```php
    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => UserStatus::PendingActivation,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => UserStatus::Disabled,
        ]);
    }

    /** Sets the flag only; SetSuperAdmin (Task 4) also attaches the super_admin badge role. */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'organisation_id' => null,
            'is_super_admin' => true,
        ]);
    }
```

- [ ] **Step 10: Ignore soft-deleted users in the profile email rule**

In `app/Concerns/ProfileValidationRules.php::emailRules()`, change both branches (§4.4: email uniqueness uses `withoutTrashed()` everywhere; M4's invite flow calls it without an id):

```php
            $userId === null
                ? Rule::unique(User::class)->withoutTrashed()
                : Rule::unique(User::class)->withoutTrashed()->ignore($userId),
```

- [ ] **Step 11: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Organisations tests/Feature/Users tests/Feature/Settings/ProfileUpdateTest.php`
Expected: PASS.

- [ ] **Step 12: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green.

- [ ] **Step 13: Commit**

```bash
git add -A
git commit -m "feat: add organisations and the tenancy columns on users

- organisations table and model with soft deletes
- users: organisation_id, is_super_admin, status, soft deletes
- email unique among non-deleted users via a partial index (§4.4)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: spatie/laravel-permission in teams mode and `PermissionsTeam`

**Files:**
- Modify: `composer.json`, `composer.lock`, `app/Models/User.php`
- Create: `config/permission.php` (published), `database/migrations/0001_01_01_000003_create_permission_tables.php`, `app/Authorization/PermissionsTeam.php`, `app/Authorization/helpers.php`
- Test: `tests/Feature/Authorization/TemplateRoleTest.php`, `tests/Feature/Authorization/PermissionsTeamTest.php`

**Interfaces:**
- Consumes: `Organisation`, `User` factories (Task 1).
- Produces: `App\Authorization\PermissionsTeam::current(): ?int`, `::set(?int $teamId): void` (tenancy listeners only), `::run(?int $teamId, Closure $callback): mixed` (restores in `finally`); global `withPermissionsTeam(?int $teamId, Closure $callback): mixed`; `User` uses `Spatie\Permission\Traits\HasRoles`.

- [ ] **Step 1: Install the package**

The package is part of the approved spec (§3, §4.6); the command prompts for approval.

```bash
composer require spatie/laravel-permission:^8.3
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --no-interaction
```

Expected: `config/permission.php` and `database/migrations/<timestamp>_create_permission_tables.php` are created.

- [ ] **Step 2: Turn on teams mode**

In `config/permission.php` set `'teams' => true,`. Leave the other keys as published. Add `declare(strict_types=1);` if the published file lacks it (Pint adds it on `composer fix`).

- [ ] **Step 3: Replace the published migration with the DEC-21 shape**

Delete the published `database/migrations/<timestamp>_create_permission_tables.php` and create `database/migrations/0001_01_01_000003_create_permission_tables.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * spatie/laravel-permission 8.3 tables, teams mode always on.
 *
 * Deliberate deviation from the vendor stub (DEC-21): the pivot primary keys exclude
 * team_id and team_id is nullable, so the global organisation_admin template role can be
 * attached per team and the super_admin badge with a null team on PostgreSQL. A user can
 * therefore not hold the same role in two teams; DEC-13 (one organisation per user) makes
 * that acceptable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->index('team_id', 'roles_team_foreign_key_index');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['team_id', 'name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->index('team_id', 'model_has_permissions_team_foreign_key_index');
            $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->index('team_id', 'model_has_roles_team_foreign_key_index');
            $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
        });

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
```

Before deleting the published file, diff it against this one (`diff <published> database/migrations/0001_01_01_000003_create_permission_tables.php`) and confirm the only schema differences are: nullable `team_id` on both `model_has_*` tables, `team_id` absent from their primary keys, the removed `$teams` / `config()` branches, and literal table/column names (the defaults). If the 8.3 stub has any other column or index, add it here.

- [ ] **Step 4: Add `HasRoles` to the User model**

In `app/Models/User.php` add `use Spatie\Permission\Traits\HasRoles;` and the trait to the list: `HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable`.

- [ ] **Step 5: Write the failing tests**

`tests/Feature/Authorization/TemplateRoleTest.php` (port of the S2 spike test, DEC-21):

```php
<?php

declare(strict_types=1);

/** covers §4.6, §6.3 */

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

it('assigns one global template role per team', function (): void {
    [$first, $second] = Organisation::factory()->count(2)->create()->all();
    $template = withPermissionsTeam(null, function (): RoleModel {
        PermissionModel::query()->firstOrCreate(['name' => 'spike.view', 'guard_name' => 'web']);
        $role = RoleModel::query()->create(['name' => 'spike_template', 'guard_name' => 'web', 'team_id' => null]);
        $role->givePermissionTo('spike.view');

        return $role;
    });

    $alice = User::factory()->for($first)->create();
    $bob = User::factory()->for($second)->create();
    withPermissionsTeam($first->id, fn () => $alice->assignRole($template));
    withPermissionsTeam($second->id, fn () => $bob->assignRole($template));

    withPermissionsTeam($first->id, function () use ($alice, $bob): void {
        expect($alice->fresh()?->hasRole('spike_template'))->toBeTrue()
            ->and($alice->fresh()?->can('spike.view'))->toBeTrue()
            ->and($bob->fresh()?->hasRole('spike_template'))->toBeFalse();
    });

    expect(RoleModel::query()->where('name', 'spike_template')->count())->toBe(1)
        ->and(DB::table('model_has_roles')->where('role_id', $template->id)->pluck('team_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all())
        ->toBe([$first->id, $second->id]);
});

it('attaches a global role with a null team', function (): void {
    $user = User::factory()->superAdmin()->create();

    withPermissionsTeam(null, function () use ($user): void {
        $role = RoleModel::query()->create(['name' => 'spike_badge', 'guard_name' => 'web', 'team_id' => null]);
        $user->assignRole($role);
    });

    expect(DB::table('model_has_roles')->where('model_id', $user->id)->whereNull('team_id')->count())->toBe(1);
});

it('propagates template permission changes to every team', function (): void {
    $organisation = Organisation::factory()->create();
    $user = User::factory()->for($organisation)->create();
    $template = withPermissionsTeam(null, function (): RoleModel {
        PermissionModel::query()->firstOrCreate(['name' => 'spike.manage', 'guard_name' => 'web']);

        return RoleModel::query()->create(['name' => 'spike_template', 'guard_name' => 'web', 'team_id' => null]);
    });
    withPermissionsTeam($organisation->id, fn () => $user->assignRole($template));

    $template->givePermissionTo('spike.manage');

    expect(withPermissionsTeam($organisation->id, fn (): bool => (bool) $user->fresh()?->can('spike.manage')))->toBeTrue();
});
```

`tests/Feature/Authorization/PermissionsTeamTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §6.3 */

use App\Authorization\PermissionsTeam;

it('runs a callback in a team and restores the previous team', function (): void {
    PermissionsTeam::set(3);

    $inside = PermissionsTeam::run(7, fn (): ?int => PermissionsTeam::current());

    expect($inside)->toBe(7)->and(PermissionsTeam::current())->toBe(3);
});

it('restores the previous team when the callback throws (W11)', function (): void {
    PermissionsTeam::set(null);

    expect(fn () => PermissionsTeam::run(5, fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class, 'boom');

    expect(PermissionsTeam::current())->toBeNull();
});

it('exposes the helper function', function (): void {
    expect(withPermissionsTeam(9, fn (): ?int => PermissionsTeam::current()))->toBe(9);
});
```

- [ ] **Step 6: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Authorization`
Expected: FAIL — `Class "App\Authorization\PermissionsTeam" not found` / `Call to undefined function withPermissionsTeam()`.

- [ ] **Step 7: Implement `PermissionsTeam` and the helper**

`app/Authorization/PermissionsTeam.php`:

```php
<?php

declare(strict_types=1);

namespace App\Authorization;

use Closure;
use Spatie\Permission\PermissionRegistrar;

/**
 * The only place that changes Spatie's team id (§6.3). Everything else uses run() /
 * withPermissionsTeam(), which restores the previous team in a finally block (W11).
 * After a switch, call $user->unsetRelation('roles') on models that may have loaded roles.
 */
final class PermissionsTeam
{
    public static function current(): ?int
    {
        $teamId = app(PermissionRegistrar::class)->getPermissionsTeamId();

        return $teamId === null ? null : (int) $teamId;
    }

    /** Reserved for the TenantResolved / TenantForgotten listeners. */
    public static function set(?int $teamId): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($teamId);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(?int $teamId, Closure $callback): mixed
    {
        $previous = self::current();
        self::set($teamId);

        try {
            return $callback();
        } finally {
            self::set($previous);
        }
    }
}
```

`app/Authorization/helpers.php`:

```php
<?php

declare(strict_types=1);

use App\Authorization\PermissionsTeam;

if (! function_exists('withPermissionsTeam')) {
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    function withPermissionsTeam(?int $teamId, Closure $callback): mixed
    {
        return PermissionsTeam::run($teamId, $callback);
    }
}
```

Register it in `composer.json` under `autoload` (keep the existing `psr-4` block):

```json
        "files": [
            "app/Authorization/helpers.php"
        ],
```

Run: `composer dump-autoload`

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Authorization`
Expected: PASS.

- [ ] **Step 9: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green. If Larastan reports the published `config/permission.php` (for example `DateInterval::createFromDateString()` returning `DateInterval|false`), fix the value in the config file (for example `'expiration_time' => new DateInterval('PT24H')`) — never add an ignore.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "feat: add spatie/laravel-permission in teams mode

- pivot keys without team_id and nullable team ids (DEC-21)
- PermissionsTeam and withPermissionsTeam() restore the team in finally (W11)
- port the S2 template-role test

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Permission catalogue, system roles and `permissions:sync`

**Files:**
- Create: `app/Authorization/Permission.php`, `app/Authorization/PermissionGroup.php`, `app/Authorization/Role.php`, `app/Authorization/SyncPermissions.php`, `app/Authorization/SyncResult.php`, `app/Console/Commands/SyncPermissionsCommand.php`, `database/seeders/EssentialsSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`, `tests/TestCase.php`, `tests/Arch/PresetsTest.php`, `lang/nl.json`, `lang/en.json`
- Test: `tests/Feature/Authorization/PermissionCatalogueTest.php`, `tests/Feature/Authorization/SyncPermissionsTest.php`, `tests/Feature/LangFilesTest.php`

**Interfaces:**
- Consumes: `PermissionsTeam` (Task 2).
- Produces:
  - `App\Authorization\Permission` (backed enum, 15 cases: `UsersView`, `UsersCreate`, `UsersUpdate`, `UsersDelete`, `UsersInviteResend`, `UsersImpersonate`, `RolesView`, `RolesAssign`, `RolesManage`, `OrganisationsView`, `OrganisationsCreate`, `OrganisationsUpdate`, `OrganisationsDelete`, `ActivityLogView`, `SystemHealth`) with `label(): string`, `description(): string`, `group(): PermissionGroup`, `superOnly(): bool`, `static organisationAdmin(): list<self>`, `static values(): list<string>`.
  - `App\Authorization\PermissionGroup` (`Users`, `Roles`, `Organisations`, `ActivityLog`, `System`; `label(): string`).
  - `App\Authorization\Role` (`SuperAdmin = 'super_admin'`, `OrganisationAdmin = 'organisation_admin'`; `label(): string`, `static values(): list<string>`).
  - `App\Authorization\SyncPermissions::handle(): SyncResult`; `SyncPermissions::GUARD = 'web'`.
  - `App\Authorization\SyncResult` (readonly: `list<string> $created`, `list<string> $pruned`, `bool $organisationAdminChanged`, `int $superOnlyDetached`, `int $badgesRepaired`; `changed(): bool`).
  - Artisan `permissions:sync`; `Database\Seeders\EssentialsSeeder`; `Tests\TestCase` seeds `EssentialsSeeder` once per process.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Authorization/PermissionCatalogueTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.6, §6.3 */

use App\Authorization\Permission;
use App\Authorization\PermissionGroup;
use App\Authorization\Role;

it('has the fifteen permissions of the catalogue', function (): void {
    expect(Permission::values())->toBe([
        'users.view', 'users.create', 'users.update', 'users.delete', 'users.invite.resend', 'users.impersonate',
        'roles.view', 'roles.assign', 'roles.manage',
        'organisations.view', 'organisations.create', 'organisations.update', 'organisations.delete',
        'activity_log.view', 'system.health',
    ]);
});

it('marks exactly the super-only permissions', function (): void {
    $superOnly = array_values(array_filter(Permission::cases(), fn (Permission $permission): bool => $permission->superOnly()));

    expect($superOnly)->toBe([
        Permission::RolesManage, Permission::OrganisationsCreate, Permission::OrganisationsDelete, Permission::SystemHealth,
    ]);
});

it('gives organisation admins the catalogue minus the super-only permissions', function (): void {
    expect(Permission::organisationAdmin())->toHaveCount(11)
        ->and(array_filter(Permission::organisationAdmin(), fn (Permission $permission): bool => $permission->superOnly()))->toBe([]);
});

it('labels, describes and groups every permission in Dutch', function (Permission $permission): void {
    app()->setLocale('nl');

    expect($permission->label())->not->toBe('')
        ->and(__($permission->label()))->toBe($permission->label())
        ->and($permission->description())->not->toBe('')
        ->and($permission->group())->toBeInstanceOf(PermissionGroup::class);
})->with(Permission::cases());

it('translates permission labels', function (): void {
    app()->setLocale('nl');

    expect(Permission::UsersView->label())->toBe('Gebruikers bekijken')
        ->and(PermissionGroup::ActivityLog->label())->toBe('Activiteitenlog')
        ->and(Role::OrganisationAdmin->label())->toBe('Organisatiebeheerder');
});

it('lists the system role names', function (): void {
    expect(Role::values())->toBe(['super_admin', 'organisation_admin']);
});
```

`tests/Feature/Authorization/SyncPermissionsTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.6, §6.3 */

use App\Authorization\Permission;
use App\Authorization\Role;
use App\Authorization\SyncPermissions;
use App\Models\Organisation;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

function systemRole(Role $role): RoleModel
{
    return RoleModel::query()->whereNull('team_id')->where('name', $role->value)->firstOrFail();
}

it('seeds the catalogue and the system roles once per test process', function (): void {
    expect(PermissionModel::query()->pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::values())->sort()->values()->all())
        ->and(systemRole(Role::OrganisationAdmin)->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::organisationAdmin())->map(fn (Permission $permission): string => $permission->value)->sort()->values()->all())
        ->and(systemRole(Role::SuperAdmin)->permissions)->toBeEmpty();
});

it('is idempotent', function (): void {
    $result = app(SyncPermissions::class)->handle();

    expect($result->changed())->toBeFalse()
        ->and(RoleModel::query()->whereNull('team_id')->count())->toBe(2);
});

it('prunes permissions that left the catalogue', function (): void {
    PermissionModel::query()->create(['name' => 'legacy.permission', 'guard_name' => 'web']);

    $result = app(SyncPermissions::class)->handle();

    expect($result->pruned)->toBe(['legacy.permission'])
        ->and(PermissionModel::query()->where('name', 'legacy.permission')->exists())->toBeFalse();
});

it('recreates a missing permission and repairs template drift (W7)', function (): void {
    PermissionModel::query()->where('name', Permission::UsersView->value)->delete();
    $template = systemRole(Role::OrganisationAdmin);
    $template->revokePermissionTo(Permission::RolesView->value);

    $result = app(SyncPermissions::class)->handle();

    expect($result->created)->toBe([Permission::UsersView->value])
        ->and($result->organisationAdminChanged)->toBeTrue()
        ->and($template->fresh()?->permissions->pluck('name')->all())->toContain(Permission::UsersView->value, Permission::RolesView->value);
});

it('recreates a deleted system role (W6)', function (): void {
    systemRole(Role::OrganisationAdmin)->delete();

    app(SyncPermissions::class)->handle();

    expect(systemRole(Role::OrganisationAdmin)->permissions)->toHaveCount(11);
});

it('strips super-only permissions from custom team roles', function (): void {
    $organisation = Organisation::factory()->create();
    $custom = RoleModel::query()->create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $custom->givePermissionTo(Permission::UsersView->value, Permission::OrganisationsCreate->value);

    $result = app(SyncPermissions::class)->handle();

    expect($result->superOnlyDetached)->toBe(1)
        ->and($custom->fresh()?->permissions->pluck('name')->all())->toBe([Permission::UsersView->value]);
});

it('runs as an artisan command', function (): void {
    expect(Artisan::call('permissions:sync'))->toBe(0);
});
```

`tests/Feature/LangFilesTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §6.5 */

it('keeps the Dutch and English JSON translations in step', function (): void {
    /** @var array<string, string> $dutch */
    $dutch = json_decode((string) file_get_contents(lang_path('nl.json')), true, flags: JSON_THROW_ON_ERROR);
    /** @var array<string, string> $english */
    $english = json_decode((string) file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($dutch))->toBe(array_keys($english));
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Authorization tests/Feature/LangFilesTest.php`
Expected: FAIL — `Class "App\Authorization\Permission" not found`.

- [ ] **Step 3: Implement the enums**

`app/Authorization/PermissionGroup.php`:

```php
<?php

declare(strict_types=1);

namespace App\Authorization;

enum PermissionGroup: string
{
    case Users = 'users';
    case Roles = 'roles';
    case Organisations = 'organisations';
    case ActivityLog = 'activity_log';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Users => __('Users'),
            self::Roles => __('Roles'),
            self::Organisations => __('Organisations'),
            self::ActivityLog => __('Activity log'),
            self::System => __('System'),
        };
    }
}
```

`app/Authorization/Permission.php`:

```php
<?php

declare(strict_types=1);

namespace App\Authorization;

/** The permission catalogue (§4.6). The only place where permission names are spelled out. */
enum Permission: string
{
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersDelete = 'users.delete';
    case UsersInviteResend = 'users.invite.resend';
    case UsersImpersonate = 'users.impersonate';
    case RolesView = 'roles.view';
    case RolesAssign = 'roles.assign';
    case RolesManage = 'roles.manage';
    case OrganisationsView = 'organisations.view';
    case OrganisationsCreate = 'organisations.create';
    case OrganisationsUpdate = 'organisations.update';
    case OrganisationsDelete = 'organisations.delete';
    case ActivityLogView = 'activity_log.view';
    case SystemHealth = 'system.health';

    /** @return list<self> */
    public static function organisationAdmin(): array
    {
        return array_values(array_filter(self::cases(), fn (self $permission): bool => ! $permission->superOnly()));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $permission): string => $permission->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::UsersView => __('View users'),
            self::UsersCreate => __('Invite users'),
            self::UsersUpdate => __('Edit users'),
            self::UsersDelete => __('Delete users'),
            self::UsersInviteResend => __('Resend invitations'),
            self::UsersImpersonate => __('Impersonate users'),
            self::RolesView => __('View roles'),
            self::RolesAssign => __('Assign roles'),
            self::RolesManage => __('Manage roles'),
            self::OrganisationsView => __('View organisations'),
            self::OrganisationsCreate => __('Create organisations'),
            self::OrganisationsUpdate => __('Edit organisations'),
            self::OrganisationsDelete => __('Delete organisations'),
            self::ActivityLogView => __('View activity log'),
            self::SystemHealth => __('View system health'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::UsersView => __('See the organisation\'s users and their details'),
            self::UsersCreate => __('Invite new users by email'),
            self::UsersUpdate => __('Change a user\'s name, email address and status'),
            self::UsersDelete => __('Delete users and cancel pending invitations'),
            self::UsersInviteResend => __('Send a pending invitation again'),
            self::UsersImpersonate => __('Act as another user to help them'),
            self::RolesView => __('See roles and their permissions'),
            self::RolesAssign => __('Give users roles or take them away'),
            self::RolesManage => __('Create, rename and delete roles and set their permissions'),
            self::OrganisationsView => __('See organisation details'),
            self::OrganisationsCreate => __('Add new organisations'),
            self::OrganisationsUpdate => __('Change an organisation\'s name and settings'),
            self::OrganisationsDelete => __('Archive, restore and purge organisations'),
            self::ActivityLogView => __('See who did what and when'),
            self::SystemHealth => __('See health checks, backups and the scheduler'),
        };
    }

    public function group(): PermissionGroup
    {
        return match ($this) {
            self::UsersView, self::UsersCreate, self::UsersUpdate, self::UsersDelete,
            self::UsersInviteResend, self::UsersImpersonate => PermissionGroup::Users,
            self::RolesView, self::RolesAssign, self::RolesManage => PermissionGroup::Roles,
            self::OrganisationsView, self::OrganisationsCreate, self::OrganisationsUpdate,
            self::OrganisationsDelete => PermissionGroup::Organisations,
            self::ActivityLogView => PermissionGroup::ActivityLog,
            self::SystemHealth => PermissionGroup::System,
        };
    }

    public function superOnly(): bool
    {
        return match ($this) {
            self::RolesManage, self::OrganisationsCreate, self::OrganisationsDelete, self::SystemHealth => true,
            default => false,
        };
    }
}
```

`app/Authorization/Role.php`:

```php
<?php

declare(strict_types=1);

namespace App\Authorization;

/**
 * System roles (§4.6, §6.3). super_admin is a display badge only: it carries no permissions
 * and is never assignable through UpdateUserRoles; users.is_super_admin grants the access.
 * organisation_admin is one global template role (team_id NULL) attached per team (DEC-21).
 */
enum Role: string
{
    case SuperAdmin = 'super_admin';
    case OrganisationAdmin = 'organisation_admin';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => __('Super admin'),
            self::OrganisationAdmin => __('Organisation admin'),
        };
    }
}
```

- [ ] **Step 4: Add the translations**

Add these keys to `lang/nl.json` (Dutch value shown) and to `lang/en.json` (value = key), keeping both sorted:

| Key | nl |
|---|---|
| `Act as another user to help them` | `Tijdelijk als een andere gebruiker werken om te helpen` |
| `Activity log` | `Activiteitenlog` |
| `Add new organisations` | `Nieuwe organisaties toevoegen` |
| `Archive, restore and purge organisations` | `Organisaties archiveren, herstellen en definitief verwijderen` |
| `Assign roles` | `Rollen toewijzen` |
| `Change a user's name, email address and status` | `Naam, e-mailadres en status van een gebruiker wijzigen` |
| `Change an organisation's name and settings` | `Naam en instellingen van een organisatie wijzigen` |
| `Create organisations` | `Organisaties aanmaken` |
| `Create, rename and delete roles and set their permissions` | `Rollen aanmaken, hernoemen, verwijderen en hun rechten instellen` |
| `Delete organisations` | `Organisaties verwijderen` |
| `Delete users` | `Gebruikers verwijderen` |
| `Delete users and cancel pending invitations` | `Gebruikers verwijderen en openstaande uitnodigingen intrekken` |
| `Edit organisations` | `Organisaties bewerken` |
| `Edit users` | `Gebruikers bewerken` |
| `Give users roles or take them away` | `Rollen aan gebruikers geven of afnemen` |
| `Impersonate users` | `Inloggen als gebruiker` |
| `Invite new users by email` | `Nieuwe gebruikers per e-mail uitnodigen` |
| `Invite users` | `Gebruikers uitnodigen` |
| `Manage roles` | `Rollen beheren` |
| `Organisation admin` | `Organisatiebeheerder` |
| `Organisations` | `Organisaties` |
| `Resend invitations` | `Uitnodigingen opnieuw versturen` |
| `Roles` | `Rollen` |
| `See health checks, backups and the scheduler` | `Gezondheidscontroles, back-ups en de planner bekijken` |
| `See organisation details` | `Organisatiegegevens bekijken` |
| `See roles and their permissions` | `Rollen en hun rechten bekijken` |
| `See the organisation's users and their details` | `De gebruikers van de organisatie en hun gegevens bekijken` |
| `See who did what and when` | `Zien wie wat wanneer heeft gedaan` |
| `Send a pending invitation again` | `Een openstaande uitnodiging opnieuw versturen` |
| `Super admin` | `Superbeheerder` |
| `System` | `Systeem` |
| `Users` | `Gebruikers` |
| `View activity log` | `Activiteitenlog bekijken` |
| `View organisations` | `Organisaties bekijken` |
| `View roles` | `Rollen bekijken` |
| `View system health` | `Systeemstatus bekijken` |
| `View users` | `Gebruikers bekijken` |

- [ ] **Step 5: Implement the sync action and its result**

`app/Authorization/SyncResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Authorization;

final readonly class SyncResult
{
    /**
     * @param  list<string>  $created
     * @param  list<string>  $pruned
     */
    public function __construct(
        public array $created,
        public array $pruned,
        public bool $organisationAdminChanged,
        public int $superOnlyDetached,
        public int $badgesRepaired,
    ) {}

    public function changed(): bool
    {
        return $this->created !== []
            || $this->pruned !== []
            || $this->organisationAdminChanged
            || $this->superOnlyDetached > 0
            || $this->badgesRepaired > 0;
    }
}
```

`app/Authorization/SyncPermissions.php`:

```php
<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent catalogue sync (§6.3, DEC-21 option A): upserts the Permission enum, prunes
 * removed permissions, keeps the global organisation_admin template on the organisation-admin
 * set, keeps the super_admin badge without permissions and strips super-only permissions from
 * custom team roles. Runs in setup, on deploy, via doctor --fix and in EssentialsSeeder.
 */
final class SyncPermissions
{
    public const string GUARD = 'web';

    public function handle(): SyncResult
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $result = PermissionsTeam::run(null, fn (): SyncResult => DB::transaction(function (): SyncResult {
            $created = $this->upsertPermissions();
            $pruned = $this->prunePermissions();
            $organisationAdminChanged = $this->syncOrganisationAdmin();
            $this->systemRole(Role::SuperAdmin)->syncPermissions([]);
            $superOnlyDetached = $this->detachSuperOnlyFromTeamRoles();

            return new SyncResult($created, $pruned, $organisationAdminChanged, $superOnlyDetached, 0);
        }));

        $registrar->forgetCachedPermissions();

        return $result;
    }

    /** @return list<string> */
    private function upsertPermissions(): array
    {
        $existing = PermissionModel::query()->where('guard_name', self::GUARD)->pluck('name')->all();
        $created = [];

        foreach (Permission::values() as $name) {
            if (! in_array($name, $existing, true)) {
                PermissionModel::query()->create(['name' => $name, 'guard_name' => self::GUARD]);
                $created[] = $name;
            }
        }

        return $created;
    }

    /** @return list<string> */
    private function prunePermissions(): array
    {
        $stale = array_values(PermissionModel::query()
            ->where('guard_name', self::GUARD)
            ->whereNotIn('name', Permission::values())
            ->pluck('name')
            ->map(fn (mixed $name): string => (string) $name)
            ->all());

        PermissionModel::query()->where('guard_name', self::GUARD)->whereIn('name', $stale)->delete();

        return $stale;
    }

    private function syncOrganisationAdmin(): bool
    {
        $template = $this->systemRole(Role::OrganisationAdmin);
        $wanted = array_map(fn (Permission $permission): string => $permission->value, Permission::organisationAdmin());
        $current = $template->permissions()->pluck('name')->map(fn (mixed $name): string => (string) $name)->all();

        sort($wanted);
        sort($current);

        if ($wanted === $current) {
            return false;
        }

        $template->syncPermissions($wanted);

        return true;
    }

    private function detachSuperOnlyFromTeamRoles(): int
    {
        $superOnly = array_values(array_map(
            fn (Permission $permission): string => $permission->value,
            array_filter(Permission::cases(), fn (Permission $permission): bool => $permission->superOnly()),
        ));

        return DB::table('role_has_permissions')
            ->whereIn('permission_id', PermissionModel::query()->whereIn('name', $superOnly)->select('id'))
            ->whereIn('role_id', RoleModel::query()->whereNotNull('team_id')->select('id'))
            ->delete();
    }

    private function systemRole(Role $role): RoleModel
    {
        $existing = RoleModel::query()
            ->whereNull('team_id')
            ->where('name', $role->value)
            ->where('guard_name', self::GUARD)
            ->first();

        return $existing ?? RoleModel::query()->create([
            'name' => $role->value,
            'guard_name' => self::GUARD,
            'team_id' => null,
        ]);
    }
}
```

- [ ] **Step 6: Add the command and the seeders**

`app/Console/Commands/SyncPermissionsCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Authorization\SyncPermissions;
use Illuminate\Console\Command;

final class SyncPermissionsCommand extends Command
{
    /** @var string */
    protected $signature = 'permissions:sync';

    /** @var string */
    protected $description = 'Sync the permission catalogue and the system roles';

    public function handle(SyncPermissions $syncPermissions): int
    {
        $result = $syncPermissions->handle();

        $this->components->info($result->changed() ? 'Permissions synced.' : 'Permissions already in sync.');

        foreach ($result->created as $name) {
            $this->components->twoColumnDetail($name, 'created');
        }

        foreach ($result->pruned as $name) {
            $this->components->twoColumnDetail($name, 'pruned');
        }

        return self::SUCCESS;
    }
}
```

`database/seeders/EssentialsSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Authorization\SyncPermissions;
use Illuminate\Database\Seeder;

/** Always seeded (§4.12): the permission catalogue and the system roles. Idempotent. */
final class EssentialsSeeder extends Seeder
{
    public function run(SyncPermissions $syncPermissions): void
    {
        $syncPermissions->handle();
    }
}
```

In `database/seeders/DatabaseSeeder.php::run()`, add `$this->call(EssentialsSeeder::class);` as the first statement (Task 4 replaces the test user).

- [ ] **Step 7: Seed once per test process**

In `tests/TestCase.php` add the attribute (Laravel 13 reads it in `RefreshDatabase`, which runs `migrate:fresh --seeder=…` once per process and wraps each test in a transaction):

```php
use Database\Seeders\EssentialsSeeder;
use Illuminate\Foundation\Testing\Attributes\Seeder;

#[Seeder(EssentialsSeeder::class)]
abstract class TestCase extends BaseTestCase
```

- [ ] **Step 8: Exempt the spec's namespaces from the Laravel arch preset**

`preset()->laravel()` allows enums only in `App\Enums`, Throwables only in `App\Exceptions`, FormRequests only in `App\Http\Requests` and Eloquent models only in `App\Models`. Spec §6.1 puts the authorization enums in `App\Authorization`, and later tasks add tenancy exceptions (Tasks 5, 7) and two vendor subclasses (Task 15) under `App\Tenancy`. In `tests/Arch/PresetsTest.php` replace `arch()->preset()->laravel();` with:

```php
// Justified ignores (§7.2, DEC-26): the authorization enums live in App\Authorization (§6.1),
// the tenancy exceptions in App\Tenancy\Exceptions, and two Fortify/Passkeys classes are
// extended to load users without the tenant scope. ignoring() matches class-name prefixes, and
// names of classes that later tasks create are harmless until then.
arch()->preset()->laravel()->ignoring([
    'App\Authorization\Permission',
    'App\Authorization\PermissionGroup',
    'App\Authorization\Role',
    'App\Tenancy\Exceptions',
    'App\Tenancy\Auth\UnscopedTwoFactorLoginRequest',
    'App\Tenancy\Auth\UnscopedPasskey',
]);
```

- [ ] **Step 8b: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Authorization tests/Feature/LangFilesTest.php tests/Arch`
Expected: PASS. If `seeds the catalogue … once per test process` fails because nothing was seeded, check that `CanConfigureMigrationCommands::seeder()` picks up the attribute on the abstract parent (`vendor/laravel/framework/src/Illuminate/Foundation/Testing/Traits/CanConfigureMigrationCommands.php`); if it does not, use `protected string $seeder = EssentialsSeeder::class;` instead.

- [ ] **Step 9: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "feat: add the permission catalogue and permissions:sync

- Permission, PermissionGroup and Role enums with nl/en labels
- idempotent SyncPermissions: upsert, prune, template sync, super-only strip (W6, W7)
- EssentialsSeeder, seeded once per test process

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `SetSuperAdmin` and super-admin badge repair

**Files:**
- Create: `app/Authorization/SetSuperAdmin.php`
- Modify: `app/Authorization/SyncPermissions.php`, `database/seeders/DatabaseSeeder.php`, `lang/nl.json`, `lang/en.json`
- Test: `tests/Feature/Authorization/SetSuperAdminTest.php`

**Interfaces:**
- Consumes: `PermissionsTeam`, `Role`, `SyncPermissions`, `SyncResult` (Tasks 2–3), `UserStatus` (Task 1).
- Produces: `App\Authorization\SetSuperAdmin::handle(User $user, bool $isSuperAdmin): void` (throws `ValidationException` with key `is_super_admin` when it would remove the last active super admin); `SyncResult::$badgesRepaired` is now filled.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Authorization/SetSuperAdminTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.6, §6.3 */

use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Authorization\SyncPermissions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** @return array<int, mixed> */
function badgeRows(User $user): array
{
    return DB::table('model_has_roles')
        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->where('roles.name', Role::SuperAdmin->value)
        ->where('model_has_roles.model_id', $user->id)
        ->pluck('model_has_roles.team_id')
        ->all();
}

it('grants the flag and the badge with a null team', function (): void {
    $user = User::factory()->create();

    app(SetSuperAdmin::class)->handle($user, true);

    expect($user->fresh()?->isSuperAdmin())->toBeTrue()
        ->and(badgeRows($user))->toBe([null]);
});

it('revokes the flag and the badge when another active super admin remains', function (): void {
    $keeper = User::factory()->superAdmin()->create();
    app(SetSuperAdmin::class)->handle($keeper, true);
    $leaver = User::factory()->superAdmin()->create();
    app(SetSuperAdmin::class)->handle($leaver, true);

    app(SetSuperAdmin::class)->handle($leaver, false);

    expect($leaver->fresh()?->isSuperAdmin())->toBeFalse()
        ->and(badgeRows($leaver))->toBe([]);
});

it('refuses to revoke the last active super admin', function (): void {
    $last = User::factory()->superAdmin()->create();
    app(SetSuperAdmin::class)->handle($last, true);
    User::factory()->superAdmin()->disabled()->create();
    User::factory()->superAdmin()->create()->delete();

    expect(fn () => app(SetSuperAdmin::class)->handle($last, false))
        ->toThrow(ValidationException::class);

    expect($last->fresh()?->isSuperAdmin())->toBeTrue()
        ->and(badgeRows($last))->toBe([null]);
});

it('repairs badge drift in permissions:sync', function (): void {
    $flaggedWithoutBadge = User::factory()->superAdmin()->create();
    $badgeWithoutFlag = User::factory()->create();
    $badgeWithTeam = User::factory()->superAdmin()->create();
    $badgeId = DB::table('roles')->whereNull('team_id')->where('name', Role::SuperAdmin->value)->value('id');
    DB::table('model_has_roles')->insert([
        ['role_id' => $badgeId, 'model_type' => $badgeWithoutFlag->getMorphClass(), 'model_id' => $badgeWithoutFlag->id, 'team_id' => null],
        ['role_id' => $badgeId, 'model_type' => $badgeWithTeam->getMorphClass(), 'model_id' => $badgeWithTeam->id, 'team_id' => $badgeWithoutFlag->organisation_id],
    ]);

    $result = app(SyncPermissions::class)->handle();

    expect($result->badgesRepaired)->toBe(4)
        ->and(badgeRows($flaggedWithoutBadge))->toBe([null])
        ->and(badgeRows($badgeWithoutFlag))->toBe([])
        ->and(badgeRows($badgeWithTeam))->toBe([null]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Authorization/SetSuperAdminTest.php`
Expected: FAIL — `Class "App\Authorization\SetSuperAdmin" not found`.

- [ ] **Step 3: Implement `SetSuperAdmin`**

`app/Authorization/SetSuperAdmin.php`:

```php
<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of users.is_super_admin (§6.3). Sets the flag and attaches or detaches the
 * super_admin badge (null team) in one transaction, and never removes the last active,
 * non-deleted super admin. Authorization is the caller's job (policy ability updateSuperAdmin);
 * console commands and seeders call it directly.
 */
final class SetSuperAdmin
{
    /** @throws ValidationException */
    public function handle(User $user, bool $isSuperAdmin): void
    {
        DB::transaction(function () use ($user, $isSuperAdmin): void {
            if (! $isSuperAdmin && $user->isSuperAdmin()) {
                $this->ensureAnotherActiveSuperAdmin($user);
            }

            $user->forceFill(['is_super_admin' => $isSuperAdmin])->save();

            PermissionsTeam::run(null, function () use ($user, $isSuperAdmin): void {
                $user->unsetRelation('roles');

                if ($isSuperAdmin) {
                    $user->assignRole(Role::SuperAdmin->value);
                } else {
                    $user->removeRole(Role::SuperAdmin->value);
                }

                $user->unsetRelation('roles');
            });
        });
    }

    /** @throws ValidationException */
    private function ensureAnotherActiveSuperAdmin(User $user): void
    {
        // pluck() instead of count(): PostgreSQL refuses FOR UPDATE with aggregates.
        $others = DB::table('users')
            ->where('is_super_admin', true)
            ->where('status', UserStatus::Active->value)
            ->whereNull('deleted_at')
            ->where('id', '!=', $user->id)
            ->lockForUpdate()
            ->pluck('id');

        if ($others->isEmpty()) {
            throw ValidationException::withMessages([
                'is_super_admin' => __('At least one active super admin must remain.'),
            ]);
        }
    }
}
```

Add `"At least one active super admin must remain."` to both lang files (nl: `"Er moet minstens één actieve superbeheerder overblijven."`).

- [ ] **Step 4: Repair badge drift in `SyncPermissions`**

In `app/Authorization/SyncPermissions.php`:
1. Add `use App\Models\User;`.
2. In `handle()`, replace the `return new SyncResult(..., 0);` line inside the transaction with:

```php
            $badgesRepaired = $this->repairSuperAdminBadges();

            return new SyncResult($created, $pruned, $organisationAdminChanged, $superOnlyDetached, $badgesRepaired);
```

3. Add the method:

```php
    /**
     * The badge mirrors users.is_super_admin (§6.3): attached with a null team to every flagged
     * user, removed from everyone else and from any non-null team.
     */
    private function repairSuperAdminBadges(): int
    {
        $badgeId = $this->systemRole(Role::SuperAdmin)->getKey();
        $morphClass = new User()->getMorphClass();
        $flagged = DB::table('users')->where('is_super_admin', true)->pluck('id')->map(fn (mixed $id): int => (int) $id);

        $removed = DB::table('model_has_roles')
            ->where('role_id', $badgeId)
            ->where('model_type', $morphClass)
            ->where(fn ($query) => $query->whereNotNull('team_id')->orWhereNotIn('model_id', $flagged->all()))
            ->delete();

        $present = DB::table('model_has_roles')
            ->where('role_id', $badgeId)
            ->where('model_type', $morphClass)
            ->pluck('model_id')
            ->map(fn (mixed $id): int => (int) $id);

        $missing = $flagged->diff($present)->values();

        DB::table('model_has_roles')->insert($missing->map(fn (int $id): array => [
            'role_id' => $badgeId,
            'model_type' => $morphClass,
            'model_id' => $id,
            'team_id' => null,
        ])->all());

        return $removed + $missing->count();
    }
```

`badgesRepaired` counts rows changed: in the test, two rows are removed (`badgeWithoutFlag`; `badgeWithTeam`'s team row) and two inserted (`flaggedWithoutBadge`; `badgeWithTeam` with a null team), so it is 4.

- [ ] **Step 5: Seed a super admin in the development seeder**

Replace `database/seeders/DatabaseSeeder.php::run()` with (keep `use WithoutModelEvents;`):

```php
    public function run(SetSuperAdmin $setSuperAdmin): void
    {
        $this->call(EssentialsSeeder::class);

        // Temporary development login until the M6 setup engine and demo seeder exist.
        $user = User::factory()->superAdmin()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $setSuperAdmin->handle($user, true);
    }
```

with `use App\Authorization\SetSuperAdmin;`.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Authorization`
Expected: PASS.

- [ ] **Step 7: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: add SetSuperAdmin with the last-super-admin guard

- flag and badge change in one transaction, badge repaired by permissions:sync
- development seeder logs in as a super admin until M6

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Tenancy core — current tenant, scope, trait and unscoped user provider

**Files:**
- Create: `config/tenancy.php`, `app/Tenancy/CurrentTenant.php`, `app/Tenancy/CentralContext.php`, `app/Tenancy/Facades/Tenant.php`, `app/Tenancy/Events/TenantResolved.php`, `app/Tenancy/Events/TenantForgotten.php`, `app/Tenancy/Listeners/SetPermissionsTeam.php`, `app/Tenancy/Scopes/TenantScope.php`, `app/Tenancy/Concerns/BelongsToOrganisation.php`, `app/Tenancy/Exceptions/TenantNotResolvedException.php`, `app/Tenancy/Auth/UnscopedUserProvider.php`, `app/Providers/TenancyServiceProvider.php`
- Modify: `bootstrap/providers.php`, `config/auth.php`, `app/Models/User.php`
- Test: `tests/Feature/Tenancy/CurrentTenantTest.php`, `tests/Feature/Tenancy/TenantScopeTest.php`

**Interfaces:**
- Consumes: `Organisation`, `User` (Task 1), `PermissionsTeam` (Task 2).
- Produces:
  - `App\Tenancy\CurrentTenant` (scoped): `current(): ?Organisation`, `check(): bool`, `set(Organisation $organisation): void` (dispatches `TenantResolved`), `forget(): void` (dispatches `TenantForgotten`), `run(Organisation $organisation, Closure $callback): mixed` (restores the previous tenant in `finally`), `bypass(Closure $callback): mixed` (re-entrant), `isBypassed(): bool`.
  - Facade `App\Tenancy\Facades\Tenant` proxying all of the above.
  - `App\Tenancy\CentralContext` (plain singleton): `enter(): void`, `leave(): void`, `active(): bool` — entered on `CommandStarting`, left on `CommandFinished`; survives the queue worker's scoped reset.
  - Events `TenantResolved(public Organisation $organisation)`, `TenantForgotten`.
  - `App\Tenancy\Scopes\TenantScope`; trait `App\Tenancy\Concerns\BelongsToOrganisation` (`organisation(): BelongsTo`, `mayExistWithoutOrganisation(): bool`).
  - `App\Tenancy\Exceptions\TenantNotResolvedException` (`forQuery(string $model)`, `forCreating(string $model)`).
  - Auth provider driver `unscoped-eloquent` (`App\Tenancy\Auth\UnscopedUserProvider`).
  - Config `tenancy.apex_domain`, `tenancy.admin_subdomain`, `tenancy.strict` (default **false** until Task 15), `tenancy.slug_cache_ttl`, `tenancy.tenant_models`, `tenancy.reserved_slugs`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Tenancy/CurrentTenantTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §6.2 */

use App\Authorization\PermissionsTeam;
use App\Models\Organisation;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Events\TenantForgotten;
use App\Tenancy\Events\TenantResolved;
use App\Tenancy\Facades\Tenant;
use Illuminate\Support\Facades\Event;

it('binds an organisation and sets the permissions team', function (): void {
    $organisation = Organisation::factory()->create();
    $resolved = [];
    Event::listen(TenantResolved::class, function (TenantResolved $event) use (&$resolved): void {
        $resolved[] = $event->organisation->id;
    });

    Tenant::set($organisation);

    expect(Tenant::current()?->is($organisation))->toBeTrue()
        ->and(Tenant::check())->toBeTrue()
        ->and(PermissionsTeam::current())->toBe($organisation->id)
        ->and($resolved)->toBe([$organisation->id]);
});

it('forgets the tenant and resets the permissions team', function (): void {
    Tenant::set(Organisation::factory()->create());
    $forgotten = 0;
    Event::listen(TenantForgotten::class, function () use (&$forgotten): void {
        $forgotten++;
    });

    Tenant::forget();

    expect(Tenant::current())->toBeNull()
        ->and(PermissionsTeam::current())->toBeNull()
        ->and($forgotten)->toBe(1);
});

it('runs a callback for an organisation and restores the previous tenant', function (): void {
    [$outer, $inner] = Organisation::factory()->count(2)->create()->all();
    Tenant::set($outer);

    $seen = Tenant::run($inner, fn (): ?int => Tenant::current()?->id);

    expect($seen)->toBe($inner->id)
        ->and(Tenant::current()?->id)->toBe($outer->id)
        ->and(PermissionsTeam::current())->toBe($outer->id);
});

it('forgets the tenant after run when none was bound, also on exceptions', function (): void {
    $organisation = Organisation::factory()->create();

    expect(fn () => Tenant::run($organisation, fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class);

    expect(Tenant::check())->toBeFalse()->and(PermissionsTeam::current())->toBeNull();
});

it('nests bypass and restores it on exceptions', function (): void {
    $depths = Tenant::bypass(fn (): array => [Tenant::isBypassed(), Tenant::bypass(fn (): bool => Tenant::isBypassed()), Tenant::isBypassed()]);

    expect($depths)->toBe([true, true, true])->and(Tenant::isBypassed())->toBeFalse();

    expect(fn () => Tenant::bypass(fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class);
    expect(Tenant::isBypassed())->toBeFalse();
});

it('proxies the scoped instance', function (): void {
    $organisation = Organisation::factory()->create();

    app(CurrentTenant::class)->set($organisation);

    expect(Tenant::current()?->id)->toBe($organisation->id);
});
```

`tests/Feature/Tenancy/TenantScopeTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.1, §6.2 */

use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\CentralContext;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use App\Tenancy\Facades\Tenant;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/** @return array{0: Organisation, 1: User, 2: User} */
function twoTenantsWithMembers(): array
{
    [$first, $second] = Organisation::factory()->count(2)->create()->all();

    return [$first, User::factory()->for($first)->create(), User::factory()->for($second)->create()];
}

it('scopes tenant models to the bound organisation', function (): void {
    [$first, $mine] = twoTenantsWithMembers();
    User::factory()->superAdmin()->create();

    expect(Tenant::run($first, fn (): array => User::query()->pluck('id')->all()))->toBe([$mine->id]);
});

it('shows every row inside a bypass', function (): void {
    [$first] = twoTenantsWithMembers();

    expect(Tenant::run($first, fn (): int => Tenant::bypass(fn (): int => User::query()->count())))->toBe(2);
});

it('does not scope without a tenant when strict mode is off', function (): void {
    config(['tenancy.strict' => false]);
    twoTenantsWithMembers();

    expect(User::query()->count())->toBe(2);
});

it('throws in strict mode when no tenant is bound', function (): void {
    config(['tenancy.strict' => true]);

    expect(fn () => User::query()->count())->toThrow(TenantNotResolvedException::class);
});

it('runs centrally in the console context even in strict mode', function (): void {
    config(['tenancy.strict' => true]);
    twoTenantsWithMembers();

    event(new CommandStarting('list', new ArrayInput([]), new NullOutput));

    expect(app(CentralContext::class)->active())->toBeTrue()
        ->and(User::query()->count())->toBe(2);
});

it('leaves the central context when the command finishes', function (): void {
    config(['tenancy.strict' => true]);

    event(new CommandStarting('list', new ArrayInput([]), new NullOutput));
    event(new CommandFinished('list', new ArrayInput([]), new NullOutput, 0));

    expect(fn () => User::query()->count())->toThrow(TenantNotResolvedException::class);
});

it('fills organisation_id from the bound tenant when creating', function (): void {
    $organisation = Organisation::factory()->create();

    $user = Tenant::run($organisation, fn (): User => User::factory()->create(['organisation_id' => null]));

    expect($user->organisation_id)->toBe($organisation->id);
});

it('refuses to create a tenant row without an organisation (W8)', function (): void {
    expect(fn () => User::factory()->create(['organisation_id' => null]))
        ->toThrow(TenantNotResolvedException::class);
});

it('lets super admins exist without an organisation', function (): void {
    expect(User::factory()->superAdmin()->create()->organisation_id)->toBeNull();
});

it('finds users of any organisation through the auth provider', function (): void {
    [$first, , $other] = twoTenantsWithMembers();

    $found = Tenant::run($first, fn (): mixed => Auth::createUserProvider('users')?->retrieveById($other->id));

    expect(config('auth.providers.users.driver'))->toBe('unscoped-eloquent')
        ->and($found)->toBeInstanceOf(User::class);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Tenancy`
Expected: FAIL — `Class "App\Tenancy\Facades\Tenant" not found`.

- [ ] **Step 3: Add the tenancy config**

`config/tenancy.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;

$appUrlHost = parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST);

return [

    /*
    | The apex domain: {slug}.{apex} are tenant hosts; {apex} and {admin}.{apex} are central.
    | Defaults to the APP_URL host so a fresh install needs no extra setting (§4.1).
    */
    'apex_domain' => env('TENANCY_APEX_DOMAIN') ?: (is_string($appUrlHost) && $appUrlHost !== '' ? $appUrlHost : 'localhost'),

    'admin_subdomain' => env('TENANCY_ADMIN_SUBDOMAIN', 'admin'),

    /*
    | Strict mode (§6.2): querying a tenant model with no tenant bound throws, except in the
    | console (central context) and inside Tenant::bypass().
    */
    'strict' => (bool) env('TENANCY_STRICT', false),

    'slug_cache_ttl' => 3600,

    /*
    | Models with an organisation_id. They use BelongsToOrganisation and SoftDeletes and follow
    | the organisation's soft delete and restore (§4.1).
    */
    'tenant_models' => [
        User::class,
    ],

    'reserved_slugs' => [
        'admin', 'www', 'api', 'app', 'mail', 'smtp', 'ftp', 'static', 'assets', 'cdn', 'docs',
        'help', 'status', 'support', 'dashboard', 'login', 'test',
    ],

];
```

- [ ] **Step 4: Add events, exception and the current tenant**

`app/Tenancy/Events/TenantResolved.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Events;

use App\Models\Organisation;

final readonly class TenantResolved
{
    public function __construct(public Organisation $organisation) {}
}
```

`app/Tenancy/Events/TenantForgotten.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Events;

final readonly class TenantForgotten {}
```

`app/Tenancy/Exceptions/TenantNotResolvedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use RuntimeException;

final class TenantNotResolvedException extends RuntimeException
{
    public static function forQuery(string $model): self
    {
        return new self("No tenant is bound while querying [{$model}]. Bind one with Tenant::run() or read centrally inside Tenant::bypass().");
    }

    public static function forCreating(string $model): self
    {
        return new self("Cannot create [{$model}] without an organisation: no tenant is bound and no organisation_id was given.");
    }
}
```

`app/Tenancy/CurrentTenant.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Organisation;
use App\Tenancy\Events\TenantForgotten;
use App\Tenancy\Events\TenantResolved;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * The organisation of the current request or job (§6.2). Registered as a scoped singleton,
 * so the queue worker starts every job without one.
 */
final class CurrentTenant
{
    private ?Organisation $organisation = null;

    private int $bypassDepth = 0;

    public function __construct(private readonly Dispatcher $events) {}

    public function current(): ?Organisation
    {
        return $this->organisation;
    }

    public function check(): bool
    {
        return $this->organisation instanceof Organisation;
    }

    public function set(Organisation $organisation): void
    {
        $this->organisation = $organisation;
        $this->events->dispatch(new TenantResolved($organisation));
    }

    public function forget(): void
    {
        $this->organisation = null;
        $this->events->dispatch(new TenantForgotten);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return (TReturn is PendingDispatch ? null : TReturn)
     */
    public function run(Organisation $organisation, Closure $callback): mixed
    {
        $previous = $this->organisation;
        $this->set($organisation);

        try {
            $result = $callback();

            // A PendingDispatch queues in its destructor; destroy it while this tenant is bound (§6.2).
            if ($result instanceof PendingDispatch) {
                unset($result);

                return null;
            }

            return $result;
        } finally {
            if ($previous instanceof Organisation) {
                $this->set($previous);
            } else {
                $this->forget();
            }
        }
    }

    /**
     * Reads tenant models across organisations. Allowed only in the namespaces listed in §6.2
     * (enforced by tests/Arch/TenancyRulesTest.php).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function bypass(Closure $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    public function isBypassed(): bool
    {
        return $this->bypassDepth > 0;
    }
}
```

`app/Tenancy/CentralContext.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * Artisan commands, the scheduler and queue workers run centrally by default (§6.2). A plain
 * singleton, not scoped: the worker's per-job scope reset must not turn strict mode back on.
 * A job that carries a tenant still binds it, and a bound tenant always scopes. Entered on
 * CommandStarting and left on CommandFinished, so an Artisan::call() inside an HTTP request
 * (setup wizard) is central only while the command runs; queue:work and schedule:work stay
 * central until they exit.
 */
final class CentralContext
{
    private int $depth = 0;

    public function enter(): void
    {
        $this->depth++;
    }

    public function leave(): void
    {
        $this->depth = max(0, $this->depth - 1);
    }

    public function active(): bool
    {
        return $this->depth > 0;
    }
}
```

`app/Tenancy/Facades/Tenant.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Facades;

use App\Models\Organisation;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Organisation|null current()
 * @method static bool check()
 * @method static void set(Organisation $organisation)
 * @method static void forget()
 * @method static mixed run(Organisation $organisation, Closure $callback)
 * @method static mixed bypass(Closure $callback)
 * @method static bool isBypassed()
 *
 * @see CurrentTenant
 */
final class Tenant extends Facade
{
    /** The scoped instance is replaced between queue jobs; never hold on to an old one. */
    protected static $cached = false;

    protected static function getFacadeAccessor(): string
    {
        return CurrentTenant::class;
    }
}
```

`app/Tenancy/Listeners/SetPermissionsTeam.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Listeners;

use App\Authorization\PermissionsTeam;
use App\Tenancy\Events\TenantForgotten;
use App\Tenancy\Events\TenantResolved;

final class SetPermissionsTeam
{
    public function handle(TenantResolved|TenantForgotten $event): void
    {
        PermissionsTeam::set($event instanceof TenantResolved ? $event->organisation->id : null);
    }
}
```

- [ ] **Step 5: Add the scope and the trait**

`app/Tenancy/Scopes/TenantScope.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Scopes;

use App\Tenancy\CentralContext;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** @implements Scope<Model> */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenancy = app(CurrentTenant::class);

        if ($tenancy->isBypassed()) {
            return;
        }

        $organisation = $tenancy->current();

        if ($organisation !== null) {
            $builder->where($model->qualifyColumn('organisation_id'), $organisation->getKey());

            return;
        }

        if (config('tenancy.strict') === true && ! app(CentralContext::class)->active()) {
            throw TenantNotResolvedException::forQuery($model::class);
        }
    }
}
```

`app/Tenancy/Concerns/BelongsToOrganisation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Concerns;

use App\Models\Organisation;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use App\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant models (§4.1): scoped to the current organisation and filled with its id on create.
 * Every model using this trait is listed in config('tenancy.tenant_models').
 */
trait BelongsToOrganisation
{
    public static function bootBelongsToOrganisation(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $model): void {
            if ($model->getAttribute('organisation_id') === null) {
                $model->setAttribute('organisation_id', app(CurrentTenant::class)->current()?->getKey());
            }

            if ($model->getAttribute('organisation_id') === null && ! $model->mayExistWithoutOrganisation()) {
                throw TenantNotResolvedException::forCreating($model::class);
            }
        });
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** Central rows (organisation_id NULL) are refused unless the model allows them. */
    public function mayExistWithoutOrganisation(): bool
    {
        return false;
    }
}
```

- [ ] **Step 6: Make User a tenant model**

In `app/Models/User.php`:
1. Add `use App\Tenancy\Concerns\BelongsToOrganisation;` and the trait to the list: `BelongsToOrganisation, HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable`.
2. **Delete** the `organisation()` method added in Task 1 (the trait provides it) and the now-unused `BelongsTo` import.
3. Add:

```php
    /** Super admins are central users without an organisation (§6.3). */
    public function mayExistWithoutOrganisation(): bool
    {
        return $this->is_super_admin === true;
    }
```

- [ ] **Step 7: Add the unscoped user provider**

`app/Tenancy/Auth/UnscopedUserProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use App\Tenancy\Scopes\TenantScope;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Session, remember-me and password-broker lookups find users on every host (§4.1);
 * which host a user may use is decided by the membership middleware and the login rule.
 */
final class UnscopedUserProvider extends EloquentUserProvider
{
    /**
     * @param  Model|null  $model
     * @return Builder<Model>
     */
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->withoutGlobalScope(TenantScope::class);
    }
}
```

In `config/auth.php` change `providers.users.driver` from `'eloquent'` to `'unscoped-eloquent'`.

- [ ] **Step 8: Add the tenancy service provider**

`app/Providers/TenancyServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Tenancy\Auth\UnscopedUserProvider;
use App\Tenancy\CentralContext;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Events\TenantForgotten;
use App\Tenancy\Events\TenantResolved;
use App\Tenancy\Listeners\SetPermissionsTeam;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentTenant::class);
        $this->app->singleton(CentralContext::class);
    }

    public function boot(): void
    {
        Auth::provider('unscoped-eloquent', fn (Application $app, array $config): UnscopedUserProvider => new UnscopedUserProvider(
            $app->make('hash'),
            (string) $config['model'],
        ));

        Event::listen(TenantResolved::class, SetPermissionsTeam::class);
        Event::listen(TenantForgotten::class, SetPermissionsTeam::class);
        Event::listen(CommandStarting::class, function (): void {
            $this->app->make(CentralContext::class)->enter();
        });
        Event::listen(CommandFinished::class, function (): void {
            $this->app->make(CentralContext::class)->leave();
        });
    }
}
```

Add `App\Providers\TenancyServiceProvider::class` to `bootstrap/providers.php` after `AppServiceProvider::class`.

- [ ] **Step 9: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Tenancy`
Expected: PASS.

- [ ] **Step 10: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green (strict mode is still off, so the M1 tests are unaffected).

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -m "feat: add the tenancy core with a strict tenant scope

- scoped CurrentTenant and Tenant facade with run/bypass, team-id listener
- BelongsToOrganisation: scope, organisation_id fill, no central rows (W8)
- unscoped auth provider; console runs in central context

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: `tenant_url()`, hosts and the tenancy testing API

**Files:**
- Create: `app/Tenancy/Hosts.php`, `app/Support/helpers.php`
- Modify: `composer.json`, `phpunit.xml`, `tests/Pest.php`
- Test: `tests/Feature/Tenancy/TenantUrlTest.php`, `tests/Feature/Tenancy/TestingApiTest.php`

**Interfaces:**
- Consumes: `CurrentTenant` (Task 5), `SetSuperAdmin` (Task 4), `Role` (Task 3).
- Produces:
  - `App\Tenancy\Hosts`: `apex(): string`, `admin(): string`, `tenant(string $slug): string`, `origin(string $host): string` (scheme + host + non-default port, no trailing slash), `url(string $host, string $path = '/'): string`.
  - Global `tenant_url(Organisation|string $organisation, string $path = '/'): string`.
  - Test helpers in `tests/Pest.php`: `onTenant(Organisation $organisation): Organisation`, `onApex(): void`, `onAdminHost(): void`, `asSuperAdmin(): User`, `asOrgAdmin(Organisation $organisation): User`, `asMember(Organisation $organisation): User`.

- [ ] **Step 1: Pin the test hosts**

In `phpunit.xml`, inside `<php>`, add next to the existing `APP_ENV` entry:

```xml
        <env name="APP_URL" value="http://localhost"/>
        <env name="TENANCY_APEX_DOMAIN" value="localhost"/>
        <env name="TENANCY_ADMIN_SUBDOMAIN" value="admin"/>
```

(phpunit's values win over `.env`, so tests never depend on the developer's site name.)

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Tenancy/TenantUrlTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.1, §6.2 */

use App\Models\Organisation;
use App\Tenancy\Hosts;
use Illuminate\Http\Request;

it('keeps the scheme and port of the current request', function (): void {
    app()->instance('request', Request::create('https://localhost:8443/anything'));

    expect(tenant_url('acme', '/dashboard'))->toBe('https://acme.localhost:8443/dashboard');
});

it('omits default ports', function (): void {
    app()->instance('request', Request::create('https://localhost/anything'));

    expect(tenant_url('acme'))->toBe('https://acme.localhost/');
});

it('falls back to APP_URL outside a web request', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);

    expect(tenant_url($organisation, 'settings'))->toBe('http://acme.localhost/settings');
});

it('derives the central hosts from the config', function (): void {
    config(['tenancy.apex_domain' => 'Example.test', 'tenancy.admin_subdomain' => 'beheer']);

    expect(Hosts::apex())->toBe('example.test')
        ->and(Hosts::admin())->toBe('beheer.example.test')
        ->and(Hosts::tenant('acme'))->toBe('acme.example.test')
        ->and(Hosts::origin(Hosts::admin()))->toBe('http://beheer.example.test');
});
```

`tests/Feature/Tenancy/TestingApiTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §7.2 */

use App\Authorization\Role;
use App\Models\Organisation;
use App\Tenancy\Facades\Tenant;
use Illuminate\Support\Facades\DB;

it('points route() and the bound tenant at an organisation', function (): void {
    $organisation = onTenant(Organisation::factory()->create(['slug' => 'acme']));

    expect(route('dashboard'))->toBe('http://acme.localhost/dashboard')
        ->and(Tenant::current()?->is($organisation))->toBeTrue();
});

it('points route() at the apex and the admin host', function (): void {
    onTenant(Organisation::factory()->create());

    onApex();
    expect(route('dashboard'))->toBe('http://localhost/dashboard')->and(Tenant::check())->toBeFalse();

    onAdminHost();
    expect(route('dashboard'))->toBe('http://admin.localhost/dashboard');
});

it('acts as an organisation admin on the organisation host', function (): void {
    $organisation = Organisation::factory()->create();

    $admin = asOrgAdmin($organisation);

    expect(auth()->id())->toBe($admin->id)
        ->and(Tenant::current()?->id)->toBe($organisation->id)
        ->and(withPermissionsTeam($organisation->id, fn (): bool => $admin->hasRole(Role::OrganisationAdmin->value)))->toBeTrue();
});

it('acts as a member without roles', function (): void {
    $member = asMember(Organisation::factory()->create());

    expect(auth()->id())->toBe($member->id)
        ->and(DB::table('model_has_roles')->where('model_id', $member->id)->exists())->toBeFalse();
});

it('acts as a super admin with the badge', function (): void {
    $superAdmin = asSuperAdmin();

    expect(auth()->id())->toBe($superAdmin->id)
        ->and($superAdmin->isSuperAdmin())->toBeTrue()
        ->and(DB::table('model_has_roles')->where('model_id', $superAdmin->id)->whereNull('team_id')->exists())->toBeTrue();
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Tenancy/TenantUrlTest.php tests/Feature/Tenancy/TestingApiTest.php`
Expected: FAIL — `Call to undefined function tenant_url()` / `onTenant()`.

- [ ] **Step 4: Implement `Hosts` and `tenant_url()`**

`app/Tenancy/Hosts.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Support\Str;

/**
 * Host names and absolute URLs (§4.1, §6.2). Scheme and port come from the current request;
 * in the console and queue workers Laravel builds that request from APP_URL.
 */
final class Hosts
{
    public static function apex(): string
    {
        return Str::lower((string) config('tenancy.apex_domain'));
    }

    public static function admin(): string
    {
        return Str::lower((string) config('tenancy.admin_subdomain')).'.'.self::apex();
    }

    public static function tenant(string $slug): string
    {
        return Str::lower($slug).'.'.self::apex();
    }

    public static function origin(string $host): string
    {
        $request = request();
        $scheme = $request->getScheme();
        $port = (int) $request->getPort();
        $defaultPort = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.$host.($port !== 0 && $port !== $defaultPort ? ':'.$port : '');
    }

    public static function url(string $host, string $path = '/'): string
    {
        return self::origin($host).'/'.ltrim($path, '/');
    }
}
```

`app/Support/helpers.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Organisation;
use App\Tenancy\Hosts;

if (! function_exists('tenant_url')) {
    /** Absolute URL on an organisation's subdomain, keeping the request's scheme and port (§6.2). */
    function tenant_url(Organisation|string $organisation, string $path = '/'): string
    {
        $slug = $organisation instanceof Organisation ? $organisation->slug : $organisation;

        return Hosts::url(Hosts::tenant($slug), $path);
    }
}
```

Add it to `composer.json` `autoload.files` (after `app/Authorization/helpers.php`) and run `composer dump-autoload`.

- [ ] **Step 5: Add the testing API**

Append to `tests/Pest.php` (add the `use` statements at the top of the file):

```php
use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Hosts;
use Illuminate\Support\Facades\URL;

use function Pest\Laravel\actingAs;

/*
| Tenancy testing API (§7.2). Each helper sets the URL origin, so route() and $this->get()
| target that host, and binds or forgets the tenant for code that runs without middleware
| (Livewire::test(), direct model calls).
*/

function onTenant(Organisation $organisation): Organisation
{
    URL::useOrigin(Hosts::origin(Hosts::tenant($organisation->slug)));
    app(CurrentTenant::class)->set($organisation);

    return $organisation;
}

function onApex(): void
{
    URL::useOrigin(Hosts::origin(Hosts::apex()));
    app(CurrentTenant::class)->forget();
}

function onAdminHost(): void
{
    URL::useOrigin(Hosts::origin(Hosts::admin()));
    app(CurrentTenant::class)->forget();
}

function asSuperAdmin(): User
{
    $user = User::factory()->superAdmin()->create();
    app(SetSuperAdmin::class)->handle($user, true);
    actingAs($user);

    return $user;
}

function asOrgAdmin(Organisation $organisation): User
{
    $user = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn () => $user->assignRole(Role::OrganisationAdmin->value));
    $user->unsetRelation('roles');
    actingAs($user);
    onTenant($organisation);

    return $user;
}

function asMember(Organisation $organisation): User
{
    $user = User::factory()->for($organisation)->create();
    actingAs($user);
    onTenant($organisation);

    return $user;
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Tenancy`
Expected: PASS.

- [ ] **Step 7: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green. `NameLeakTest` scans `phpunit.xml`; `localhost` is not a site name, so it passes.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: add tenant_url() and the tenancy testing API

- Hosts builds apex, admin and tenant URLs with the request's scheme and port
- onTenant/onApex/onAdminHost and asSuperAdmin/asOrgAdmin/asMember helpers
- phpunit pins the test apex to localhost

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Subdomain resolver, friendly tenant 404 and slug cache

**Files:**
- Create: `app/Tenancy/Contracts/TenantResolver.php`, `app/Tenancy/Resolvers/SubdomainResolver.php`, `app/Tenancy/Exceptions/TenantNotFoundException.php`, `app/Tenancy/Http/Middleware/InitializeTenancy.php`, `app/Tenancy/Observers/OrganisationObserver.php`, `resources/views/errors/tenant-not-found.blade.php`
- Modify: `app/Providers/TenancyServiceProvider.php`, `bootstrap/app.php`, `app/Models/Organisation.php`, `lang/nl.json`, `lang/en.json`
- Test: `tests/Feature/Tenancy/ResolveTenantTest.php`

**Interfaces:**
- Consumes: `CurrentTenant`, `Hosts`, `tenant_url()` (Tasks 5–6).
- Produces: `App\Tenancy\Contracts\TenantResolver::resolve(Request $request): ?Organisation` (null = central host; throws `TenantNotFoundException` for unknown, archived, reserved or foreign hosts); `SubdomainResolver::cacheKey(string $slug): string`; middleware `InitializeTenancy` prepended to the `web` group; `OrganisationObserver` (slug cache; Task 11 adds the cascade).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Tenancy/ResolveTenantTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.1, §6.2 */

use App\Authorization\PermissionsTeam;
use App\Models\Organisation;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\Hosts;
use App\Tenancy\Resolvers\SubdomainResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware('web')->get('/_probe', fn (): array => [
        'tenant' => Tenant::current()?->slug,
        'team' => PermissionsTeam::current(),
    ]);
});

it('resolves the organisation from the subdomain and sets the team', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);

    $this->getJson(tenant_url($organisation, '/_probe'))
        ->assertOk()
        ->assertExactJson(['tenant' => 'acme', 'team' => $organisation->id]);
});

it('treats the apex and the admin host as central', function (string $host): void {
    Organisation::factory()->create(['slug' => 'admin']);

    $this->getJson(Hosts::url($host === 'apex' ? Hosts::apex() : Hosts::admin(), '/_probe'))
        ->assertOk()
        ->assertExactJson(['tenant' => null, 'team' => null]);
})->with(['apex', 'admin']);

it('forgets a previously bound tenant on a central host', function (): void {
    $organisation = Organisation::factory()->create();
    $this->getJson(tenant_url($organisation, '/_probe'))->assertOk();

    $this->getJson(Hosts::url(Hosts::apex(), '/_probe'))->assertExactJson(['tenant' => null, 'team' => null]);
});

it('shows a friendly 404 with a link to the apex for unknown hosts', function (string $url): void {
    Organisation::factory()->create(['slug' => 'www']);
    Organisation::factory()->create(['slug' => 'archived'])->delete();

    $this->get($url)
        ->assertNotFound()
        ->assertSee('Organisation not found')
        ->assertSee('href="http://localhost/"', false);
})->with([
    'unknown slug' => 'http://nobody.localhost/_probe',
    'archived organisation' => 'http://archived.localhost/_probe',
    'reserved slug' => 'http://www.localhost/_probe',
    'nested subdomain' => 'http://a.b.localhost/_probe',
    'too short' => 'http://ab.localhost/_probe',
    'foreign host' => 'http://example.com/_probe',
]);

it('caches the slug lookup and forgets it on rename', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'before']);
    $this->getJson(tenant_url('before', '/_probe'))->assertOk();
    expect(Cache::get(SubdomainResolver::cacheKey('before')))->toBe($organisation->id);

    $organisation->update(['slug' => 'after']);

    expect(Cache::has(SubdomainResolver::cacheKey('before')))->toBeFalse();
    $this->get(tenant_url('before', '/_probe'))->assertNotFound();
    $this->getJson(tenant_url('after', '/_probe'))->assertExactJson(['tenant' => 'after', 'team' => $organisation->id]);
});

it('never binds a stale cached id to another slug', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'after']);
    Cache::put(SubdomainResolver::cacheKey('before'), $organisation->id, 60);

    $this->get(tenant_url('before', '/_probe'))->assertNotFound();
});

it('does not cache unknown slugs', function (): void {
    $this->get(tenant_url('nobody', '/_probe'))->assertNotFound();

    expect(Cache::has(SubdomainResolver::cacheKey('nobody')))->toBeFalse();
});

it('forgets the cached slug when the organisation is archived', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'gone']);
    $this->getJson(tenant_url('gone', '/_probe'))->assertOk();

    $organisation->delete();

    $this->get(tenant_url('gone', '/_probe'))->assertNotFound();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Tenancy/ResolveTenantTest.php`
Expected: FAIL — tenant hosts return `tenant: null` and unknown hosts return 200.

- [ ] **Step 3: Implement the resolver**

`app/Tenancy/Contracts/TenantResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Contracts;

use App\Models\Organisation;
use App\Tenancy\Exceptions\TenantNotFoundException;
use Illuminate\Http\Request;

interface TenantResolver
{
    /**
     * The organisation for a tenant host, or null for a central host.
     *
     * @throws TenantNotFoundException for unknown, archived, reserved or foreign hosts
     */
    public function resolve(Request $request): ?Organisation;
}
```

`app/Tenancy/Resolvers/SubdomainResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Resolvers;

use App\Models\Organisation;
use App\Tenancy\Contracts\TenantResolver;
use App\Tenancy\Exceptions\TenantNotFoundException;
use App\Tenancy\Hosts;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * {slug}.{apex} → organisation (§6.2). Only one label of 3–63 characters [a-z0-9-] without
 * a leading or trailing hyphen; reserved slugs and the admin subdomain are never tenants.
 * The cache stores ids only (cache.serializable_classes is false).
 */
final readonly class SubdomainResolver implements TenantResolver
{
    public function __construct(private Cache $cache) {}

    public static function cacheKey(string $slug): string
    {
        return 'tenancy.slug.'.$slug;
    }

    public function resolve(Request $request): ?Organisation
    {
        $host = Str::lower($request->getHost());

        if ($host === Hosts::apex() || $host === Hosts::admin()) {
            return null;
        }

        $slug = $this->slugFrom($host) ?? throw new TenantNotFoundException;

        // Verify the cached id still carries this slug, and never cache misses (no cache write per
        // random subdomain).
        $key = self::cacheKey($slug);
        $id = $this->cache->get($key);
        $organisation = is_int($id) ? Organisation::query()->find($id) : null;

        if ($organisation === null || $organisation->slug !== $slug) {
            $this->cache->forget($key);
            $organisation = Organisation::query()->where('slug', $slug)->first() ?? throw new TenantNotFoundException;
            $this->cache->put($key, $organisation->id, (int) config('tenancy.slug_cache_ttl'));
        }

        return $organisation;
    }

    private function slugFrom(string $host): ?string
    {
        $suffix = '.'.Hosts::apex();

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $slug = substr($host, 0, -strlen($suffix));

        if (preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $slug) !== 1) {
            return null;
        }

        /** @var list<string> $reserved */
        $reserved = config('tenancy.reserved_slugs', []);

        return in_array($slug, $reserved, true) ? null : $slug;
    }
}
```

- [ ] **Step 4: Add the not-found exception and its page**

`app/Tenancy/Exceptions/TenantNotFoundException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use App\Tenancy\Hosts;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** Rendered as the friendly tenant 404 with a link to the apex (§4.1). Not reported. */
final class TenantNotFoundException extends RuntimeException
{
    public function render(Request $request): Response
    {
        return response()->view('errors.tenant-not-found', [
            'apexUrl' => Hosts::url(Hosts::apex()),
        ], 404);
    }
}
```

`resources/views/errors/tenant-not-found.blade.php` (self-contained: no Vite, no session, no database; M5 restyles all error pages):

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Organisation not found') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: ui-sans-serif, system-ui, sans-serif; background: #fafafa; color: #171717; }
        main { max-width: 28rem; padding: 2rem; text-align: center; }
        h1 { font-size: 1.25rem; }
        a { color: inherit; font-weight: 600; }
        @media (prefers-color-scheme: dark) { body { background: #171717; color: #fafafa; } }
    </style>
</head>
<body>
    <main>
        <h1>{{ __('Organisation not found') }}</h1>
        <p>{{ __('There is no organisation at this address. Check the link or go to the main site.') }}</p>
        <p><a href="{{ $apexUrl }}">{{ __('Go to the main site') }}</a></p>
    </main>
</body>
</html>
```

Add to both lang files:

| Key | nl |
|---|---|
| `Go to the main site` | `Naar de hoofdsite` |
| `Organisation not found` | `Organisatie niet gevonden` |
| `There is no organisation at this address. Check the link or go to the main site.` | `Er is geen organisatie op dit adres. Controleer de link of ga naar de hoofdsite.` |

- [ ] **Step 5: Add the middleware and wire it**

`app/Tenancy/Http/Middleware/InitializeTenancy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Middleware;

use App\Tenancy\Contracts\TenantResolver;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** First middleware of the web group (also for Livewire updates): binds or forgets the tenant. */
final readonly class InitializeTenancy
{
    public function __construct(private TenantResolver $resolver) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(CurrentTenant::class);
        $organisation = $this->resolver->resolve($request);

        if ($organisation !== null) {
            $tenant->set($organisation);
        } else {
            $tenant->forget();
        }

        return $next($request);
    }
}
```

In `app/Providers/TenancyServiceProvider.php::register()` add:

```php
        $this->app->bind(TenantResolver::class, SubdomainResolver::class);
```

(with `use App\Tenancy\Contracts\TenantResolver;` and `use App\Tenancy\Resolvers\SubdomainResolver;`).

In `bootstrap/app.php`:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [InitializeTenancy::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([TenantNotFoundException::class]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
```

with `use App\Tenancy\Exceptions\TenantNotFoundException;` and `use App\Tenancy\Http\Middleware\InitializeTenancy;`.

- [ ] **Step 6: Invalidate the slug cache**

`app/Tenancy/Observers/OrganisationObserver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Observers;

use App\Models\Organisation;
use App\Tenancy\Resolvers\SubdomainResolver;
use Illuminate\Support\Facades\Cache;

final class OrganisationObserver
{
    public function saved(Organisation $organisation): void
    {
        $this->forgetSlugs($organisation);
    }

    public function deleted(Organisation $organisation): void
    {
        $this->forgetSlugs($organisation);
    }

    public function restored(Organisation $organisation): void
    {
        $this->forgetSlugs($organisation);
    }

    public function forceDeleted(Organisation $organisation): void
    {
        $this->forgetSlugs($organisation);
    }

    /** In "saved" the original still holds the old slug, so a rename forgets both. */
    private function forgetSlugs(Organisation $organisation): void
    {
        $slugs = array_unique(array_filter([$organisation->slug, $organisation->getOriginal('slug')], is_string(...)));

        foreach ($slugs as $slug) {
            Cache::forget(SubdomainResolver::cacheKey($slug));
        }
    }
}
```

Add `#[ObservedBy(OrganisationObserver::class)]` to `app/Models/Organisation.php` (imports `Illuminate\Database\Eloquent\Attributes\ObservedBy`, `App\Tenancy\Observers\OrganisationObserver`).

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Tenancy`
Expected: PASS.

- [ ] **Step 8: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green. The M1 tests run on `localhost`, the apex, so they stay central.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: resolve the organisation from the subdomain

- SubdomainResolver behind a TenantResolver contract, slug cache with invalidation
- friendly tenant 404 linking to the apex for unknown, archived or reserved hosts
- InitializeTenancy first in the web group

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Membership middleware and the M1 tests on tenant hosts

**Files:**
- Create: `app/Tenancy/Http/Middleware/VerifyTenantMembership.php`
- Modify: `bootstrap/app.php`, `app/Models/User.php`, `lang/nl.json`, `lang/en.json`, `tests/Feature/Auth/AuthenticationTest.php`, `tests/Feature/Auth/PasswordConfirmationTest.php`, `tests/Feature/Auth/PasswordResetTest.php`, `tests/Feature/Auth/TwoFactorChallengeTest.php`, `tests/Feature/Auth/InviteOnlyTest.php`, `tests/Feature/DashboardTest.php`, `tests/Feature/Settings/ProfileUpdateTest.php`, `tests/Feature/Settings/SecurityTest.php`
- Test: `tests/Feature/Tenancy/MembershipTest.php`

**Interfaces:**
- Consumes: `Tenant` (Task 5), testing API (Task 6), `UserStatus` (Task 1).
- Produces: `User::belongsToCurrentHost(): bool`; middleware `VerifyTenantMembership` appended to the `web` group; flash key `status` with the reason.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Tenancy/MembershipTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.1 */

use App\Models\Organisation;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('lets a member in on their own organisation host', function (): void {
    asMember(Organisation::factory()->create());

    $this->get(route('dashboard'))->assertOk();
    $this->assertAuthenticated();
});

it('logs a member out on another organisation host', function (): void {
    asMember(Organisation::factory()->create());
    onTenant(Organisation::factory()->create());

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your account does not have access to this address. Sign in at your own organisation.');
    $this->assertGuest();
});

it('logs a member out on the central hosts', function (string $host): void {
    asMember(Organisation::factory()->create());
    $host === 'apex' ? onApex() : onAdminHost();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
})->with(['apex', 'admin']);

it('logs out users that are not active', function (string $state): void {
    $organisation = Organisation::factory()->create();
    actingAs(User::factory()->for($organisation)->{$state}()->create());
    onTenant($organisation);

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your account is not active. Contact your administrator.');
    $this->assertGuest();
})->with(['pending', 'disabled']);

it('lets an active super admin in on every host', function (string $host): void {
    asSuperAdmin();
    match ($host) {
        'tenant' => onTenant(Organisation::factory()->create()),
        'apex' => onApex(),
        default => onAdminHost(),
    };

    $this->get(route('dashboard'))->assertOk();
})->with(['tenant', 'apex', 'admin']);

it('logs out a disabled super admin', function (): void {
    actingAs(User::factory()->superAdmin()->disabled()->create());
    onAdminHost();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('uses host-only session cookies', function (): void {
    expect(config('session.domain'))->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Tenancy/MembershipTest.php`
Expected: FAIL — members stay logged in on foreign hosts.

- [ ] **Step 3: Implement the host rule and the middleware**

In `app/Models/User.php` add (import `App\Tenancy\Facades\Tenant`):

```php
    /** Super admins belong on every host; members only on their organisation's host (§4.1). */
    public function belongsToCurrentHost(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $organisation = Tenant::current();

        return $organisation !== null && $this->organisation_id === $organisation->id;
    }
```

`app/Tenancy/Http/Middleware/VerifyTenantMembership.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs out inactive users and users on a host they do not belong to, with a reason (§4.1).
 * M4 adds the impersonation case (end the impersonation instead of logging out).
 */
final class VerifyTenantMembership
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ($user->isActive() && $user->belongsToCurrentHost())) {
            return $next($request);
        }

        $reason = $user->isActive()
            ? __('Your account does not have access to this address. Sign in at your own organisation.')
            : __('Your account is not active. Contact your administrator.');

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', $reason);
    }
}
```

In `bootstrap/app.php` extend the middleware closure:

```php
        $middleware->web(prepend: [InitializeTenancy::class], append: [VerifyTenantMembership::class]);
```

(import `App\Tenancy\Http\Middleware\VerifyTenantMembership`).

Add to both lang files:

| Key | nl |
|---|---|
| `Your account does not have access to this address. Sign in at your own organisation.` | `Je account heeft geen toegang tot dit adres. Log in bij je eigen organisatie.` |
| `Your account is not active. Contact your administrator.` | `Je account is niet actief. Neem contact op met je beheerder.` |

- [ ] **Step 4: Run the new tests, then the M1 suite**

Run: `php artisan test --compact tests/Feature/Tenancy/MembershipTest.php`
Expected: PASS.

Run: `php artisan test --compact tests/Feature/Auth tests/Feature/Settings tests/Feature/DashboardTest.php`
Expected: FAIL — factory users belong to an organisation and are now logged out on `localhost` (the apex).

- [ ] **Step 5: Move the M1 tests onto the user's tenant host**

In each test below that creates a user and then authenticates, sends a request or runs a Livewire call, insert `onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));` on the line right after the user is created, **before** the first request (the relation is nullable, so Larastan needs the `?? throw`). Tests that only make guest requests stay on the apex.

| File | Tests to change (by the line that creates the user) |
|---|---|
| `tests/Feature/Auth/AuthenticationTest.php` | every test with `User::factory()` (lines 17, 32, 52, 64) |
| `tests/Feature/Auth/PasswordConfirmationTest.php` | line 10 |
| `tests/Feature/Auth/PasswordResetTest.php` | lines 25, 35, 51 |
| `tests/Feature/Auth/TwoFactorChallengeTest.php` | line 26 |
| `tests/Feature/Auth/InviteOnlyTest.php` | line 26 |
| `tests/Feature/DashboardTest.php` | line 15 |
| `tests/Feature/Settings/ProfileUpdateTest.php` | every test with `User::factory()` that acts as that user (lines 12, 18, 36, 60 and the Task 1 test); for `$this->actingAs($user = User::factory()->create());` insert the `onTenant(...)` line on the next line; for `$this->actingAs(User::factory()->create());` first assign the user to `$user` |
| `tests/Feature/Settings/SecurityTest.php` | lines 25, 40, 51, 64, 86, 104 |

Example (`tests/Feature/DashboardTest.php`):

```php
test('authenticated users can visit the dashboard', function (): void {
    $user = User::factory()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});
```

Assertions that compare against `route(...)` keep working because `route()` now builds tenant-host URLs.

- [ ] **Step 6: Run the M1 suite again**

Run: `php artisan test --compact tests/Feature`
Expected: PASS.

- [ ] **Step 7: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: log out users on hosts they do not belong to

- VerifyTenantMembership with a translated reason, also on Livewire updates
- M1 feature tests run on the user's tenant host

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Host-scoped route groups and Livewire persistent middleware

**Files:**
- Create: `app/Tenancy/Http/Middleware/EnsureTenantHost.php`, `app/Tenancy/Http/Middleware/EnsureCentralHost.php`, `tests/Fixtures/TenantProbe.php`
- Modify: `app/Providers/TenancyServiceProvider.php`, `bootstrap/app.php`
- Test: `tests/Feature/Tenancy/HostScopingTest.php`

**Interfaces:**
- Consumes: `Tenant`, testing API (Tasks 5–6).
- Produces: `Route::tenant(Closure $routes)` and `Route::central(Closure $routes)` route-group macros (no `Route::domain()`, so route names and `route()` calls carry no tenant parameter); both middleware are Livewire persistent middleware and run before `can:` (`Illuminate\Auth\Middleware\Authorize`).

- [ ] **Step 1: Add the Livewire fixture**

`tests/Fixtures/TenantProbe.php` (class-based on purpose: the MFC rule covers `resources/views` only):

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Livewire\Component;

final class TenantProbe extends Component
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function render(): string
    {
        return '<div>{{ $count }}</div>';
    }
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Tenancy/HostScopingTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §6.2 */

use App\Models\Organisation;
use App\Tenancy\Hosts;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Drawer\Utils;
use Livewire\Livewire;
use Tests\Fixtures\TenantProbe;

beforeEach(function (): void {
    Livewire::component('tenant-probe', TenantProbe::class);
    Gate::define('nobody', fn (): bool => false);

    Route::middleware('web')->group(function (): void {
        Route::tenant(function (): void {
            Route::get('/_tenant-only', fn (): string => 'tenant');
            Route::get('/_probe', fn (): string => Blade::render('<livewire:tenant-probe />'));
        });
        Route::central(function (): void {
            Route::get('/_central-only', fn (): string => 'central');
        });
        Route::middleware('can:nobody')->group(function (): void {
            Route::tenant(function (): void {
                Route::get('/_guarded', fn (): string => 'never');
            });
        });
    });
});

it('serves tenant routes on tenant hosts only', function (): void {
    asSuperAdmin();
    $organisation = Organisation::factory()->create();

    $this->get(tenant_url($organisation, '/_tenant-only'))->assertOk()->assertSee('tenant');
    $this->get(Hosts::url(Hosts::apex(), '/_tenant-only'))->assertNotFound();
    $this->get(Hosts::url(Hosts::admin(), '/_tenant-only'))->assertNotFound();
});

it('serves central routes on central hosts only', function (): void {
    asSuperAdmin();
    $organisation = Organisation::factory()->create();

    $this->get(Hosts::url(Hosts::apex(), '/_central-only'))->assertOk();
    $this->get(Hosts::url(Hosts::admin(), '/_central-only'))->assertOk();
    $this->get(tenant_url($organisation, '/_central-only'))->assertNotFound();
});

it('checks the host before can: middleware', function (): void {
    // As a guest: can:nobody answers 403 if it runs before EnsureTenantHost, and 404 otherwise.
    // A super admin would pass can:nobody through SuperAdminGate (Task 12) and hide a wrong order.
    $this->get(Hosts::url(Hosts::apex(), '/_guarded'))->assertNotFound();
});

it('keeps the host rule on forged Livewire updates from the wrong host', function (): void {
    asSuperAdmin();
    $organisation = Organisation::factory()->create();
    $html = $this->get(tenant_url($organisation, '/_probe'))->assertOk()->getContent();
    $snapshot = Utils::extractAttributeDataFromHtml((string) $html, 'wire:snapshot');
    $payload = ['components' => [[
        'snapshot' => json_encode($snapshot),
        'updates' => [],
        'calls' => [['method' => 'increment', 'params' => [], 'path' => '', 'metadata' => []]],
    ]]];

    $this->postJson(tenant_url($organisation, Livewire::getUpdateUri()), $payload, ['X-Livewire' => '1'])->assertOk();
    Livewire::flushState(); // a new request cycle, as in production: PersistentMiddleware remembers routes it already handled
    $this->postJson(Hosts::url(Hosts::apex(), Livewire::getUpdateUri()), $payload, ['X-Livewire' => '1'])->assertNotFound();
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Tenancy/HostScopingTest.php`
Expected: FAIL — `Method Illuminate\Routing\Router::tenant does not exist.`

- [ ] **Step 4: Implement the middleware, macros and persistence**

`app/Tenancy/Http/Middleware/EnsureTenantHost.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Middleware;

use App\Tenancy\Facades\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTenantHost
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Tenant::check(), 404);

        return $next($request);
    }
}
```

`app/Tenancy/Http/Middleware/EnsureCentralHost.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Middleware;

use App\Tenancy\Facades\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCentralHost
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(Tenant::check(), 404);

        return $next($request);
    }
}
```

In `app/Providers/TenancyServiceProvider.php::boot()` add (imports `Closure`, `Illuminate\Support\Facades\Route`, `Livewire\Livewire`, both middleware):

```php
        // §6.2: host groups attach middleware instead of Route::domain(), so route names,
        // route() calls and mount() signatures never carry a {tenant} parameter.
        Route::macro('tenant', function (Closure $routes): void {
            Route::middleware(EnsureTenantHost::class)->group($routes);
        });
        Route::macro('central', function (Closure $routes): void {
            Route::middleware(EnsureCentralHost::class)->group($routes);
        });

        // Re-applied on /livewire/update for components mounted on these routes (FQCN required).
        Livewire::addPersistentMiddleware([EnsureTenantHost::class, EnsureCentralHost::class]);
```

In `bootstrap/app.php` extend the middleware closure (import `Illuminate\Auth\Middleware\Authorize` and both middleware):

```php
        $middleware->prependToPriorityList(Authorize::class, EnsureTenantHost::class);
        $middleware->prependToPriorityList(Authorize::class, EnsureCentralHost::class);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Tenancy/HostScopingTest.php`
Expected: PASS. If the forged-update test fails on the payload shape (422/500 instead of 200 on the tenant host), compare with `vendor/livewire/livewire/src/Features/SupportTesting/Testable.php::call()` and `SubsequentRender::makeSubsequentRequest()` for the exact `calls` entry format, and keep the wrong-host assertion unchanged. If the wrong-host post returns 200, check that `Livewire::flushState()` runs between the two posts (PersistentMiddleware skips routes it already handled until flush-state).

- [ ] **Step 6: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green. Larastan boots the application, so it sees the `Route::tenant` / `Route::central` macros. If it still reports an undefined static method, report the exact message instead of adding an ignore.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: add tenant and central route groups

- Route::tenant/Route::central attach EnsureTenantHost/EnsureCentralHost
- both persistent for Livewire updates and ordered before can:

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Queue tenant context

**Files:**
- Create: `app/Tenancy/Queue/JobTenancy.php`, `tests/Fixtures/RecordTenantJob.php`
- Modify: `app/Providers/TenancyServiceProvider.php`, `app/Tenancy/Exceptions/TenantNotResolvedException.php`
- Test: `tests/Feature/Tenancy/QueueTenancyTest.php`

**Interfaces:**
- Consumes: `CurrentTenant`, `Tenant` (Task 5), `PermissionsTeam` (Task 2).
- Produces: every queue payload carries `tenant_id` (`int|null`); `App\Tenancy\Queue\JobTenancy` (singleton) with `begin(JobProcessing $event): void` and `end(JobProcessed|JobFailed|JobExceptionOccurred $event): void`; `TenantNotResolvedException::forJob(int $organisationId): self`.

- [ ] **Step 1: Add the job fixture**

`tests/Fixtures/RecordTenantJob.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Authorization\PermissionsTeam;
use App\Tenancy\Facades\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

final class RecordTenantJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::put('job.ran', true);
        Cache::put('job.tenant', Tenant::current()?->id);
        Cache::put('job.team', PermissionsTeam::current());
    }
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Tenancy/QueueTenancyTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §6.2 */

use App\Models\Organisation;
use App\Tenancy\Facades\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\RecordTenantJob;

beforeEach(function (): void {
    config(['queue.default' => 'database']);
});

/** @return array<string, mixed> */
function lastQueuedPayload(): array
{
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) DB::table('jobs')->latest('id')->value('payload'), true, flags: JSON_THROW_ON_ERROR);

    return $payload;
}

function workOneJob(): void
{
    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true, '--tries' => 3]);
}

it('adds the current tenant to every queued payload', function (): void {
    $organisation = Organisation::factory()->create();

    Tenant::run($organisation, function (): void {
        Bus::dispatch(new RecordTenantJob);
    });

    expect(lastQueuedPayload()['tenant_id'])->toBe($organisation->id);
});

it('queues central work without a tenant', function (): void {
    Bus::dispatch(new RecordTenantJob);

    expect(lastQueuedPayload()['tenant_id'])->toBeNull();
});

it('binds the tenant and the team while the worker runs the job', function (): void {
    $organisation = Organisation::factory()->create();
    Tenant::run($organisation, function (): void {
        Bus::dispatch(new RecordTenantJob);
    });

    workOneJob();

    expect(Cache::get('job.tenant'))->toBe($organisation->id)
        ->and(Cache::get('job.team'))->toBe($organisation->id)
        ->and(Tenant::check())->toBeFalse();
});

it('fails the job without retry when the organisation is gone', function (): void {
    $organisation = Organisation::factory()->create();
    Tenant::run($organisation, function (): void {
        Bus::dispatch(new RecordTenantJob);
    });
    $organisation->delete();

    workOneJob();

    expect(Cache::has('job.ran'))->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});

it('restores the request tenant after a synchronous job', function (): void {
    config(['queue.default' => 'sync']);
    $organisation = Organisation::factory()->create();
    Tenant::set($organisation);

    Bus::dispatch(new RecordTenantJob);

    expect(Cache::get('job.tenant'))->toBe($organisation->id)
        ->and(Tenant::current()?->id)->toBe($organisation->id);
});
```

Also add (Task 5 makes `Tenant::run()` destroy a returned `PendingDispatch` while the tenant is still bound):

```php
it('queues a job returned from Tenant::run() under that tenant', function (): void {
    $organisation = Organisation::factory()->create();

    Tenant::run($organisation, fn () => dispatch(new RecordTenantJob));

    expect(lastQueuedPayload()['tenant_id'])->toBe($organisation->id);
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Tenancy/QueueTenancyTest.php`
Expected: FAIL — `Undefined array key "tenant_id"`.

- [ ] **Step 4: Implement the job tenancy**

Add to `app/Tenancy/Exceptions/TenantNotResolvedException.php`:

```php
    public static function forJob(int $organisationId): self
    {
        return new self("The organisation [{$organisationId}] of this queued job no longer exists or is archived.");
    }
```

`app/Tenancy/Queue/JobTenancy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Queue;

use App\Models\Organisation;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Queue tenant context (§6.2). JobProcessing runs before the command is unserialized, so model
 * restoring already happens under the job's tenant. A missing or archived organisation fails
 * the job without retry. The previous tenant is restored afterwards (not just forgotten), so a
 * synchronous job inside a request leaves the request's tenant in place. end() is idempotent
 * per job because a failing job raises more than one of the end events.
 */
final class JobTenancy
{
    /** @var array<int, Organisation|null> previous tenant per job object */
    private array $previous = [];

    public function begin(JobProcessing $event): void
    {
        $tenant = app(CurrentTenant::class);
        $this->previous[spl_object_id($event->job)] = $tenant->current();

        $tenantId = $event->job->payload()['tenant_id'] ?? null;

        if ($tenantId === null) {
            $tenant->forget();

            return;
        }

        $organisation = Organisation::query()->find((int) $tenantId);

        if ($organisation === null) {
            $exception = TenantNotResolvedException::forJob((int) $tenantId);
            $event->job->fail($exception);

            throw $exception;
        }

        $tenant->set($organisation);
    }

    public function end(JobProcessed|JobFailed|JobExceptionOccurred $event): void
    {
        $key = spl_object_id($event->job);

        if (! array_key_exists($key, $this->previous)) {
            return;
        }

        $previous = $this->previous[$key];
        unset($this->previous[$key]);

        $tenant = app(CurrentTenant::class);

        if ($previous instanceof Organisation) {
            $tenant->set($previous);
        } else {
            $tenant->forget();
        }
    }
}
```

In `app/Providers/TenancyServiceProvider.php`:
- `register()`: add `$this->app->singleton(JobTenancy::class);`
- `boot()`: add (imports `Illuminate\Support\Facades\Queue`, the four queue events, `JobTenancy`):

```php
        Queue::createPayloadUsing(fn (): array => [
            'tenant_id' => $this->app->make(CurrentTenant::class)->current()?->id,
        ]);
        Event::listen(JobProcessing::class, [JobTenancy::class, 'begin']);
        Event::listen(JobProcessed::class, [JobTenancy::class, 'end']);
        Event::listen(JobFailed::class, [JobTenancy::class, 'end']);
        Event::listen(JobExceptionOccurred::class, [JobTenancy::class, 'end']);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Tenancy/QueueTenancyTest.php`
Expected: PASS. The failing-job test reports the exception through the worker; a log line in `storage/logs` is expected.

- [ ] **Step 6: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: carry the tenant through queued jobs

- tenant_id on every payload, bound before the job is unserialized
- jobs of a missing or archived organisation fail without retry
- the previous tenant is restored after the job

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Soft-delete cascade and the tenant-model registry check

**Files:**
- Create: `app/Tenancy/SoftDeleteCascade.php`
- Modify: `app/Tenancy/Observers/OrganisationObserver.php`
- Test: `tests/Feature/Organisations/OrganisationCascadeTest.php`, `tests/Feature/Tenancy/TenantModelsTest.php`

**Interfaces:**
- Consumes: `OrganisationObserver` (Task 7), `config('tenancy.tenant_models')` (Task 5).
- Produces: `App\Tenancy\SoftDeleteCascade::trash(Organisation $organisation): void`, `::restore(Organisation $organisation): void`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Organisations/OrganisationCascadeTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.1 */

use App\Authorization\SetSuperAdmin;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function deletedAtOf(User $user): ?string
{
    $value = DB::table('users')->where('id', $user->id)->value('deleted_at');

    return $value === null ? null : (string) $value;
}

it('soft deletes the organisation\'s tenant rows with the same timestamp', function (): void {
    $organisation = Organisation::factory()->create();
    [$first, $second] = User::factory()->for($organisation)->count(2)->create()->all();
    $outsider = User::factory()->create();

    $organisation->delete();

    $organisationDeletedAt = (string) DB::table('organisations')->where('id', $organisation->id)->value('deleted_at');
    expect(deletedAtOf($first))->toBe($organisationDeletedAt)
        ->and(deletedAtOf($second))->toBe($organisationDeletedAt)
        ->and(deletedAtOf($outsider))->toBeNull();
});

it('restores only the rows the cascade deleted', function (): void {
    $organisation = Organisation::factory()->create();
    $deletedEarlier = User::factory()->for($organisation)->create();
    $deletedEarlier->delete();
    $this->travel(1)->minutes();
    $cascaded = User::factory()->for($organisation)->create();

    $organisation->delete();
    $organisation->restore();

    expect(deletedAtOf($cascaded))->toBeNull()
        ->and(deletedAtOf($deletedEarlier))->not->toBeNull();
});

it('does not cascade a force delete', function (): void {
    $empty = Organisation::factory()->create();
    $empty->forceDelete();
    expect(Organisation::withTrashed()->find($empty->id))->toBeNull();

    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();

    expect(fn () => DB::transaction(fn (): ?bool => $organisation->forceDelete()))->toThrow(QueryException::class);
    expect(deletedAtOf($member))->toBeNull();
});

it('never archives a super admin with the organisation', function (): void {
    $organisation = Organisation::factory()->create();
    $promoted = User::factory()->for($organisation)->create();
    app(SetSuperAdmin::class)->handle($promoted, true);

    $organisation->delete();

    expect(deletedAtOf($promoted))->toBeNull();
});
```

`tests/Feature/Tenancy/TenantModelsTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §6.2, §7.2 */

use App\Tenancy\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('registers every model with an organisation_id as a soft-deleting tenant model', function (): void {
    /** @var list<string> $registered */
    $registered = config('tenancy.tenant_models');

    $withOrganisationId = collect(File::allFiles(app_path('Models')))
        ->map(fn (SplFileInfo $file): string => 'App\\Models\\'.Str::of($file->getRelativePathname())->beforeLast('.php')->replace('/', '\\'))
        ->filter(fn (string $class): bool => is_subclass_of($class, Model::class) && ! new ReflectionClass($class)->isAbstract())
        ->filter(function (string $class): bool {
            /** @var Model $model */
            $model = app($class);

            return Schema::hasColumn($model->getTable(), 'organisation_id');
        })
        ->values();

    expect($withOrganisationId)->not->toBeEmpty();

    foreach ($withOrganisationId as $class) {
        expect($registered)->toContain($class)
            ->and(class_uses_recursive($class))->toContain(BelongsToOrganisation::class, SoftDeletes::class);
    }

    foreach ($registered as $class) {
        expect(class_uses_recursive($class))->toContain(BelongsToOrganisation::class, SoftDeletes::class);
    }
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Organisations/OrganisationCascadeTest.php tests/Feature/Tenancy/TenantModelsTest.php`
Expected: the cascade tests FAIL (members keep `deleted_at = null`); the registry test already PASSES (User is registered) and guards future models.

- [ ] **Step 3: Implement the cascade**

`app/Tenancy/SoftDeleteCascade.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Soft-deleting an organisation soft-deletes its tenant rows with the same timestamp; restore
 * brings back only those rows; force delete does not cascade (purge is explicit, §4.1, §4.3).
 * Bulk updates without global scopes: no model events, no tenant scope.
 */
final class SoftDeleteCascade
{
    public function trash(Organisation $organisation): void
    {
        $deletedAt = $organisation->deleted_at;

        if ($deletedAt === null) {
            return;
        }

        foreach ($this->models() as $model) {
            $model::query()->withoutGlobalScopes()
                ->where('organisation_id', $organisation->id)
                ->whereNull('deleted_at')
                // A promoted super admin keeps its organisation_id; archiving that organisation must
                // never archive a super admin (it would bypass the last-super-admin guard).
                ->when($model === User::class, fn (Builder $query): Builder => $query->where('is_super_admin', false))
                ->update(['deleted_at' => $deletedAt]);
        }
    }

    /** Call from "restoring": deleted_at still holds the cascade timestamp there. */
    public function restore(Organisation $organisation): void
    {
        $deletedAt = $organisation->deleted_at;

        if ($deletedAt === null) {
            return;
        }

        foreach ($this->models() as $model) {
            $model::query()->withoutGlobalScopes()
                ->where('organisation_id', $organisation->id)
                ->where('deleted_at', $deletedAt)
                ->update(['deleted_at' => null]);
        }
    }

    /** @return list<class-string<Model>> */
    private function models(): array
    {
        /** @var list<class-string<Model>> $models */
        $models = config('tenancy.tenant_models', []);

        return $models;
    }
}
```

In `app/Tenancy/Observers/OrganisationObserver.php` add (import `App\Tenancy\SoftDeleteCascade`):

```php
    public function trashed(Organisation $organisation): void
    {
        app(SoftDeleteCascade::class)->trash($organisation);
    }

    public function restoring(Organisation $organisation): void
    {
        app(SoftDeleteCascade::class)->restore($organisation);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Organisations tests/Feature/Tenancy/TenantModelsTest.php`
Expected: PASS.

- [ ] **Step 5: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: cascade organisation soft deletes to tenant models

- same timestamp on delete, only those rows on restore, no cascade on force delete
- registry check: every model with organisation_id is a listed tenant model

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Policies and the `Gate::before` rule

**Files:**
- Create: `app/Authorization/SuperAdminGate.php`, `app/Policies/UserPolicy.php`, `app/Policies/OrganisationPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Authorization/SuperAdminGateTest.php`, `tests/Feature/Authorization/UserPolicyTest.php`, `tests/Feature/Authorization/OrganisationPolicyTest.php`

**Interfaces:**
- Consumes: `Permission`, `Role` (Task 3), testing API (Task 6).
- Produces: `App\Authorization\SuperAdminGate::ALWAYS_CHECKED` (`impersonate`, `delete`, `forceDelete`, `updateStatus`, `updateRoles`, `updateSuperAdmin`), `SuperAdminGate::before(User $user, string $ability): ?bool`; `UserPolicy` abilities `viewAny`, `view`, `update`, `delete`, `updateStatus`, `updateRoles`, `updateSuperAdmin`; `OrganisationPolicy` abilities `viewAny`, `view`, `create`, `update`, `delete`, `restore`, `forceDelete`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Authorization/SuperAdminGateTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.6, §6.3 */

use App\Authorization\Permission;
use App\Authorization\SuperAdminGate;
use App\Models\User;

it('grants super admins every ability except the always-checked ones', function (): void {
    $superAdmin = User::factory()->superAdmin()->make();

    expect(SuperAdminGate::before($superAdmin, Permission::RolesManage->value))->toBeTrue()
        ->and(SuperAdminGate::before($superAdmin, 'update'))->toBeTrue();

    foreach (SuperAdminGate::ALWAYS_CHECKED as $ability) {
        expect(SuperAdminGate::before($superAdmin, $ability))->toBeNull();
    }
});

it('never decides for other users', function (): void {
    expect(SuperAdminGate::before(User::factory()->make(), Permission::UsersView->value))->toBeNull();
});

it('treats a user without organisation but without the flag as a normal user (W8)', function (): void {
    $user = User::factory()->make(['organisation_id' => null]);

    expect(SuperAdminGate::before($user, Permission::RolesManage->value))->toBeNull();
});

it('is registered with the gate', function (): void {
    $superAdmin = asSuperAdmin();

    expect($superAdmin->can(Permission::SystemHealth))->toBeTrue();
});
```

`tests/Feature/Authorization/UserPolicyTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.4, §4.6 */

use App\Authorization\SetSuperAdmin;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** @return array{organisation: Organisation, admin: User, member: User, outsider: User, superAdmin: User, otherSuperAdmin: User} */
function userPolicyCast(): array
{
    $organisation = Organisation::factory()->create();
    $outsider = User::factory()->create();
    $superAdmin = asSuperAdmin();
    $otherSuperAdmin = User::factory()->superAdmin()->create();
    $member = User::factory()->for($organisation)->create();
    $admin = asOrgAdmin($organisation);

    return compact('organisation', 'admin', 'member', 'outsider', 'superAdmin', 'otherSuperAdmin');
}

it('lets an organisation admin manage members of their own organisation', function (): void {
    ['admin' => $admin, 'member' => $member] = userPolicyCast();
    $gate = Gate::forUser($admin);

    expect($gate->allows('viewAny', User::class))->toBeTrue()
        ->and($gate->allows('view', $member))->toBeTrue()
        ->and($gate->allows('update', $member))->toBeTrue()
        ->and($gate->allows('updateStatus', $member))->toBeTrue()
        ->and($gate->allows('updateRoles', $member))->toBeTrue()
        ->and($gate->allows('delete', $member))->toBeTrue();
});

it('keeps an organisation admin out of other organisations and away from super admins', function (): void {
    ['admin' => $admin, 'outsider' => $outsider, 'superAdmin' => $superAdmin] = userPolicyCast();
    $gate = Gate::forUser($admin);

    foreach (['view', 'update', 'updateStatus', 'updateRoles', 'delete'] as $ability) {
        expect($gate->allows($ability, $outsider))->toBeFalse("{$ability} outsider")
            ->and($gate->allows($ability, $superAdmin))->toBeFalse("{$ability} super admin");
    }

    expect($gate->allows('updateSuperAdmin', $outsider))->toBeFalse();
});

it('stops users from deleting or disabling themselves, super admins included (W2, W12)', function (): void {
    ['admin' => $admin, 'superAdmin' => $superAdmin] = userPolicyCast();

    expect(Gate::forUser($admin)->allows('delete', $admin))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('updateStatus', $admin))->toBeFalse()
        ->and(Gate::forUser($superAdmin)->allows('delete', $superAdmin))->toBeFalse()
        ->and(Gate::forUser($superAdmin)->allows('updateStatus', $superAdmin))->toBeFalse()
        ->and(Gate::forUser($superAdmin)->allows('updateSuperAdmin', $superAdmin))->toBeFalse();
});

it('keeps organisation admins away from a super admin of their own organisation', function (): void {
    $organisation = Organisation::factory()->create();
    $promoted = User::factory()->for($organisation)->create();
    app(SetSuperAdmin::class)->handle($promoted, true);
    $gate = Gate::forUser(asOrgAdmin($organisation));

    foreach (['view', 'update', 'updateStatus', 'updateRoles', 'delete'] as $ability) {
        expect($gate->allows($ability, $promoted->fresh()))->toBeFalse($ability);
    }
});

it('lets super admins manage everyone else', function (): void {
    ['superAdmin' => $superAdmin, 'otherSuperAdmin' => $other, 'outsider' => $outsider] = userPolicyCast();
    $gate = Gate::forUser($superAdmin);

    expect($gate->allows('update', $outsider))->toBeTrue()
        ->and($gate->allows('delete', $outsider))->toBeTrue()
        ->and($gate->allows('delete', $other))->toBeTrue()
        ->and($gate->allows('updateRoles', $outsider))->toBeTrue()
        ->and($gate->allows('updateSuperAdmin', $outsider))->toBeTrue();
});

it('never assigns organisation roles to super admins', function (): void {
    ['superAdmin' => $superAdmin, 'otherSuperAdmin' => $other] = userPolicyCast();

    expect(Gate::forUser($superAdmin)->allows('updateRoles', $other))->toBeFalse();
});

it('gives members without permissions nothing', function (): void {
    ['organisation' => $organisation, 'admin' => $admin] = userPolicyCast();
    $member = asMember($organisation);
    $gate = Gate::forUser($member);

    expect($gate->allows('viewAny', User::class))->toBeFalse();

    foreach (['view', 'update', 'updateStatus', 'updateRoles', 'delete', 'updateSuperAdmin'] as $ability) {
        expect($gate->allows($ability, $admin))->toBeFalse($ability);
    }
});
```

`tests/Feature/Authorization/OrganisationPolicyTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.3, §4.6 */

use App\Models\Organisation;
use Illuminate\Support\Facades\Gate;

it('lets an organisation admin view and edit only their own organisation', function (): void {
    $own = Organisation::factory()->create();
    $other = Organisation::factory()->create();
    $gate = Gate::forUser(asOrgAdmin($own));

    expect($gate->allows('viewAny', Organisation::class))->toBeTrue()
        ->and($gate->allows('view', $own))->toBeTrue()
        ->and($gate->allows('update', $own))->toBeTrue()
        ->and($gate->allows('view', $other))->toBeFalse()
        ->and($gate->allows('update', $other))->toBeFalse();

    foreach (['delete', 'restore', 'forceDelete'] as $ability) {
        expect($gate->allows($ability, $own))->toBeFalse($ability);
    }

    expect($gate->allows('create', Organisation::class))->toBeFalse();
});

it('reserves create, archive, restore and purge for super admins', function (): void {
    $active = Organisation::factory()->create();
    $archived = Organisation::factory()->create();
    $archived->delete();
    $gate = Gate::forUser(asSuperAdmin());

    expect($gate->allows('create', Organisation::class))->toBeTrue()
        ->and($gate->allows('delete', $active))->toBeTrue()
        ->and($gate->allows('delete', $archived))->toBeFalse()
        ->and($gate->allows('restore', $archived))->toBeTrue()
        ->and($gate->allows('forceDelete', $archived))->toBeTrue()
        ->and($gate->allows('forceDelete', $active))->toBeFalse();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Authorization`
Expected: FAIL — `Class "App\Authorization\SuperAdminGate" not found`.

- [ ] **Step 3: Implement the gate rule**

`app/Authorization/SuperAdminGate.php`:

```php
<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Models\User;

/**
 * Gate::before for super admins (§4.6). users.is_super_admin is the only source (W8). The
 * always-checked abilities return null so their policies and guards run for super admins too
 * (W2, W3, W12); those policies grant super admins explicitly where appropriate.
 */
final class SuperAdminGate
{
    /** @var list<string> */
    public const array ALWAYS_CHECKED = ['impersonate', 'delete', 'forceDelete', 'updateStatus', 'updateRoles', 'updateSuperAdmin'];

    public static function before(User $user, string $ability): ?bool
    {
        if (in_array($ability, self::ALWAYS_CHECKED, true)) {
            return null;
        }

        return $user->isSuperAdmin() ? true : null;
    }
}
```

In `app/Providers/AppServiceProvider.php` call `$this->configureAuthorization();` in `boot()` and add:

```php
    protected function configureAuthorization(): void
    {
        Gate::before(SuperAdminGate::before(...));
    }
```

(imports `App\Authorization\SuperAdminGate`, `Illuminate\Support\Facades\Gate`).

- [ ] **Step 4: Implement the policies**

`app/Policies/UserPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Authorization\Permission;
use App\Models\User;

final class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::UsersView);
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->can(Permission::UsersView) && $this->managesMember($actor, $target);
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->can(Permission::UsersUpdate) && $this->managesMember($actor, $target);
    }

    /** Always checked: nobody deletes themselves, super admins included (W2). */
    public function delete(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        if ($actor->isSuperAdmin()) {
            return true;
        }

        return ! $target->isSuperAdmin()
            && $actor->can(Permission::UsersDelete)
            && $this->sameOrganisation($actor, $target);
    }

    /** Always checked: nobody disables themselves (W12). */
    public function updateStatus(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        if ($actor->isSuperAdmin()) {
            return true;
        }

        return ! $target->isSuperAdmin()
            && $actor->can(Permission::UsersUpdate)
            && $this->sameOrganisation($actor, $target);
    }

    /** Always checked: roles belong to an organisation; super admins carry only the badge (W1). */
    public function updateRoles(User $actor, User $target): bool
    {
        if ($target->isSuperAdmin() || $target->organisation_id === null) {
            return false;
        }

        if ($actor->isSuperAdmin()) {
            return true;
        }

        return $actor->can(Permission::RolesAssign) && $this->sameOrganisation($actor, $target);
    }

    /** Always checked: only another super admin changes the flag (§6.3). */
    public function updateSuperAdmin(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin() && $actor->isNot($target);
    }

    /** Organisation admins never act on super admins, even promoted ones that keep an organisation_id (§6.3). */
    private function managesMember(User $actor, User $target): bool
    {
        return ! $target->isSuperAdmin() && $this->sameOrganisation($actor, $target);
    }

    private function sameOrganisation(User $actor, User $target): bool
    {
        return $actor->organisation_id !== null && $actor->organisation_id === $target->organisation_id;
    }
}
```

`app/Policies/OrganisationPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Authorization\Permission;
use App\Models\Organisation;
use App\Models\User;

/** §4.3: view/update = permission + ownership; create/delete/restore/forceDelete = super admin. */
final class OrganisationPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::OrganisationsView);
    }

    public function view(User $actor, Organisation $organisation): bool
    {
        return $actor->can(Permission::OrganisationsView) && $actor->organisation_id === $organisation->id;
    }

    /** Super admins pass through Gate::before. */
    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, Organisation $organisation): bool
    {
        return $actor->can(Permission::OrganisationsUpdate) && $actor->organisation_id === $organisation->id;
    }

    /** Always checked: archiving applies to active organisations only. */
    public function delete(User $actor, Organisation $organisation): bool
    {
        return $actor->isSuperAdmin() && ! $organisation->trashed();
    }

    /** Super admins pass through Gate::before. */
    public function restore(User $actor, Organisation $organisation): bool
    {
        return false;
    }

    /** Always checked: purge is only available for archived organisations. */
    public function forceDelete(User $actor, Organisation $organisation): bool
    {
        return $actor->isSuperAdmin() && $organisation->trashed();
    }
}
```

If Rector's dead-code set removes the unused parameters of `create()` / `restore()`, keep its change only if the policy still resolves (Laravel passes the arguments positionally; an unused parameter can go).

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Authorization`
Expected: PASS.

- [ ] **Step 6: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green. `preset()->laravel()` accepts `App\Policies\*Policy`; its only ignores are the ones listed in `tests/Arch/PresetsTest.php` (Task 3).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: add user and organisation policies with the super-admin gate

- Gate::before reads only is_super_admin; self-destructive abilities always checked (W2, W8)
- ownership rules for organisation admins

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: `UpdateUserRoles` (W1, W12)

**Files:**
- Create: `app/Actions/Users/UpdateUserRoles.php`
- Modify: `lang/nl.json`, `lang/en.json`
- Test: `tests/Feature/Users/UpdateUserRolesTest.php`

**Interfaces:**
- Consumes: `UserPolicy::updateRoles` (Task 12), `PermissionsTeam`, `Role` (Tasks 2–3), `UserStatus` (Task 1).
- Produces: `App\Actions\Users\UpdateUserRoles::handle(User $actor, User $target, list<int> $roleIds): void` — throws `AuthorizationException` (policy) or `ValidationException` (key `roles`). The only way app code assigns organisation roles (§4.6); M4's user edit, invite and bulk invite call it.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Users/UpdateUserRolesTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.6, §6.3 */

use App\Actions\Users\UpdateUserRoles;
use App\Authorization\Permission;
use App\Authorization\PermissionsTeam;
use App\Authorization\Role;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as RoleModel;

function teamRole(Organisation $organisation, string $name): RoleModel
{
    return RoleModel::query()->create(['name' => $name, 'guard_name' => 'web', 'team_id' => $organisation->id]);
}

function systemRoleId(Role $role): int
{
    return (int) RoleModel::query()->whereNull('team_id')->where('name', $role->value)->value('id');
}

/** @return list<string> */
function roleNamesOf(User $user, Organisation $organisation): array
{
    $names = array_map(
        fn (mixed $name): string => (string) $name,
        withPermissionsTeam($organisation->id, fn (): array => $user->fresh()?->getRoleNames()->all() ?? []),
    );
    sort($names);

    return $names;
}

it('assigns team roles and the organisation admin template in the target team', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $editor = teamRole($organisation, 'editor');
    $admin = asOrgAdmin($organisation);

    app(UpdateUserRoles::class)->handle($admin, $member, [(int) $editor->id, systemRoleId(Role::OrganisationAdmin)]);

    expect(roleNamesOf($member, $organisation))->toBe(['editor', 'organisation_admin'])
        ->and(DB::table('model_has_roles')->where('model_id', $member->id)->pluck('team_id')->unique()->map(fn (mixed $id): int => (int) $id)->all())
        ->toBe([$organisation->id]);
});

it('rejects the super admin badge and roles of other organisations (W1)', function (int|string $role): void {
    $organisation = Organisation::factory()->create();
    $foreign = teamRole(Organisation::factory()->create(), 'foreign');
    $member = User::factory()->for($organisation)->create();
    $admin = asOrgAdmin($organisation);
    $roleId = match ($role) {
        'super admin badge' => systemRoleId(Role::SuperAdmin),
        'foreign team role' => (int) $foreign->id,
        default => (int) $role,
    };

    expect(fn () => app(UpdateUserRoles::class)->handle($admin, $member, [$roleId]))
        ->toThrow(ValidationException::class);
    expect(roleNamesOf($member, $organisation))->toBe([]);
})->with(['super admin badge', 'foreign team role', 999999]);

it('refuses granting roles with permissions the actor lacks (W1)', function (): void {
    $organisation = Organisation::factory()->create();
    $assigner = teamRole($organisation, 'assigner');
    $assigner->givePermissionTo(Permission::RolesAssign->value);
    $actor = asMember($organisation);
    withPermissionsTeam($organisation->id, fn () => $actor->assignRole($assigner));
    $actor->unsetRelation('roles');

    expect(fn () => app(UpdateUserRoles::class)->handle($actor, $actor, [(int) $assigner->id, systemRoleId(Role::OrganisationAdmin)]))
        ->toThrow(ValidationException::class);
    expect(roleNamesOf($actor, $organisation))->toBe(['assigner']);
});

it('refuses targets outside the actor\'s organisation and super admin targets', function (): void {
    $organisation = Organisation::factory()->create();
    $outsider = User::factory()->create();
    $superAdmin = User::factory()->superAdmin()->create();
    $admin = asOrgAdmin($organisation);

    expect(fn () => app(UpdateUserRoles::class)->handle($admin, $outsider, []))->toThrow(AuthorizationException::class);
    expect(fn () => app(UpdateUserRoles::class)->handle($admin, $superAdmin, []))->toThrow(AuthorizationException::class);
});

it('refuses actors without roles.assign', function (): void {
    $organisation = Organisation::factory()->create();
    $target = User::factory()->for($organisation)->create();
    $member = asMember($organisation);

    expect(fn () => app(UpdateUserRoles::class)->handle($member, $target, []))->toThrow(AuthorizationException::class);
});

it('refuses removing your own organisation admin role (W12)', function (): void {
    $organisation = Organisation::factory()->create();
    $other = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn () => $other->assignRole(Role::OrganisationAdmin->value));
    $admin = asOrgAdmin($organisation);

    expect(fn () => app(UpdateUserRoles::class)->handle($admin, $admin, []))->toThrow(ValidationException::class);
    expect(roleNamesOf($admin, $organisation))->toBe(['organisation_admin']);
});

it('refuses demoting the last active organisation admin (W12)', function (): void {
    $organisation = Organisation::factory()->create();
    $onlyAdmin = User::factory()->for($organisation)->create();
    $disabledAdmin = User::factory()->for($organisation)->disabled()->create();
    withPermissionsTeam($organisation->id, function () use ($onlyAdmin, $disabledAdmin): void {
        $onlyAdmin->assignRole(Role::OrganisationAdmin->value);
        $disabledAdmin->assignRole(Role::OrganisationAdmin->value);
    });
    $superAdmin = asSuperAdmin();
    onTenant($organisation);

    expect(fn () => app(UpdateUserRoles::class)->handle($superAdmin, $onlyAdmin, []))->toThrow(ValidationException::class);
});

it('allows demoting an organisation admin when another active one remains', function (): void {
    $organisation = Organisation::factory()->create();
    $leaving = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn () => $leaving->assignRole(Role::OrganisationAdmin->value));
    $admin = asOrgAdmin($organisation);

    app(UpdateUserRoles::class)->handle($admin, $leaving, []);

    expect(roleNamesOf($leaving, $organisation))->toBe([]);
});

it('restores the permissions team after a refusal', function (): void {
    $organisation = Organisation::factory()->create();
    $admin = asOrgAdmin($organisation);
    $before = PermissionsTeam::current();

    rescue(fn () => app(UpdateUserRoles::class)->handle($admin, $admin, []), report: false);

    expect(PermissionsTeam::current())->toBe($before);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Users/UpdateUserRolesTest.php`
Expected: FAIL — `Class "App\Actions\Users\UpdateUserRoles" not found`.

- [ ] **Step 3: Implement the action**

`app/Actions/Users/UpdateUserRoles.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Authorization\PermissionsTeam;
use App\Authorization\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * The only way to change a user's organisation roles (§4.6). Accepts roles of the target's
 * own team plus the global organisation_admin template (DEC-21 option A); the super_admin badge
 * and other teams' roles are rejected, and non-super-admins only hand out roles whose
 * permissions they hold (W1). Refuses removing your own organisation admin role and demoting
 * the last active organisation admin (W12).
 */
final class UpdateUserRoles
{
    /**
     * @param  list<int>  $roleIds
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, User $target, array $roleIds): void
    {
        Gate::forUser($actor)->authorize('updateRoles', $target);

        $teamId = $target->organisation_id ?? throw new LogicException('updateRoles allows only users with an organisation.');
        $roles = $this->resolveRoles($teamId, $roleIds);
        $keepsAdmin = $roles->contains(fn (RoleModel $role): bool => $role->name === Role::OrganisationAdmin->value);

        PermissionsTeam::run($teamId, function () use ($actor, $target, $roles, $keepsAdmin, $teamId): void {
            DB::transaction(function () use ($actor, $target, $roles, $keepsAdmin, $teamId): void {
                $target->unsetRelation('roles');
                $this->guardEscalation($actor, $target, $roles);

                if (! $keepsAdmin && $target->hasRole(Role::OrganisationAdmin->value)) {
                    $this->guardAdminRemoval($actor, $target, $teamId);
                }

                $target->syncRoles($roles);
                $target->unsetRelation('roles');
            });
        });
    }

    /**
     * @param  list<int>  $roleIds
     * @return Collection<int, RoleModel>
     *
     * @throws ValidationException
     */
    private function resolveRoles(int $teamId, array $roleIds): Collection
    {
        $roleIds = array_values(array_unique($roleIds));

        $roles = RoleModel::query()
            ->whereKey($roleIds)
            ->where(function (Builder $query) use ($teamId): void {
                $query->where('team_id', $teamId)->orWhere(function (Builder $template): void {
                    $template->whereNull('team_id')->where('name', Role::OrganisationAdmin->value);
                });
            })
            ->get();

        if ($roles->count() !== count($roleIds)) {
            throw ValidationException::withMessages([
                'roles' => __('One or more roles are not available for this organisation.'),
            ]);
        }

        return $roles;
    }

    /**
     * Non-super-admins may only give or take away roles whose permissions they hold themselves,
     * so roles.assign alone never leads to organisation admin (W1).
     *
     * @param  Collection<int, RoleModel>  $roles
     *
     * @throws ValidationException
     */
    private function guardEscalation(User $actor, User $target, Collection $roles): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        $currentIds = $target->roles()->pluck('roles.id')->map(fn (mixed $id): int => (int) $id)->all();
        $newIds = $roles->map(fn (RoleModel $role): int => (int) $role->getKey())->all();
        $changedIds = array_values(array_unique([...array_diff($newIds, $currentIds), ...array_diff($currentIds, $newIds)]));

        $actor->unsetRelation('roles');

        foreach (RoleModel::query()->whereKey($changedIds)->with('permissions')->get() as $role) {
            foreach ($role->permissions as $permission) {
                if (! $actor->checkPermissionTo($permission->name)) {
                    throw ValidationException::withMessages([
                        'roles' => __('You can only give or take away roles whose permissions you have yourself.'),
                    ]);
                }
            }
        }
    }

    /** @throws ValidationException */
    private function guardAdminRemoval(User $actor, User $target, int $teamId): void
    {
        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'roles' => __('You cannot remove your own organisation admin role.'),
            ]);
        }

        $templateId = RoleModel::query()->whereNull('team_id')->where('name', Role::OrganisationAdmin->value)->value('id');

        // pluck() instead of exists(): keeps FOR UPDATE out of an EXISTS subquery on PostgreSQL.
        $otherActiveAdmins = DB::table('model_has_roles')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.role_id', $templateId)
            ->where('model_has_roles.model_type', $target->getMorphClass())
            ->where('model_has_roles.team_id', $teamId)
            ->where('users.id', '!=', $target->id)
            ->where('users.status', UserStatus::Active->value)
            ->whereNull('users.deleted_at')
            ->lockForUpdate()
            ->pluck('users.id');

        if ($otherActiveAdmins->isEmpty()) {
            throw ValidationException::withMessages([
                'roles' => __('An organisation needs at least one active organisation admin.'),
            ]);
        }
    }
}
```

Add to both lang files:

| Key | nl |
|---|---|
| `An organisation needs at least one active organisation admin.` | `Een organisatie heeft minstens één actieve organisatiebeheerder nodig.` |
| `One or more roles are not available for this organisation.` | `Een of meer rollen zijn niet beschikbaar voor deze organisatie.` |
| `You can only give or take away roles whose permissions you have yourself.` | `Je kunt alleen rollen geven of afnemen waarvan je zelf alle rechten hebt.` |
| `You cannot remove your own organisation admin role.` | `Je kunt je eigen rol als organisatiebeheerder niet verwijderen.` |

Activity logging of the change is added in M4 together with spatie/laravel-activitylog.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Users/UpdateUserRolesTest.php`
Expected: PASS.

- [ ] **Step 5: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green. On PostgreSQL, `FOR UPDATE` with a join locks both tables' rows; that is intended.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add UpdateUserRoles with escalation and lock-out guards

- only the target team's roles and the organisation_admin template (W1)
- no self-demotion, never the last active organisation admin (W12)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 14: Route × host × role matrix

**Files:**
- Modify: `routes/web.php`, `tests/Feature/Auth/InviteOnlyTest.php`, `tests/Feature/ExampleTest.php`
- Test: `tests/Feature/Tenancy/RouteHostMatrixTest.php`

**Interfaces:**
- Consumes: testing API (Task 6), membership middleware (Task 8).
- Produces: `/` sends guests to login and signed-in users to the dashboard (§6.2 matrix; "super admin on central → organisations index" follows in M4); the dataset test that every later milestone extends with its routes, plus a check that every named GET route is either in the matrix or explicitly excluded.

- [ ] **Step 1: Write the test**

`tests/Feature/Tenancy/RouteHostMatrixTest.php`:

```php
<?php

declare(strict_types=1);

/**
 * covers §6.2
 *
 * Route × host × role matrix. Every milestone adds its named routes to matrixRoutes() and,
 * where the rule differs, to routeMatrixOutcome().
 */

use App\Models\Organisation;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/** @return list<string> */
function matrixRoutes(): array
{
    return [
        'home', 'login', 'password.request', 'two-factor.login', 'password.confirm',
        'dashboard', 'profile.edit', 'appearance.edit', 'security.edit',
    ];
}

dataset('matrix routes', matrixRoutes());
dataset('matrix actors', ['guest', 'member', 'organisation admin', 'super admin']);
dataset('matrix hosts', ['tenant', 'apex', 'admin']);

/** @return 'ok'|'login'|'dashboard'|'confirm' */
function routeMatrixOutcome(string $route, string $actor, string $host): string
{
    $signedIn = $actor !== 'guest';
    $belongsHere = $actor === 'super admin' || $host === 'tenant';

    return match (true) {
        $signedIn && ! $belongsHere => 'login',           // membership middleware logs out first
        $route === 'home' => $signedIn ? 'dashboard' : 'login',
        in_array($route, ['login', 'password.request', 'two-factor.login'], true) => match (true) {
            $signedIn => 'dashboard',                     // guest-only pages
            $route === 'two-factor.login' => 'login',     // no challenge in the session
            default => 'ok',
        },
        ! $signedIn => 'login',
        $route === 'security.edit' => 'confirm',
        default => 'ok',
    };
}

it('answers every route on every host for every role', function (string $route, string $actor, string $host): void {
    $organisation = Organisation::factory()->create();

    match ($actor) {
        'member' => asMember($organisation),
        'organisation admin' => asOrgAdmin($organisation),
        'super admin' => asSuperAdmin(),
        default => null,
    };

    match ($host) {
        'tenant' => onTenant($organisation),
        'apex' => onApex(),
        default => onAdminHost(),
    };

    $response = $this->get(route($route));

    match (routeMatrixOutcome($route, $actor, $host)) {
        'ok' => $response->assertOk(),
        'login' => $response->assertRedirect(route('login')),
        'dashboard' => $response->assertRedirect(route('dashboard')),
        'confirm' => $response->assertRedirect(route('password.confirm')),
    };
})->with('matrix routes')->with('matrix actors')->with('matrix hosts');

it('lists every named GET route in the matrix or the exclusions', function (): void {
    // Not pages: JSON endpoints, assets, and routes that need a token or a path parameter.
    $excluded = [
        'well-known.passkeys', 'password.reset', 'password.confirmation', 'passkey.confirm-options',
        'passkey.login-options', 'passkey.registration-options', 'two-factor.qr-code',
        'two-factor.secret-key', 'two-factor.recovery-codes', 'storage.local', '__flux.flag',
    ];

    $named = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => in_array('GET', $route->methods(), true))
        ->map(fn (RoutingRoute $route): ?string => $route->getName())
        ->filter(fn (?string $name): bool => $name !== null && ! str_starts_with($name, 'livewire.'))
        ->values();

    expect($named->diff([...matrixRoutes(), ...$excluded])->values()->all())->toBe([]);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Tenancy/RouteHostMatrixTest.php`
Expected: FAIL only for `home` as a guest (it redirects to `/dashboard`, the matrix expects login). If the completeness test names another vendor asset or JSON route, add it to `$excluded` with its kind in the comment; a page route goes into `matrixRoutes()` instead.

- [ ] **Step 3: Send guests from `/` straight to login**

In `routes/web.php` replace `Route::redirect('/', '/dashboard')->name('home');` with (import `Illuminate\Http\RedirectResponse`):

```php
Route::get('/', fn (): RedirectResponse => to_route(auth()->check() ? 'dashboard' : 'login'))->name('home');
```

`Route::get()` no longer answers DELETE, so in `tests/Feature/Auth/InviteOnlyTest.php`:
- remove the `'/' => RedirectController::class,` line from the expected DELETE table and change the comment above it to `// Route::redirect() registers an "any" route, so the settings redirect also accepts DELETE.`;
- replace the test `redirects the root url to the dashboard` with:

```php
it('sends guests from the root url to the login page', function (): void {
    $this->get('/')->assertRedirect(route('login'));
});
```

In `tests/Feature/ExampleTest.php` (keep the file: `pest --init` recreates it when missing, DEC-22) replace the test with:

```php
it('sends guests from the root url to the login page', function (): void {
    $this->followingRedirects()
        ->get('/')
        ->assertOk()
        ->assertSee(route('login.store'), escape: false);

    $this->get('/')->assertRedirect(route('login'));
    $this->get('/dashboard')->assertRedirect(route('login'));
});
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Tenancy/RouteHostMatrixTest.php tests/Feature/Auth/InviteOnlyTest.php tests/Feature/ExampleTest.php`
Expected: PASS (108 matrix cases plus the completeness check). A failure is a real behaviour difference: read the actual status/redirect, decide whether the code or `routeMatrixOutcome()` is wrong against spec §6.2, and fix that side. Do not special-case a single combination without a spec reason.

- [ ] **Step 5: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "test: add the route × host × role matrix

- / sends guests to login and signed-in users to the dashboard (§6.2)
- every named GET route is in the matrix or explicitly excluded

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 15: Strict mode on — tenancy and Eloquent

**Files:**
- Create: `app/Tenancy/Auth/UnscopedTwoFactorLoginRequest.php`, `app/Tenancy/Auth/UnscopedPasskey.php`
- Modify: `config/tenancy.php`, `.env.example`, `phpunit.xml`, `app/Providers/AppServiceProvider.php`, `app/Providers/TenancyServiceProvider.php`, `tests/Feature/AppDefaultsTest.php`, any test the suite run flags
- Test: `tests/Feature/Tenancy/StrictModeTest.php`, `tests/Feature/AppDefaultsTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: `tenancy.strict` defaults to `true`; `Model::shouldBeStrict(! app()->isProduction())`; Fortify's two-factor challenge and passkey login load the user without the tenant scope (super admins on central hosts; M3 adds the host rule).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Tenancy/StrictModeTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.0, §6.2 */

use App\Models\User;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use App\Tenancy\Hosts;
use Illuminate\Support\Facades\Route;

it('is on by default', function (): void {
    expect(config('tenancy.strict'))->toBeTrue();
});

it('refuses tenant queries on a central host outside a bypass', function (): void {
    Route::middleware('web')->get('/_count', fn (): int => User::query()->count());
    $this->withoutExceptionHandling();

    expect(fn () => $this->get(Hosts::url(Hosts::apex(), '/_count')))->toThrow(TenantNotResolvedException::class);
});

it('lets a super admin pass the two-factor challenge on a central host', function (): void {
    $superAdmin = User::factory()->superAdmin()->withTwoFactor()->create();
    onAdminHost();

    $this->post(route('login.store'), ['email' => $superAdmin->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    $this->get(route('two-factor.login'))->assertOk();
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($superAdmin);
});
```

In `tests/Feature/AppDefaultsTest.php`:
1. Add imports `Illuminate\Database\Console\Migrations\FreshCommand`, `Illuminate\Database\Console\WipeCommand`, `Illuminate\Database\Eloquent\Model`.
2. In the `finally` block of `prohibits destructive database commands in production`, add `Model::shouldBeStrict(true);` after `DB::prohibitDestructiveCommands(false);`, and add `->and(Model::preventsLazyLoading())->toBeFalse()` after the two `Artisan::call` expectations inside the `try` (production must not be strict).
3. Replace the weak `allows destructive database commands outside production` test (DEC-25) with:

```php
it('allows destructive database commands outside production', function (): void {
    new AppServiceProvider(app())->boot();

    expect(app()->isProduction())->toBeFalse()
        ->and(new ReflectionProperty(WipeCommand::class, 'prohibitedFromRunning')->getValue())->toBeFalse()
        ->and(new ReflectionProperty(FreshCommand::class, 'prohibitedFromRunning')->getValue())->toBeFalse();
});

it('makes Eloquent strict outside production', function (): void {
    expect(Model::preventsLazyLoading())->toBeTrue()
        ->and(Model::preventsSilentlyDiscardingAttributes())->toBeTrue()
        ->and(Model::preventsAccessingMissingAttributes())->toBeTrue();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Tenancy/StrictModeTest.php tests/Feature/AppDefaultsTest.php`
Expected: FAIL — `tenancy.strict` is false; Eloquent is not strict.

- [ ] **Step 3: Turn strict mode on**

1. `config/tenancy.php`: `'strict' => (bool) env('TENANCY_STRICT', true),`
2. `phpunit.xml`: add `<env name="TENANCY_STRICT" value="true"/>` next to the other `TENANCY_*` entries.
3. `.env.example`: add after the `APP_*` block:

```dotenv
# Tenancy: {slug}.{apex} are organisations; {apex} and admin.{apex} are central.
# Leave TENANCY_APEX_DOMAIN empty to use the APP_URL host.
TENANCY_APEX_DOMAIN=
TENANCY_ADMIN_SUBDOMAIN=admin
TENANCY_STRICT=true
```

4. `app/Providers/AppServiceProvider.php::configureDefaults()`: add `Model::shouldBeStrict(! app()->isProduction());` (import `Illuminate\Database\Eloquent\Model`).
5. `app/Providers/AppServiceProvider.php::configureDevCommands()`: add as the first statement (DEC-25):

```php
        if (! $this->app->runningInConsole()) {
            return;
        }
```

- [ ] **Step 4: Load the two-factor user without the tenant scope**

Fortify's challenge calls `$model::find(session('login.id'))` directly instead of going through the user provider, so the tenant scope applies; under strict mode that throws on central hosts and hides super admins on tenant hosts. M3 adds the host rule on top.

`app/Tenancy/Auth/UnscopedTwoFactorLoginRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use App\Tenancy\CurrentTenant;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

/** Fortify's two-factor challenge looks the user up without the tenant scope (§4.1). */
final class UnscopedTwoFactorLoginRequest extends TwoFactorLoginRequest
{
    public function hasChallengedUser()
    {
        return app(CurrentTenant::class)->bypass(fn (): bool => (bool) parent::hasChallengedUser());
    }

    public function challengedUser()
    {
        return app(CurrentTenant::class)->bypass(fn (): mixed => parent::challengedUser());
    }
}
```

In `app/Providers/TenancyServiceProvider.php::register()` add:

```php
        $this->app->bind(TwoFactorLoginRequest::class, UnscopedTwoFactorLoginRequest::class);
```

(imports `Laravel\Fortify\Http\Requests\TwoFactorLoginRequest`, `App\Tenancy\Auth\UnscopedTwoFactorLoginRequest`).

Laravel Passkeys loads `$passkey->user` through the same scope (`vendor/laravel/passkeys/src/Http/Controllers/PasskeyLoginController.php`, `Actions/VerifyPasskey.php`): under strict mode every passkey login on a central host would be a 500 (Appendix A P2). Load it unscoped until M3 adds the host rule:

`app/Tenancy/Auth/UnscopedPasskey.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use App\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passkeys\Passkey;

/** Passkey login loads its user without the tenant scope (§4.1, Appendix A P2). */
final class UnscopedPasskey extends Passkey
{
    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        return parent::user()->withoutGlobalScope(TenantScope::class);
    }
}
```

In `TenancyServiceProvider::boot()` add `Passkeys::usePasskeyModel(UnscopedPasskey::class);` (imports `Laravel\Passkeys\Passkeys`, `App\Tenancy\Auth\UnscopedPasskey`). It is already in the arch-preset ignore list (Task 3) because it extends an Eloquent model outside `App\Models`. Add to `StrictModeTest.php` (import `App\Tenancy\Auth\UnscopedPasskey`):

```php
it('loads a passkey user without the tenant scope on a central host', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();
    $passkey = new UnscopedPasskey;
    $passkey->setAttribute('user_id', $superAdmin->id);
    onAdminHost();

    expect($passkey->user()->first()?->is($superAdmin))->toBeTrue();
});
```

- [ ] **Step 5: Run the new tests**

Run: `php artisan test --compact tests/Feature/Tenancy/StrictModeTest.php tests/Feature/AppDefaultsTest.php`
Expected: PASS.

- [ ] **Step 6: Run the whole suite and fix the fallout**

Run: `php artisan test --compact`
Expected: failures, if any, are of three kinds. Fix each at its cause:
- `TenantNotResolvedException` in **test code** that reads tenant models without a tenant (for example `User::query()->count()` in an assertion): bind the right tenant (`onTenant($organisation)` / `Tenant::run($organisation, …)`) or, for cross-organisation assertions, wrap the read in `Tenant::bypass(…)` (tests are not covered by the bypass allow-list). `fresh()` / `refresh()` and saves are unscoped and need no change.
- `TenantNotResolvedException` in **app code**: the code reads a tenant model without a tenant. Bind the tenant where it is known, or move the read into an allowed namespace with `Tenant::bypass()`. Never turn strict mode off.
- `LazyLoadingViolationException` / `MissingAttributeException` / `MassAssignmentException`: eager-load or fill correctly in kit code. If a vendor package trips it, add a scoped `Model::handleLazyLoadingViolationUsing(...)` exception for that vendor relation only, record it in DEC-26 (Task 17) and keep strict mode on (§13).

- [ ] **Step 7: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: turn on tenancy strict mode and strict Eloquent

- tenant queries without a tenant throw outside the console and bypass
- Model::shouldBeStrict outside production; stronger app-default tests (DEC-25)
- Fortify's two-factor challenge loads the user without the tenant scope

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 16: Kit arch rules for tenancy and authorization

**Files:**
- Modify: `tests/Pest.php`, `tests/Arch/KitRulesTest.php`
- Create: `tests/Arch/TenancyRulesTest.php`, `tests/Arch/AuthorizationRulesTest.php`

**Interfaces:**
- Consumes: `Permission` (Task 3), `PermissionsTeam` (Task 2), `CurrentTenant::bypass` (Task 5).
- Produces: `kitSourceFiles(string ...$directories): list<string>` (base-relative paths of `.php` files, Blade included) in `tests/Pest.php`; arch rules of spec §7.2.

- [ ] **Step 1: Add the file helper**

Append to `tests/Pest.php` (imports `Illuminate\Support\Facades\File`, `Illuminate\Support\Str`):

```php
/**
 * Base-relative paths of every .php file (Blade included) under the given directories, for
 * rules that Pest's arch() cannot express: static method calls, string literals, anonymous
 * migration classes and closure-style test files.
 *
 * @return list<string>
 */
function kitSourceFiles(string ...$directories): array
{
    return array_values(collect($directories)
        ->filter(fn (string $directory): bool => is_dir(base_path($directory)))
        ->flatMap(fn (string $directory): array => File::allFiles(base_path($directory)))
        ->map(fn (SplFileInfo $file): string => Str::after($file->getPathname(), base_path().DIRECTORY_SEPARATOR))
        ->filter(fn (string $path): bool => str_ends_with($path, '.php'))
        ->all());
}

/**
 * @param  list<string>  $paths
 * @return list<string>
 */
function filesMatching(array $paths, string $pattern): array
{
    return array_values(array_filter(
        $paths,
        fn (string $path): bool => preg_match($pattern, (string) file_get_contents(base_path($path))) === 1,
    ));
}
```

- [ ] **Step 2: Write the rules**

`tests/Arch/TenancyRulesTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §6.2, §7.2 */

use Illuminate\Support\Str;

it('bypasses the tenant scope only in the allowed places', function (): void {
    // §6.2. Central Livewire components are added here from M4 on, one path at a time.
    $allowed = [
        'app/Console/', 'app/Setup/', 'app/Tenancy/', 'app/Actions/Audit/', 'database/seeders/',
        'app/Http/Controllers/HealthCheckController.php',
    ];

    $candidates = array_values(array_filter(
        kitSourceFiles('app', 'routes', 'resources/views', 'database'),
        fn (string $path): bool => ! Str::startsWith($path, $allowed),
    ));

    expect(filesMatching($candidates, '/Tenant::bypass\s*\(|->bypass\s*\(/'))->toBe([]);
});

it('keeps driver-specific SQL out of the app', function (): void {
    expect(filesMatching(kitSourceFiles('app', 'resources/views'), '/\bilike\b/i'))->toBe([]);
});
```

`tests/Arch/AuthorizationRulesTest.php`:

```php
<?php

declare(strict_types=1);

/** covers §4.6, §6.3, §7.2 */

use App\Authorization\Permission;

it('spells permission names only in the Permission enum', function (): void {
    $names = implode('|', array_map(fn (string $name): string => preg_quote($name, '/'), Permission::values()));
    $files = array_values(array_diff(kitSourceFiles('app', 'routes', 'resources/views'), ['app/Authorization/Permission.php']));

    expect(filesMatching($files, "/(['\"])({$names})\\1/"))->toBe([]);
});

it('changes the Spatie team id only in PermissionsTeam', function (): void {
    $files = array_values(array_diff(
        kitSourceFiles('app', 'routes', 'resources/views', 'database', 'tests'),
        ['app/Authorization/PermissionsTeam.php', 'tests/Arch/AuthorizationRulesTest.php'],
    ));

    expect(filesMatching($files, '/setPermissionsTeamId\s*\(/'))->toBe([]);
});

arch('actions never depend on Livewire')
    ->expect('App\Actions')
    ->not->toUse('Livewire');
```

Add to `tests/Arch/KitRulesTest.php` (DEC-25: the namespace arch rule misses closure-style Pest files and anonymous migrations):

```php
it('has no debugging leftovers in tests and migrations', function (): void {
    $files = array_values(array_diff(kitSourceFiles('tests', 'database/migrations'), ['tests/Arch/KitRulesTest.php']));

    expect(filesMatching($files, '/(?<![\w$>:\\\\])(dd|dump|ray|var_dump)\s*\(|->(dd|dump)\s*\(/'))->toBe([]);
});
```

(`KitRulesTest.php` has no imports today; keep the docblock first and add none — the helper functions are global.)

- [ ] **Step 3: Prove each rule can fail**

For each rule, temporarily add a violation, run the arch suite and see that exact rule fail, then remove the violation:
- add `// Tenant::bypass(` to `app/Policies/UserPolicy.php` (outside the allow-list).
- add `'users.view'` in a comment string in `routes/web.php` (for example `// 'users.view'`).
- add `// setPermissionsTeamId(1)` to `app/Authorization/SetSuperAdmin.php`.
- add `// ilike` to `app/Models/Organisation.php`.
- add `// dd(1);` to `tests/Feature/ExampleTest.php`.

Run after each: `php artisan test --compact tests/Arch`
Expected: the matching rule FAILS and names the file. After removing all violations: PASS. `git diff` shows no leftovers.

- [ ] **Step 4: Run the full checks on both databases**

Run: `composer fix && composer check && composer test:pgsql`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "test: add tenancy and authorization arch rules

- Tenant::bypass allow-list, no permission literals, team id only in PermissionsTeam
- actions independent of Livewire, no ilike, no debug calls in tests or migrations (DEC-25)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 17: Decision record, changelog and agent guidelines

**Files:**
- Modify: `docs/decisions.md`, `CHANGELOG.md`, `.ai/guidelines/skv3.md`, `CLAUDE.md`

**Interfaces:**
- Consumes: the M2 code.
- Produces: DEC-26; guidance that later milestones' agents load.

- [ ] **Step 1: Record DEC-26**

Append to `docs/decisions.md` under `## Build decisions`:

```markdown
### DEC-26 — Tenancy and authorization build rulings (M2)

**Question:** Which choices made while building M2 refine the spec, and what is deferred?
**Answer:** (1) The `super_admin` role is a badge without permissions; `users.is_super_admin` plus `Gate::before` (`App\Authorization\SuperAdminGate`) is the only grant. The always-checked abilities (`impersonate`, `delete`, `forceDelete`, `updateStatus`, `updateRoles`, `updateSuperAdmin`) return null for super admins so their policies run; those policies grant super admins explicitly. (2) Console code runs centrally through a `CommandStarting` listener that sets a plain singleton (`CentralContext`), not the scoped `CurrentTenant`, because the queue worker resets scoped instances before every job; a job that carries a tenant still binds it and a bound tenant always scopes. (3) Queued jobs restore the previous tenant after they finish (not just forget it), so a synchronous job inside a request keeps the request's tenant; a job whose organisation is gone fails without retry. (4) The slug cache stores organisation ids only, because `cache.serializable_classes` is false. (5) The organisations migration is named `0001_01_00_000000_…` so it sorts before the users table and `users.organisation_id` is created inline (no `ALTER TABLE` on SQLite). (6) Hosts outside the apex get the friendly tenant 404, like unknown slugs. (7) Tests pin the apex to `localhost` (`{slug}.localhost`, `admin.localhost`) and set hosts with `URL::useOrigin()`; `Livewire::test()` skips middleware, so host rules are tested over HTTP, including a forged Livewire update. (8) Fortify's two-factor challenge loads the user through `Tenant::bypass()` (`UnscopedTwoFactorLoginRequest`), because it calls `$model::find()` instead of the user provider. (9) DEC-21's consequence stands: a user cannot hold the same role in two teams; `UpdateUserRoles` only attaches roles in the target's own team. (10) The cascade uses bulk updates without model events and never archives a super admin (a promoted super admin keeps its organisation_id); M4 wraps archive and restore in an action with a transaction and audit logging. (11) The Pest Laravel arch preset ignores, on purpose, the `App\Authorization` enums (§6.1), `App\Tenancy\Exceptions` and the two vendor subclasses `UnscopedTwoFactorLoginRequest` and `UnscopedPasskey`. (12) Non-super-admins can only give or take away roles whose permissions they hold themselves, so `roles.assign` alone never leads to organisation admin; organisation admins never view or edit a super admin. (13) The central context lasts for a command's lifetime (`CommandStarting` → `CommandFinished`), so an `Artisan::call()` in a web request does not switch strict mode off afterwards. (14) `Tenant::run()` destroys a returned `PendingDispatch` while the tenant is bound, so `Tenant::run($organisation, fn () => dispatch(...))` queues under that tenant. (15) The slug cache verifies the cached id still carries the slug and never caches misses. (16) Passkey login loads its user unscoped (`UnscopedPasskey`) until M3 adds the host rule (P2).
**Evidence:** `tests/Feature/Tenancy/*`, `tests/Feature/Authorization/*`, `tests/Feature/Users/UpdateUserRolesTest.php`, `tests/Arch/{Tenancy,Authorization}RulesTest.php`; vendor reads of Laravel 13.35 (`QueueServiceProvider::resetScope`, `TwoFactorLoginRequest`), Livewire 4.4.7 (`PersistentMiddleware`, `RequestBroker`) and spatie/laravel-permission 8.3.0 (`Role::findByParam`).
**Consequence:** Deferred: M3 — host-scoped login (incl. `Passkeys::authorizeLoginUsing()`), passkey origin listener, handoff, `AuthenticateSession`; M4 — admin UI, super admin on a central `/` → organisations index (§6.2 matrix), `DeleteUser` / `UpdateUserStatus` actions, `RolePolicy`, impersonation incl. the impersonation-aware membership check, activity logging of role and super-admin changes, `#[RequiresTenant]`, slug validation rule; M5 — tenant name in the brand, per-user locale; M6 — doctor checks for the template role and badge drift, `skv:create-super-admin`, demo seeder (the development seeder's `test@example.com` super admin is temporary).
```

If Task 15 needed a vendor lazy-loading exception, add it as point (17).

- [ ] **Step 2: Update the changelog**

In `CHANGELOG.md` under `## [Unreleased]` → `### Added`, add:

```markdown
- Multi-tenancy: organisations on their own subdomain, a strict tenant scope, `Route::tenant()` / `Route::central()` groups, tenant-aware queues and a friendly page for unknown organisations.
- Roles and permissions: a fixed permission catalogue, `permissions:sync`, a global organisation admin role and a super-admin flag with guards against lock-out and privilege escalation.
```

- [ ] **Step 3: Teach the agents**

Append to `.ai/guidelines/skv3.md`:

```markdown
## Tenancy and authorization

- Hosts: `{slug}.{apex}` is an organisation, `{apex}` and `admin.{apex}` are central. Group single-host routes with `Route::tenant()` / `Route::central()`; never use `Route::domain()` or a `{tenant}` route parameter.
- Models with `organisation_id` use `App\Tenancy\Concerns\BelongsToOrganisation` and `SoftDeletes` and are listed in `config('tenancy.tenant_models')`.
- Strict mode: reading a tenant model without a bound tenant throws. Bind one with `Tenant::run($organisation, fn () => …)`; read across organisations with `Tenant::bypass(fn () => …)` only in `App\Console`, `App\Setup`, `App\Tenancy`, `App\Actions\Audit`, `Database\Seeders` and central components.
- Permissions: use the `App\Authorization\Permission` enum (`$user->can(Permission::UsersView)`, `->can(Permission::UsersView)` on routes); never a permission string. Switch teams only with `withPermissionsTeam($teamId, fn () => …)`.
- Super admin = `users.is_super_admin`, changed only by `App\Authorization\SetSuperAdmin`. Organisation roles change only through `App\Actions\Users\UpdateUserRoles`.
- Tests: `onTenant($organisation)`, `onApex()`, `onAdminHost()`, `asSuperAdmin()`, `asOrgAdmin($organisation)`, `asMember($organisation)`. `Livewire::test()` skips all middleware, so test host rules with HTTP requests.
```

Add the same section, shortened to these bullets, to `CLAUDE.md` under a new `## Tenancy and authorization` heading after `## Conventions` (keep `CLAUDE.md` under 200 lines).

Run: `php artisan boost:update` (regenerates the gitignored `AGENTS.md` from `.ai/guidelines`).

- [ ] **Step 4: Run the checks**

Run: `composer check`
Expected: green (`AiConfigTest` and `NameLeakTest` scan these files).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "docs: record M2 rulings in DEC-26 and teach agents the tenancy rules

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 18: Milestone gate

**Files:** none (verification, review and merge).

- [ ] **Step 1: Verify on both databases from a clean tree**

Run: `git status --short` → expected: empty.
Run: `composer check` → expected: Pint, Rector, Larastan and Pest all green.
Run: `composer test:pgsql` → expected: green on PostgreSQL.

- [ ] **Step 2: Check traceability**

Run: `grep -L "covers §" $(git diff --name-only --diff-filter=A main -- 'tests/**/*.php' | grep -v Fixtures)`
Expected: no output (every new test file starts with its `covers §` docblock).

- [ ] **Step 3: Check the spec coverage of M2**

Walk spec §6.2, §6.3 and the M2 parts of §4.0, §4.1, §4.4, §4.6 and §7.2, and confirm each bullet is implemented and tested in this branch or listed as deferred in DEC-26. Fix gaps before review.

- [ ] **Step 4: Whole-branch review**

Use superpowers:requesting-code-review on `main...m2-tenancy-authorization` with this plan and the spec sections as context. Apply verified findings with TDD, re-run Step 1.

- [ ] **Step 5: Merge after the owner's approval**

Ask the owner to approve the merge. After approval:

```bash
git switch main
git merge --no-ff m2-tenancy-authorization -m "Merge M2: tenancy and authorization

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git branch -d m2-tenancy-authorization
```

Run: `composer check && composer test:pgsql` on `main` → expected: green.
