<?php

declare(strict_types=1);

/** covers §4.6.6, §7.2 */

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
