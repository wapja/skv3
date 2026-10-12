<?php

declare(strict_types=1);

/** covers §4.10 */

use App\Authorization\SetSuperAdmin;
use App\Enums\Locale;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('profile page is displayed', function (): void {
    $this->actingAs($user = User::factory()->create());
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));

    $this->get(route('profile.edit'))->assertOk();
});

test('profile information can be updated', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->name)->toBe('Test User')
        ->and($user->email)->not->toBe('test@example.com')
        ->and(confirmationLinkFor('test@example.com'))->toContain('/settings/email/confirm/');
});

test('the email address is stored lower-cased and works for login in any casing', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', $user->name)
        ->set('email', 'Foo@Example.COM')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();
    $this->withSession(['auth.password_confirmed_at' => time()])->get(confirmationLinkFor('foo@example.com'))->assertRedirect(route('profile.edit'));
    $this->post(route('logout'));

    expect($user->refresh()->email)->toBe('foo@example.com');
    $this->post(route('login.store'), ['email' => 'FOO@example.com', 'password' => 'password'])
        ->assertRedirect(route('dashboard'));
});

test('the email uniqueness check ignores casing', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    $user = User::factory()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('email', 'Taken@Example.com')
        ->call('updateProfileInformation')
        ->assertHasErrors(['email' => __('This address can\'t be used.')]);
});

it('answers the generic message on the profile page for an address used in another organisation or by a super admin (W9)', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'elsewhere@example.com']);
    $organisation = Organisation::factory()->create();
    User::factory()->for($organisation)->create(['email' => 'colleague@example.com']);
    app(SetSuperAdmin::class)->handle(User::factory()->for($organisation)->create(['email' => 'promoted@example.com']), true);
    $user = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('email', 'elsewhere@example.com')
        ->call('updateProfileInformation')
        ->assertHasErrors(['email' => __('This address can\'t be used.')])
        ->set('email', 'promoted@example.com')
        ->call('updateProfileInformation')
        ->assertHasErrors(['email' => __('This address can\'t be used.')])
        ->set('email', 'colleague@example.com')
        ->call('updateProfileInformation')
        ->assertHasErrors(['email' => __('This address is already used in this organisation.')]);

    Notification::assertNothingSent();
});

test('email address of a deleted user can be reused', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'former@example.com'])->delete();
    $user = User::factory()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', $user->name)
        ->set('email', 'former@example.com')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();
    $this->withSession(['auth.password_confirmed_at' => time()])->get(confirmationLinkFor('former@example.com'));

    expect($user->refresh()->email)->toBe('former@example.com');
});

it('renders the language field on the profile page (§4.10)', function (): void {
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    $html = (string) $this->get(route('profile.edit'))->assertOk()->assertSee('data-test="profile-language"', false)->getContent();

    preg_match('/<select[^>]*data-test="profile-language".*?<\/select>/s', $html, $field);
    preg_match_all('/<option[^>]*\bvalue="([a-z]+)"/', $field[0] ?? '', $options);

    expect($field[0] ?? '')->toContain('wire:model="locale"')
        ->and($options[1])->toBe(array_map(fn (Locale $locale): string => $locale->value, Locale::cases()));
});

it('changes the language from the profile page (§4.10)', function (): void {
    $user = asMember(Organisation::factory()->create(['slug' => 'acme']));

    Livewire::test('pages::settings.profile')
        ->set('locale', 'nl')
        ->call('updateProfileInformation')
        ->assertHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh()?->locale)->toBe(Locale::Dutch);
});
