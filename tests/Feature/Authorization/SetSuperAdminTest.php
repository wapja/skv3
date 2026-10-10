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
