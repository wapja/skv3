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

it('saves the appearance chosen in the menu without re-rendering it', function (): void {
    $user = asMember(Organisation::factory()->create(['slug' => 'acme']));
    $snapshot = pageSnapshot(route('dashboard'), 'user-menu');

    callPageAction($snapshot, 'saveAppearance', ['light'])->assertOk()
        ->assertJsonMissingPath('components.0.effects.html');

    expect($user->fresh()?->appearance)->toBe(Appearance::Light);
});

it('saves each appearance change from one place on the page', function (string $route): void {
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    $content = (string) $this->get(route($route))->assertOk()->getContent();

    expect(substr_count($content, '$wire.saveAppearance'))->toBe(1);
})->with(['dashboard', 'appearance.edit']);
