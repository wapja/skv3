<?php

declare(strict_types=1);

/** covers §4.5, §6.2, Appendix A W5, W14 */

use App\Actions\Invitations\AcceptInvitation;
use App\Actions\Invitations\InviteUser;
use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Enums\ActivityEvent;
use App\Enums\Locale;
use App\Enums\UserStatus;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;

$tokenOf = fn (Invitation $invitation): string => $invitation->token ?? throw new LogicException('The invitation keeps no token.');

it('shows the form on the invitation\'s host and 404 on another tenant host, the apex and the admin host (W14)', function (): void {
    $invitation = inviteMember(Organisation::factory()->create(['slug' => 'acme']));
    Organisation::factory()->create(['slug' => 'beta']);
    $link = acceptLinkFor($invitation);

    $this->get($link)
        ->assertOk()
        ->assertSee(__('Choose your name and a password to activate your account.'));

    foreach (['beta.localhost', 'localhost', 'admin.localhost'] as $host) {
        $this->get(str_replace('acme.localhost', $host, $link))->assertNotFound();
    }

    expect(DB::table('users')->where('id', $invitation->user_id)->value('status'))->toBe(UserStatus::PendingActivation->value);
});

it('activates the account with the chosen name and signs the invitee in (W14)', function () use ($tokenOf): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $invitation = inviteMember($organisation);
    onTenant($organisation);

    Livewire::test('pages::invitations.accept', ['token' => $tokenOf($invitation)])
        ->set('name', 'Ann Invitee')
        ->set('password', 'secret-password')
        ->set('password_confirmation', 'secret-password')
        ->call('accept')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $invitee = User::query()->findOrFail($invitation->user_id);

    expect($invitee->name)->toBe('Ann Invitee')
        ->and($invitee->status)->toBe(UserStatus::Active)
        ->and(Hash::check('secret-password', $invitee->password))->toBeTrue()
        ->and($invitation->refresh()->accepted_at)->not->toBeNull()
        ->and(session('status'))->toBe(__('Welcome, your account is ready.'));
    $this->assertAuthenticatedAs($invitee);
});

it('shows why an accept that waited on an archive is refused (CD-5, G4-07)', function () use ($tokenOf): void {
    // The archive commits while the accept waits for the organisation lock; HostScope::lockForWrite() then refuses on
    // the "organisation" key, which the page did not render, so the button did nothing visible.
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $invitation = inviteMember($organisation);
    onTenant($organisation);
    $page = Livewire::test('pages::invitations.accept', ['token' => $tokenOf($invitation)])
        ->set('name', 'Ann Invitee')
        ->set('password', 'secret-password')
        ->set('password_confirmation', 'secret-password');
    DB::table('organisations')->where('id', $organisation->id)->update(['deleted_at' => now()]);

    $page->call('accept')
        ->assertHasErrors(['organisation'])
        ->assertSee(__('This organisation is archived.'));

    expect(DB::table('users')->where('id', $invitation->user_id)->value('status'))->toBe(UserStatus::PendingActivation->value);
});

it('shows the expired page after the domain expiry and answers 403 after the signature grace (INV-4)', function (): void {
    $link = acceptLinkFor(inviteMember(Organisation::factory()->create(['slug' => 'acme'])));

    $this->travel(8)->days();
    $this->get($link)
        ->assertOk()
        ->assertSee(__('This invitation has expired'))
        ->assertDontSee(__('Resend invitation'));

    // 40 days in: past expires_at (7 days) plus the 30-day signature grace.
    $this->travel(32)->days();
    $this->get($link)->assertForbidden();
});

it('refuses a forged accept on an expired invitation within the signature grace (INV-4)', function () use ($tokenOf): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $invitation = inviteMember($organisation);
    onTenant($organisation);
    $this->travel(8)->days();

    // The page still mounts in the grace window and Livewire updates skip the signature, so the action's re-check decides.
    Livewire::test('pages::invitations.accept', ['token' => $tokenOf($invitation)])
        ->set('name', 'Mallory')
        ->set('password', 'secret-password')
        ->set('password_confirmation', 'secret-password')
        ->call('accept')
        ->assertHasErrors(['invitation' => __('This invitation has expired.')]);

    expect(User::query()->findOrFail($invitation->user_id)->status)->toBe(UserStatus::PendingActivation)
        ->and($invitation->refresh()->accepted_at)->toBeNull();
    $this->assertGuest();
});

it('shows "already accepted — please log in" for an accepted invitation (W5)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $invitation = inviteMember($organisation);
    $link = acceptLinkFor($invitation);
    onTenant($organisation);
    app(AcceptInvitation::class)->handle($invitation, 'Ann Invitee', 'secret-password');

    $this->get($link)
        ->assertOk()
        ->assertSee(__('This invitation has already been accepted. Please log in.'))
        ->assertSee(route('login'))
        ->assertDontSee('wire:model="password"', false);
});

it('offers resend on the expired page only to users who may remind (INV-11)', function (string $visitor, bool $offered): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $link = acceptLinkFor(inviteMember($organisation));

    if ($visitor === 'member') {
        asMember($organisation);
    } elseif ($visitor === 'organisation admin') {
        asOrgAdmin($organisation);
    }

    $this->travel(8)->days();
    $response = $this->get($link)->assertOk()->assertSee(__('This invitation has expired'));

    if ($offered) {
        $response->assertSee(__('Resend invitation'));
    } else {
        $response->assertDontSee(__('Resend invitation'));
    }
})->with([
    'guest' => ['guest', false],
    'member without users.invite.resend' => ['member', false],
    'organisation admin' => ['organisation admin', true],
]);

it('shows a signed-in admin opening an expired invitation the resend button (OR-I10)', function () use ($tokenOf): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $invitation = inviteMember($organisation);
    $admin = asOrgAdmin($organisation);
    $this->travel(8)->days();

    Livewire::test('pages::invitations.accept', ['token' => $tokenOf($invitation)])
        ->assertSee(__('This invitation has expired'))
        ->assertSee(__('Resend invitation'))
        ->call('resend')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show')
        ->assertSee(__('You are signed in as :name.', ['name' => $admin->name]));

    expect($invitation->refresh()->isExpired())->toBeFalse()
        ->and($invitation->reminder_sent_at)->not->toBeNull();
});

it('shows a signed-in visitor who they are instead of the form (RL-15)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $link = acceptLinkFor(inviteMember($organisation));
    $member = asMember($organisation);

    $this->get($link)
        ->assertOk()
        ->assertSee(__('You are signed in as :name.', ['name' => $member->name]))
        ->assertSee(__('Log out first to accept this invitation.'))
        ->assertDontSee('wire:model="password"', false);
});

it('refuses a forged accept from a signed-in visitor (RK-M3)', function () use ($tokenOf): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $invitation = inviteMember($organisation);
    $member = asMember($organisation);

    Livewire::test('pages::invitations.accept', ['token' => $tokenOf($invitation)])
        ->set('name', 'Mallory')
        ->set('password', 'secret-password')
        ->set('password_confirmation', 'secret-password')
        ->call('accept')
        ->assertForbidden();

    expect(User::query()->findOrFail($invitation->user_id)->status)->toBe(UserStatus::PendingActivation);
    $this->assertAuthenticatedAs($member);
});

/**
 * One database connection cannot race two requests, so this proves the state re-check on the
 * freshly locked row (the stale model still says "open"), not the row lock itself (X9).
 */
it('refuses a second acceptance of the same invitation (X9)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $invitation = inviteMember($organisation);
    onTenant($organisation);
    app(AcceptInvitation::class)->handle($invitation, 'Ann Invitee', 'secret-password');

    expect(fn () => app(AcceptInvitation::class)->handle($invitation, 'Mallory', 'other-password'))
        ->toThrow(ValidationException::class, __('This invitation has already been accepted. Please log in.'));
    expect(User::query()->findOrFail($invitation->user_id)->name)->toBe('Ann Invitee');
});

it('refuses to accept for a pending user that was deleted or changed meanwhile (RK-M3)', function (string $change): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $invitation = inviteMember($organisation);
    onTenant($organisation);
    $invitee = User::query()->findOrFail($invitation->user_id);

    if ($change === 'deleted') {
        $invitee->delete();
    } else {
        $invitee->forceFill(['status' => UserStatus::Disabled])->save();
    }

    expect(fn () => app(AcceptInvitation::class)->handle($invitation, 'Ann Invitee', 'secret-password'))
        ->toThrow(ValidationException::class, __('This invitation is no longer valid.'));
    expect($invitation->refresh()->accepted_at)->toBeNull();
})->with(['deleted', 'no longer pending']);

it('refuses to accept for a pending user who was made a super admin (§6.3)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $invitation = inviteMember($organisation);
    onTenant($organisation);
    // A direct write stands in for any path that ever promotes a pending user.
    User::query()->findOrFail($invitation->user_id)->forceFill(['is_super_admin' => true])->save();

    expect(fn () => app(AcceptInvitation::class)->handle($invitation, 'Mallory', 'secret-password'))
        ->toThrow(ValidationException::class, __('This invitation is no longer valid.'));
    expect(User::query()->findOrFail($invitation->user_id)->status)->toBe(UserStatus::PendingActivation)
        ->and($invitation->refresh()->accepted_at)->toBeNull();
});

it('refuses a forged accept from another tenant host (W14)', function (): void {
    $invitation = inviteMember(Organisation::factory()->create(['slug' => 'acme']));
    Organisation::factory()->create(['slug' => 'beta']);
    $snapshot = pageSnapshot(acceptLinkFor($invitation), 'pages::invitations.accept');

    // Livewire updates skip the route signature, so the host binding rests on the tenant-scoped lookup.
    callPageAction($snapshot, 'accept', [], [
        'name' => 'Mallory',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ], host: 'beta.localhost')->assertNotFound();

    expect(DB::table('users')->where('id', $invitation->user_id)->value('status'))->toBe(UserStatus::PendingActivation->value);
    $this->assertGuest();
});

it('clears the stored token on accept so the link cannot be rebuilt (RK-B3)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $invitation = inviteMember($organisation);
    $hash = $invitation->token_hash;
    onTenant($organisation);

    app(AcceptInvitation::class)->handle($invitation, 'Ann Invitee', 'secret-password');

    expect(DB::table('invitations')->where('id', $invitation->id)->value('token'))->toBeNull()
        ->and(DB::table('invitations')->where('id', $invitation->id)->value('token_hash'))->toBe($hash)
        ->and(fn () => $invitation->refresh()->acceptUrl())->toThrow(LogicException::class);
});

it('sends no referrer from the accept page (RK-M12)', function (): void {
    $link = acceptLinkFor(inviteMember(Organisation::factory()->create(['slug' => 'acme'])));

    $this->get($link)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
});

it('renders in the invitee\'s language', function (): void {
    $organisation = Organisation::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $superAdmin = User::factory()->superAdmin()->create();
    app(SetSuperAdmin::class)->handle($superAdmin, true);
    $templateId = (int) RoleModel::query()->whereNull('team_id')->where('name', Role::OrganisationAdmin->value)->value('id');
    $invitation = app(InviteUser::class)->handle($superAdmin, $organisation, 'invitee@example.com', [$templateId], Locale::Dutch);

    $this->get(acceptLinkFor($invitation))
        ->assertOk()
        ->assertSee('lang="nl"', false)
        ->assertSee('Welkom bij Acme')
        ->assertSee('Kies je naam en een wachtwoord om je account te activeren.');

    expect(now()->subDay()->diffForHumans())->toContain('geleden');
});

it('lets a guest\'s own language choice win over the invitee\'s language (DEC-29)', function (): void {
    $organisation = Organisation::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $link = acceptLinkFor(inviteMember($organisation));
    onTenant($organisation);

    $this->get($link)->assertOk()->assertSee('lang="en"', false);

    $this->from($link)->post(route('locale.update'), ['locale' => Locale::Dutch->value])->assertRedirect($link);

    $this->get($link)
        ->assertOk()
        ->assertSee('lang="nl"', false)
        ->assertSee('Kies je naam en een wachtwoord om je account te activeren.');

    $response = callPageAction(pageSnapshot($link, 'pages::invitations.accept'), '$refresh');

    $response->assertOk();
    expect((string) $response->getContent())->toContain('Kies je naam en een wachtwoord om je account te activeren.')
        ->and(now()->subDay()->diffForHumans())->toContain('geleden');
});

it('renders in a signed-in visitor\'s own language (DEC-29)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $link = acceptLinkFor(inviteMember($organisation));
    $member = asMember($organisation);
    $member->forceFill(['locale' => Locale::Dutch])->save();

    $this->get($link)
        ->assertOk()
        ->assertSee('lang="nl"', false)
        ->assertSee('Je bent ingelogd als '.e($member->name).'.', false);
});

it('rejects a tampered signature with 403', function (): void {
    $link = acceptLinkFor(inviteMember(Organisation::factory()->create(['slug' => 'acme'])));

    $this->get((string) preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $link))->assertForbidden();
});

it('records invitation.accepted and a sign-in', function () use ($tokenOf): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $invitation = inviteMember($organisation);
    onTenant($organisation);

    Livewire::test('pages::invitations.accept', ['token' => $tokenOf($invitation)])
        ->set('name', 'Ann Invitee')
        ->set('password', 'secret-password')
        ->set('password_confirmation', 'secret-password')
        ->call('accept');

    $accepted = recordedActivity(ActivityEvent::InvitationAccepted)->sole();

    expect($accepted->subject_id)->toBe($invitation->user_id)
        ->and($accepted->causer_id)->toBe($invitation->user_id)
        ->and($accepted->organisation_id)->toBe($organisation->id)
        ->and(recordedActivity(ActivityEvent::Login))->toHaveCount(1);
});

it('explains a cancelled or unknown invitation link instead of a bare 404 (DEC-28 (15))', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    onTenant($organisation);
    $url = URL::temporarySignedRoute('invitations.accept', now()->addDay(), ['token' => str_repeat('x', 64)], absolute: false);

    $this->get(tenant_url($organisation, $url))
        ->assertNotFound()
        ->assertSee(__('This invitation is no longer valid'));
});
