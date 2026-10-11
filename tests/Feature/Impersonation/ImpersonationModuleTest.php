<?php

declare(strict_types=1);

/** covers §6.8, §7.2 */

use App\Authorization\Role;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/** A user of $organisation holding the organisation_admin template role (DEC-21), not signed in. */
$organisationAdmin = function (Organisation $organisation): User {
    $user = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $user->assignRole(Role::OrganisationAdmin->value));
    $user->unsetRelation('roles');

    return $user;
};

it('switches the whole impersonation module with its flag (IMP-11, IMP-12)', function (bool $enabled) use ($organisationAdmin): void {
    useImpersonationModule($enabled);
    $organisation = Organisation::factory()->create();
    $admin = $organisationAdmin($organisation);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    expect(Route::has('impersonation.stop'))->toBe($enabled)
        ->and(Gate::forUser($admin)->allows('impersonate', $member))->toBe($enabled);

    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])->assertRedirect();
    Auth::guard('web')->forgetUser();

    $index = $this->get(route('users.index'))->assertOk();

    if ($enabled) {
        $index->assertSee('data-test="impersonate-'.$member->id.'"', false);
    } else {
        $index->assertDontSee('data-test="impersonate-', false)->assertDontSee('data-test="impersonation-reason-modal"', false);
    }

    Auth::guard('web')->forgetUser();
    $snapshot = pageSnapshot(route('users.index'), 'pages::users.index');
    $confirmed = callPageAction($snapshot, 'confirmImpersonation', [$member->id])->assertStatus($enabled ? 200 : 404);

    Auth::guard('web')->forgetUser();

    if ($enabled) {
        /** @var array<string, mixed> $afterConfirm */
        $afterConfirm = json_decode((string) $confirmed->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);
        callPageAction($afterConfirm, 'startImpersonation', [], ['impersonationReason' => 'Ticket 42'])
            ->assertOk()
            ->assertJsonPath('components.0.effects.redirect', route('dashboard'));
    } else {
        callPageAction($snapshot, 'startImpersonation', [], ['impersonationReason' => 'Ticket 42'])->assertNotFound();
    }

    Auth::guard('web')->forgetUser();
    $dashboard = $this->get(route('dashboard'))->assertOk();

    if ($enabled) {
        $dashboard->assertSee('data-test="impersonation-banner"', false);
    } else {
        $dashboard->assertDontSee('data-test="impersonation-banner"', false);
    }

    Auth::guard('web')->forgetUser();
    $this->delete('/impersonation')->assertStatus($enabled ? 302 : 404);
})->with(['on' => [true], 'off' => [false]]);
