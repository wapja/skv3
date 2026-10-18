<?php

declare(strict_types=1);

/** covers §4.10.1, §4.10.5, §6.5 */

use App\Enums\Appearance;
use App\Enums\Locale;
use App\Models\Organisation;
use Livewire\Livewire;

it('shows the user menu with settings, language, appearance and log out on app pages', function (): void {
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    $this->get(route('dashboard'))->assertOk()
        ->assertSee('data-test="user-menu-language"', false)
        ->assertSee('data-test="user-menu-appearance"', false)
        ->assertSee('data-test="logout-button"', false)
        ->assertSee(Locale::Dutch->nativeName())
        ->assertSee(Locale::English->nativeName());
});

it('changes the language and lets the browser reload the page it shows, never a redirect to a stored path or the Referer (G3-07, RL-7)', function (): void {
    $user = asOrgAdmin(Organisation::factory()->create(['slug' => 'acme']));
    $this->withHeader('Referer', 'https://evil.example/phish');
    $snapshot = pageSnapshot(route('users.index', ['search' => 'x']), 'user-menu');

    callPageAction($snapshot, 'setLocale', ['nl'])->assertOk()
        ->assertJsonMissingPath('components.0.effects.redirect')
        ->assertJsonPath('components.0.effects.xjs.0.expression', 'window.location.reload()');

    expect($user->fresh()?->locale)->toBe(Locale::Dutch);
});

it('keeps no return path in its state for a client to change (G3-07)', function (): void {
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    expect(pageSnapshot(route('dashboard'), 'user-menu')['data'])->not->toHaveKey('returnPath');
});

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

it('gives small screens a header with the sidebar toggle and the user menu (§4.10.1)', function (): void {
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();
    preg_match('/<header\b[^>]*\blg:hidden\b.*?<\/header>/s', $html, $header);

    expect($header)->not->toBeEmpty()
        ->and($header[0] ?? '')->toContain('data-flux-sidebar-toggle')
        ->and($header[0] ?? '')->toContain('aria-label="'.e(__('Toggle sidebar')).'"')
        ->and($header[0] ?? '')->toContain('data-test="logout-button"');
});
