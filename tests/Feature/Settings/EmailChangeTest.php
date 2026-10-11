<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Actions\Settings\RequestEmailChange;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\EmailChangeRequested;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** @return array{0: Organisation, 1: User} */
function memberChangingEmail(): array
{
    Notification::fake();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create(['email' => 'old@example.com']);
    onTenant($organisation);

    return [$organisation, $member];
}

it('mails a confirmation link to the new address and a notice to the old one without changing anything yet (E1)', function (): void {
    [, $member] = memberChangingEmail();

    app(RequestEmailChange::class)->handle($member, 'New@Example.com');

    expect($member->fresh()?->email)->toBe('old@example.com')
        ->and(confirmationLinkFor('new@example.com'))->toStartWith('http://acme.localhost/settings/email/confirm/');
    Notification::assertSentOnDemand(
        EmailChangeRequested::class,
        fn (EmailChangeRequested $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notification->newEmail === 'new@example.com'
            && ($notifiable->routes['mail'] ?? null) === 'old@example.com',
    );
});

it('changes the address when the signed-in user follows the link', function (): void {
    [, $member] = memberChangingEmail();
    DB::table('password_reset_tokens')->insert(['email' => 'old@example.com', 'token' => 'x', 'created_at' => now()]);
    app(RequestEmailChange::class)->handle($member, 'new@example.com');

    $this->actingAs($member)->withSession(['auth.password_confirmed_at' => time()])->get(confirmationLinkFor('new@example.com'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', __('Your email address has been changed.'));

    expect($member->fresh()?->email)->toBe('new@example.com')
        ->and(DB::table('password_reset_tokens')->where('email', 'old@example.com')->exists())->toBeFalse();
});

it('sends a guest to the login page first', function (): void {
    [, $member] = memberChangingEmail();
    app(RequestEmailChange::class)->handle($member, 'new@example.com');

    $this->get(confirmationLinkFor('new@example.com'))->assertRedirect(route('login'));

    expect($member->fresh()?->email)->toBe('old@example.com');
});

it('refuses a tampered or expired link', function (string $case): void {
    [, $member] = memberChangingEmail();
    app(RequestEmailChange::class)->handle($member, 'new@example.com');
    $link = confirmationLinkFor('new@example.com');

    if ($case === 'tampered') {
        $link = str_replace('new%40example.com', 'evil%40example.com', $link);
    } else {
        $this->travel(61)->minutes();
    }

    $this->actingAs($member)->get($link)->assertForbidden();
    expect($member->fresh()?->email)->toBe('old@example.com');
})->with(['tampered', 'expired']);

it('refuses a link opened by another user', function (): void {
    [$organisation, $member] = memberChangingEmail();
    $colleague = User::factory()->for($organisation)->create();
    app(RequestEmailChange::class)->handle($member, 'new@example.com');

    $this->actingAs($colleague)->withSession(['auth.password_confirmed_at' => time()])->get(confirmationLinkFor('new@example.com'))->assertForbidden();
});

it('uses a link only once', function (): void {
    [, $member] = memberChangingEmail();
    app(RequestEmailChange::class)->handle($member, 'new@example.com');
    $link = confirmationLinkFor('new@example.com');
    $this->actingAs($member)->withSession(['auth.password_confirmed_at' => time()])->get($link);
    $member->refresh();

    $this->actingAs($member)->get($link)
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', __('This confirmation link is no longer valid.'));
});

it('accepts only the newest link', function (): void {
    [, $member] = memberChangingEmail();
    app(RequestEmailChange::class)->handle($member, 'first@example.com');
    app(RequestEmailChange::class)->handle($member, 'second@example.com');

    $this->actingAs($member)->withSession(['auth.password_confirmed_at' => time()])->get(confirmationLinkFor('first@example.com'))
        ->assertSessionHas('status', __('This confirmation link is no longer valid.'));

    expect($member->fresh()?->email)->toBe('old@example.com');

    $this->actingAs($member)->get(confirmationLinkFor('second@example.com'))
        ->assertSessionHas('status', __('Your email address has been changed.'));

    expect($member->fresh()?->email)->toBe('second@example.com');
});

it('refuses an address that was taken in the meantime', function (): void {
    [$organisation, $member] = memberChangingEmail();
    app(RequestEmailChange::class)->handle($member, 'new@example.com');
    User::factory()->for($organisation)->create(['email' => 'new@example.com']);

    $this->actingAs($member)->withSession(['auth.password_confirmed_at' => time()])->get(confirmationLinkFor('new@example.com'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', __('This address is already used in this organisation.'));

    expect($member->fresh()?->email)->toBe('old@example.com');
});

it('asks a session without a confirmed password to confirm it before the address changes', function (): void {
    [, $member] = memberChangingEmail();
    app(RequestEmailChange::class)->handle($member, 'attacker@example.com');

    $this->actingAs($member)->get(confirmationLinkFor('attacker@example.com'))
        ->assertRedirect(route('password.confirm'));

    expect($member->fresh()?->email)->toBe('old@example.com');
});

it('changes the address once the password is confirmed and the link is followed again', function (): void {
    [, $member] = memberChangingEmail();
    app(RequestEmailChange::class)->handle($member, 'new@example.com');
    $link = confirmationLinkFor('new@example.com');

    $this->actingAs($member)->get($link)->assertRedirect(route('password.confirm'));
    $this->post(route('password.confirm.store'), ['password' => 'password'])->assertRedirect($link);
    $this->get($link)
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', __('Your email address has been changed.'));

    expect($member->fresh()?->email)->toBe('new@example.com');
});
