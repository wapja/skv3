<?php

declare(strict_types=1);

/** covers §4.10 */

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
        ->assertHasErrors(['email' => 'unique']);
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
