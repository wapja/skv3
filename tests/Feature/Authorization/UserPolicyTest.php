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

    return ['organisation' => $organisation, 'admin' => $admin, 'member' => $member, 'outsider' => $outsider, 'superAdmin' => $superAdmin, 'otherSuperAdmin' => $otherSuperAdmin];
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
