<?php

declare(strict_types=1);

/** covers §4.6, §6.3 */

use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Authorization\SyncPermissions;
use App\Enums\ActivityEvent;
use App\Enums\UserStatus;
use App\Models\Organisation;
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
    $leaver = User::factory()->create();
    app(SetSuperAdmin::class)->handle($leaver, true);

    app(SetSuperAdmin::class)->handle($leaver, false);

    expect($leaver->fresh()?->isSuperAdmin())->toBeFalse()
        ->and(badgeRows($leaver))->toBe([]);
});

it('refuses to revoke a super admin who has no organisation', function (): void {
    $keeper = User::factory()->superAdmin()->create();
    app(SetSuperAdmin::class)->handle($keeper, true);
    $central = User::factory()->superAdmin()->create();
    app(SetSuperAdmin::class)->handle($central, true);

    expect(fn () => app(SetSuperAdmin::class)->handle($central, false))
        ->toThrow(ValidationException::class, __('Assign an organisation before removing super admin.'));

    expect($central->fresh()?->isSuperAdmin())->toBeTrue()
        ->and(badgeRows($central))->toBe([null]);
});

it('refuses to revoke the last active super admin', function (): void {
    // A promoted super admin with an organisation: an unattached one would hit the W8 guard first.
    $last = User::factory()->create();
    app(SetSuperAdmin::class)->handle($last, true);
    User::factory()->superAdmin()->disabled()->create();
    User::factory()->superAdmin()->create()->delete();

    expect(fn () => app(SetSuperAdmin::class)->handle($last, false))
        ->toThrow(ValidationException::class, __('At least one active super admin must remain.'));

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

it('decides on the locked row, not on a stale model (FU2-5)', function (): void {
    // Fails on main: the stale instance still said "super admin", so the demotion ran the last-super-admin
    // check again and refused, although the stored flag was already off.
    $organisation = Organisation::factory()->create();
    $keeper = User::factory()->superAdmin()->create();
    app(SetSuperAdmin::class)->handle($keeper, true);
    $promoted = User::factory()->for($organisation)->create();
    app(SetSuperAdmin::class)->handle($promoted, true);
    $stale = $promoted->fresh() ?? throw new LogicException('The promoted user is missing.');

    app(SetSuperAdmin::class)->handle($promoted, false);
    DB::table('users')->where('id', $keeper->id)->update(['status' => UserStatus::Disabled->value]);

    app(SetSuperAdmin::class)->handle($stale, false);

    expect($stale->fresh()?->isSuperAdmin())->toBeFalse()
        ->and($stale->isSuperAdmin())->toBeFalse()
        ->and(badgeRows($stale))->toBe([])
        ->and(recordedActivity(ActivityEvent::SuperAdminRevoked))->toHaveCount(1);
});

it('attaches the badge even when the flag was already set, and records nothing (OR-I4)', function (): void {
    $user = User::factory()->superAdmin()->create();
    expect(badgeRows($user))->toBe([]);

    app(SetSuperAdmin::class)->handle($user, true);

    expect(badgeRows($user))->toBe([null])
        ->and(recordedActivity(ActivityEvent::SuperAdminGranted))->toBeEmpty();
});

it('refuses to demote a promoted super admin while their organisation is archived (FU3-4)', function (): void {
    // Fails on main: the demotion went through and left a member whose home host is an archived organisation.
    $keeper = User::factory()->superAdmin()->create();
    app(SetSuperAdmin::class)->handle($keeper, true);
    $organisation = Organisation::factory()->create();
    $promoted = User::factory()->for($organisation)->create();
    app(SetSuperAdmin::class)->handle($promoted, true);
    $organisation->delete();

    expect(fn () => app(SetSuperAdmin::class)->handle($promoted, false))
        ->toThrow(ValidationException::class, __('Restore the organisation before removing super admin.'));

    expect($promoted->fresh()?->isSuperAdmin())->toBeTrue()
        ->and(badgeRows($promoted))->toBe([null])
        ->and(recordedActivity(ActivityEvent::SuperAdminRevoked))->toBeEmpty();
});
