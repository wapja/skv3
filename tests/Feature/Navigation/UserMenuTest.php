<?php

declare(strict_types=1);

/** covers §6.5 */

use App\Enums\Appearance;
use App\Enums\Locale;
use App\Models\Organisation;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

it('shows the user menu with settings, language, appearance and log out on app pages', function (): void {
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    $this->get(route('dashboard'))->assertOk()
        ->assertSee('data-test="user-menu-language"', false)
        ->assertSee('data-test="user-menu-appearance"', false)
        ->assertSee('data-test="logout-button"', false)
        ->assertSee(Locale::Dutch->label())
        ->assertSee(Locale::English->label());
});

it('changes the language and reloads the page it was opened on', function (): void {
    $user = asOrgAdmin(Organisation::factory()->create(['slug' => 'acme']));
    $snapshot = pageSnapshot(route('users.index'), 'user-menu');

    callPageAction($snapshot, 'setLocale', ['nl'])->assertOk()
        ->assertJsonPath('components.0.effects.redirect', '/users');

    expect($user->fresh()?->locale)->toBe(Locale::Dutch);
});

it('keeps the return path locked against the client', function (): void {
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    Livewire::test('user-menu', ['variant' => 'sidebar'])->set('returnPath', 'https://evil.example');
})->throws(CannotUpdateLockedPropertyException::class);

it('refuses an unknown language', function (): void {
    $user = asMember(Organisation::factory()->create(['slug' => 'acme']));

    Livewire::test('user-menu', ['variant' => 'sidebar'])
        ->call('setLocale', 'fr')
        ->assertHasErrors('locale');

    expect($user->fresh()?->locale)->toBe(Locale::default());
});

it('saves the appearance chosen in the menu', function (): void {
    $user = asMember(Organisation::factory()->create(['slug' => 'acme']));

    Livewire::test('user-menu', ['variant' => 'header'])->call('saveAppearance', 'light');

    expect($user->fresh()?->appearance)->toBe(Appearance::Light);
});
