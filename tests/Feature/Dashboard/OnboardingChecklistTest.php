<?php

declare(strict_types=1);

/** covers §6.5 */

use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Support\Dashboard\OnboardingChecklist;

it('gives super admins the platform checklist on central hosts', function (): void {
    config(['branding.accent' => 'neutral', 'branding.logo' => null]);
    $admin = asSuperAdmin();
    onAdminHost();

    expect(array_column(OnboardingChecklist::for($admin), 'done', 'key'))->toBe([
        'create-organisation' => false,
        'invite-admin' => false,
        'two-factor' => false,
        'branding' => false,
    ]);
});

it('ticks the platform items off as they happen', function (): void {
    config(['branding.accent' => 'indigo']);
    $organisation = Organisation::factory()->create();
    inviteMember($organisation);
    $admin = asSuperAdmin();
    $admin->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    onAdminHost();

    expect(OnboardingChecklist::isComplete(OnboardingChecklist::for($admin)))->toBeTrue();
});

it('gives organisation admins their own checklist on their host', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);

    expect(array_column(OnboardingChecklist::for($admin), 'done', 'key'))->toBe([
        'invite-colleague' => false,
        'two-factor' => false,
    ]);

    User::factory()->for($organisation)->create();

    expect(array_column(OnboardingChecklist::for($admin), 'done', 'key')['invite-colleague'])->toBeTrue();
});

it('does not count the admin\'s own accepted invitation as inviting a colleague', function (): void {
    // AcceptInvitation keeps the row (accepted_at set, token cleared); the usual first admin joined that way.
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);
    Invitation::factory()->for($organisation)->for($admin, 'user')->accepted()->create();

    expect(array_column(OnboardingChecklist::for($admin), 'done', 'key')['invite-colleague'])->toBeFalse();
});

it('gives members no checklist', function (): void {
    $member = asMember(Organisation::factory()->create(['slug' => 'acme']));

    expect(OnboardingChecklist::for($member))->toBe([]);
});

it('hides the checklist on the dashboard once every item is done', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);

    $this->get(route('dashboard'))->assertOk()->assertSee('data-test="onboarding-checklist"', false);

    User::factory()->for($organisation)->create();
    $admin->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();

    $this->get(route('dashboard'))->assertOk()->assertDontSee('data-test="onboarding-checklist"', false);
});
