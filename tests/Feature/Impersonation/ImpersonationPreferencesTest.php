<?php

declare(strict_types=1);

/** covers §4.7, §4.11 */

use App\Actions\Settings\RequestEmailChange;
use App\Authorization\Role;
use App\Enums\Appearance;
use App\Enums\Locale;
use App\Models\Organisation;
use App\Models\User;
use App\Support\RateLimits\UserActionLimit;
use App\Tenancy\Auth\Impersonation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    useImpersonationModule(true);
});

/**
 * Starts acme's organisation admin impersonating a member over HTTP; both are Dutch with the system appearance.
 *
 * @return array{0: User, 1: User} the admin and the member
 */
$impersonating = function (): array {
    $organisation = onTenant(Organisation::factory()->create(['slug' => 'acme']));
    $preferences = ['locale' => Locale::Dutch, 'appearance' => Appearance::System];
    $admin = User::factory()->for($organisation)->create($preferences);
    withPermissionsTeam($organisation->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));
    $admin->unsetRelation('roles');
    $member = User::factory()->for($organisation)->create($preferences);
    startImpersonatingOverHttp($admin, $member);

    return [$admin, $member];
};

it('keeps a language chosen in the user menu while impersonating off the target\'s account (G2-05)', function () use ($impersonating): void {
    [$admin, $member] = $impersonating();

    callPageAction(pageSnapshot(route('dashboard'), 'user-menu'), 'setLocale', ['en'])->assertOk();

    expect($member->fresh()?->locale)->toBe(Locale::Dutch)
        ->and($admin->fresh()?->locale)->toBe(Locale::Dutch);
    $html = (string) $this->get(route('dashboard'))->assertOk()->assertSee('<html lang="en"', false)->getContent();
    expect($html)->toMatch('/<ui-menu-radio[^>]*value="en"[^>]*checked/');
});

it('keeps a language posted to the language toggle while impersonating off the target\'s account (G2-05)', function () use ($impersonating): void {
    [, $member] = $impersonating();

    $this->post(route('locale.update'), ['locale' => 'en'])->assertRedirect();

    expect($member->fresh()?->locale)->toBe(Locale::Dutch);
    $this->get(route('dashboard'))->assertOk()->assertSee('<html lang="en"', false);
});

it('keeps an appearance chosen while impersonating off the target\'s account (G2-05)', function () use ($impersonating): void {
    [, $member] = $impersonating();

    callPageAction(pageSnapshot(route('dashboard'), 'user-menu'), 'saveAppearance', ['dark'])->assertOk();

    expect($member->fresh()?->appearance)->toBe(Appearance::System)
        ->and((string) $this->get(route('dashboard'))->assertOk()->getContent())->toMatch('/<html[^>]*class="dark"/');
});

it('forgets the language and appearance chosen during an impersonation when it stops (G2-05)', function () use ($impersonating): void {
    [$admin] = $impersonating();
    callPageAction(pageSnapshot(route('dashboard'), 'user-menu'), 'setLocale', ['en'])->assertOk();
    callPageAction(pageSnapshot(route('dashboard'), 'user-menu'), 'saveAppearance', ['dark'])->assertOk();

    $this->delete(route('impersonation.stop'))->assertRedirect(route('users.index'));
    Auth::guard('web')->forgetUser();

    $html = (string) $this->get(route('users.index'))->assertOk()
        ->assertSessionMissing(Impersonation::LOCALE_KEY)
        ->assertSessionMissing(Impersonation::APPEARANCE_KEY)
        ->assertSee('<html lang="nl"', false)
        ->getContent();
    expect($html)->not->toMatch('/<html[^>]*class="dark"/');
    $this->assertAuthenticatedAs($admin);
});

it('starts the profile form from the language chosen during an impersonation and switches back from there (G2-05)', function () use ($impersonating): void {
    [, $member] = $impersonating();
    $this->post(route('locale.update'), ['locale' => 'en'])->assertRedirect();

    $snapshot = pageSnapshot(route('profile.edit'), 'pages::settings.profile');
    expect($snapshot['data']['locale'])->toBe('en');

    callPageAction($snapshot, 'updateProfileInformation', [], ['locale' => 'nl'])->assertOk();

    expect($member->fresh()?->locale)->toBe(Locale::Dutch)
        ->and(session(Impersonation::LOCALE_KEY))->toBe('nl');
});

it('keeps a language saved on the profile page while impersonating off the target\'s account (G2-05)', function () use ($impersonating): void {
    // The other profile test saves the language the target already has, so a write to the account went unseen.
    [, $member] = $impersonating();

    callPageAction(pageSnapshot(route('profile.edit'), 'pages::settings.profile'), 'updateProfileInformation', [], ['locale' => 'en'])->assertOk();

    expect($member->fresh()?->locale)->toBe(Locale::Dutch)
        ->and(session(Impersonation::LOCALE_KEY))->toBe('en');
});

it('starts no email change for the target while impersonating (G2-05)', function () use ($impersonating): void {
    // The link would go to an address the impersonator chooses, the notice to the target's mailbox, and the new
    // nonce would void the target's own pending link.
    Notification::fake();
    [, $member] = $impersonating();
    Cache::put(RequestEmailChange::cacheKey($member), 'the target\'s own pending link', now()->addHour());

    $response = callPageAction(pageSnapshot(route('profile.edit'), 'pages::settings.profile'), 'updateProfileInformation', [], ['email' => 'impersonator@example.com'])->assertOk();

    /** @var array{memo: array{errors?: array<string, list<string>>}} $snapshot */
    $snapshot = json_decode((string) $response->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);
    expect($snapshot['memo']['errors'] ?? [])->toBe(['email' => [__('The address can\'t be changed during an impersonation.')]])
        ->and(Cache::get(RequestEmailChange::cacheKey($member)))->toBe('the target\'s own pending link')
        ->and(RateLimiter::attempts(UserActionLimit::emailChange()->key($member)))->toBe(0);
    Notification::assertNothingSent();
});
