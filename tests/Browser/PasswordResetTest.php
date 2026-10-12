<?php

declare(strict_types=1);

/** covers §4.2, §7.2 */

use App\Models\Organisation;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

it('resets a password through the link sent to the member\'s own host', function (): void {
    Notification::fake();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = browserUser($organisation);

    visit('/forgot-password')->withHost('acme.localhost')
        ->fill('email', $member->email)
        ->press('@email-password-reset-link-button')
        ->assertSee(__('passwords.sent'));

    $url = null;
    Notification::assertSentTo($member, ResetPassword::class, function (ResetPassword $notification) use ($member, &$url): bool {
        $url = $notification->toMail($member)->actionUrl;

        return true;
    });

    $path = parse_url((string) $url, PHP_URL_PATH).'?'.parse_url((string) $url, PHP_URL_QUERY);

    visit($path)->withHost('acme.localhost')
        ->fill('password', 'a-new-password-1')
        ->fill('password_confirmation', 'a-new-password-1')
        ->press('@reset-password-button')
        ->assertPathIs('/login')
        ->fill('email', $member->email)->fill('password', 'a-new-password-1')->press('@login-button')
        ->assertPathIs('/dashboard');
});
