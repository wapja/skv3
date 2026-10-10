<?php

declare(strict_types=1);

/** covers §4.10 */

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

test('profile page is displayed', function (): void {
    $this->actingAs($user = User::factory()->create());
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));

    $this->get(route('profile.edit'))->assertOk();
});

test('profile information can be updated', function (): void {
    $user = User::factory()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User');
    expect($user->email)->toEqual('test@example.com');
});

test('the email address is stored lower-cased and works for login in any casing', function (): void {
    $user = User::factory()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('email', 'Foo@Example.COM')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->refresh()->email)->toBe('foo@example.com');

    Auth::logout();

    $this->post(route('login.store'), [
        'email' => 'FOO@example.com',
        'password' => 'password',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
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
    User::factory()->create(['email' => 'former@example.com'])->delete();
    $this->actingAs($user = User::factory()->create());
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));

    Livewire::test('pages::settings.profile')
        ->set('name', $user->name)
        ->set('email', 'former@example.com')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->refresh()->email)->toBe('former@example.com');
});
