<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('sends a member the reset link for their own host, wherever it was requested (R1)', function (string $host): void {
    Notification::fake();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    match ($host) {
        'own tenant' => onTenant($organisation),
        'apex' => onApex(),
        default => onAdminHost(),
    };

    $this->post(route('password.email'), ['email' => $member->email])->assertSessionHasNoErrors();

    Notification::assertSentTo($member, ResetPassword::class, fn (ResetPassword $notification): bool => str_starts_with(
        (string) $notification->toMail($member)->actionUrl,
        'http://acme.localhost/reset-password/',
    ));
})->with(['own tenant', 'apex', 'admin']);

it('sends a super admin the reset link for the admin host', function (): void {
    Notification::fake();
    $superAdmin = User::factory()->superAdmin()->create();
    onApex();

    $this->post(route('password.email'), ['email' => $superAdmin->email]);

    Notification::assertSentTo($superAdmin, ResetPassword::class, fn (ResetPassword $notification): bool => str_starts_with(
        (string) $notification->toMail($superAdmin)->actionUrl,
        'http://admin.localhost/reset-password/',
    ));
});

it('answers every reset-link request the same way', function (): void {
    Notification::fake();
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    $responses = [
        'known' => $this->post(route('password.email'), ['email' => $member->email]),
        'requested again' => $this->post(route('password.email'), ['email' => $member->email]),
        'unknown' => $this->post(route('password.email'), ['email' => 'nobody@example.com']),
    ];

    foreach ($responses as $response) {
        $response->assertSessionHasNoErrors()->assertSessionHas('status', __('passwords.sent'));
    }
});

it('throttles reset-link requests', function (): void {
    Notification::fake();
    onApex();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('password.email'), ['email' => 'someone@example.com']);
    }

    $this->post(route('password.email'), ['email' => 'someone@example.com'])->assertTooManyRequests();
});

it('refuses a reset token on a host the user does not belong to', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $token = Password::broker()->createToken($member);
    onApex();

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $member->email,
        'password' => 'new-password-1',
        'password_confirmation' => 'new-password-1',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('password', (string) $member->fresh()?->password))->toBeTrue();
});

it('has a neutral Dutch reset message', function (): void {
    app()->setLocale('nl');

    expect(__('passwords.sent'))->toBe('Als dit e-mailadres bij ons bekend is, hebben we je een link gestuurd om je wachtwoord opnieuw in te stellen.');
});
