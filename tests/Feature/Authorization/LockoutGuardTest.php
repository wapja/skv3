<?php

declare(strict_types=1);

/** covers §4.4, §4.6, §6.3 */

use App\Authorization\LockoutGuard;
use App\Authorization\Role;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as RoleModel;

it('locks every candidate row in id order, the target included (RL-27)', function (): void {
    $organisation = Organisation::factory()->create();
    [$firstAdmin, $secondAdmin] = User::factory()->for($organisation)->count(2)->create()->all();
    withPermissionsTeam($organisation->id, function () use ($firstAdmin, $secondAdmin): void {
        $firstAdmin->assignRole(Role::OrganisationAdmin->value);
        $secondAdmin->assignRole(Role::OrganisationAdmin->value);
    });
    $firstSuperAdmin = User::factory()->superAdmin()->create();
    User::factory()->superAdmin()->create();

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    DB::transaction(function () use ($firstSuperAdmin, $firstAdmin, $organisation): void {
        app(LockoutGuard::class)->ensureAnotherActiveSuperAdmin($firstSuperAdmin, 'is_super_admin');
        app(LockoutGuard::class)->ensureAnotherActiveOrganisationAdmin($firstAdmin, $organisation->id, 'roles');
    });

    $superAdmins = collect($queries)->first(fn (string $sql): bool => str_contains($sql, '"is_super_admin"'));
    $organisationAdmins = collect($queries)->first(fn (string $sql): bool => str_contains($sql, '"model_has_roles"."role_id"'));

    foreach ([$superAdmins, $organisationAdmins] as $sql) {
        // The target is not filtered out in SQL (no "!=" / "<>"): it is locked with the others, in id order.
        expect($sql)->toBeString()
            ->toContain('order by "users"."id" asc')
            ->not->toContain('!=')
            ->not->toContain('<>');

        // SQLite has no row locks and compiles no FOR UPDATE clause.
        if (DB::getDriverName() !== 'sqlite') {
            expect($sql)->toContain('for update');
        }
    }
});

it('keeps the existing lock-out messages', function (): void {
    $onlySuperAdmin = User::factory()->superAdmin()->create();
    User::factory()->superAdmin()->disabled()->create();
    $organisation = Organisation::factory()->create();
    $onlyAdmin = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $onlyAdmin->assignRole(Role::OrganisationAdmin->value));

    // A try/catch instead of toThrow(Closure): Pest types that closure as Closure(Throwable), so a
    // ValidationException-typed parameter fails Larastan.
    try {
        DB::transaction(fn () => app(LockoutGuard::class)->ensureAnotherActiveSuperAdmin($onlySuperAdmin, 'is_super_admin'));
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['is_super_admin' => [__('At least one active super admin must remain.')]]);
    }

    try {
        DB::transaction(fn () => app(LockoutGuard::class)->ensureAnotherActiveOrganisationAdmin($onlyAdmin, $organisation->id, 'roles'));
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['roles' => [__('An organisation needs at least one active organisation admin.')]]);
    }
});

it('counts only active, non-deleted template holders of the same organisation as another admin', function (): void {
    $organisation = Organisation::factory()->create();
    $otherOrganisation = Organisation::factory()->create();
    $onlyAdmin = User::factory()->for($organisation)->create();
    $otherOrganisationAdmin = User::factory()->for($otherOrganisation)->create();
    $deletedAdmin = User::factory()->for($organisation)->create(['deleted_at' => now()]);
    $lookAlikeAdmin = User::factory()->for($organisation)->create();
    $lookAlikeRole = RoleModel::query()->create([
        'name' => Role::OrganisationAdmin->value,
        'guard_name' => 'web',
        'team_id' => $organisation->id,
    ]);

    withPermissionsTeam($organisation->id, function () use ($onlyAdmin, $deletedAdmin, $lookAlikeAdmin, $lookAlikeRole): void {
        $onlyAdmin->assignRole(Role::OrganisationAdmin->value);
        $deletedAdmin->assignRole(Role::OrganisationAdmin->value);
        $lookAlikeAdmin->assignRole($lookAlikeRole);
    });
    withPermissionsTeam($otherOrganisation->id, fn (): User => $otherOrganisationAdmin->assignRole(Role::OrganisationAdmin->value));

    // Each candidate is active, so each one alone would cover the organisation if its filter were dropped:
    // the team filter (another organisation's admin), the deleted_at filter, and the template-id filter.
    try {
        DB::transaction(fn () => app(LockoutGuard::class)->ensureAnotherActiveOrganisationAdmin($onlyAdmin, $organisation->id, 'roles'));
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['roles' => [__('An organisation needs at least one active organisation admin.')]]);
    }
});

it('counts only active, non-deleted super admins as another super admin', function (): void {
    $onlySuperAdmin = User::factory()->superAdmin()->create();
    User::factory()->superAdmin()->create(['deleted_at' => now()]);
    User::factory()->create();

    // Each candidate is active, so each one alone would keep a super admin if its filter were dropped: the
    // deleted_at filter (a deleted super admin) and the is_super_admin filter (a regular user).
    try {
        DB::transaction(fn () => app(LockoutGuard::class)->ensureAnotherActiveSuperAdmin($onlySuperAdmin, 'is_super_admin'));
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['is_super_admin' => [__('At least one active super admin must remain.')]]);
    }
});
