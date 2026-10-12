<?php

declare(strict_types=1);

/** covers §4.11, §6.5 */

use App\Enums\Locale;
use App\Models\Organisation;
use App\Models\User;

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create(['slug' => 'acme']);
    onTenant($this->organisation);
});

it('uses the signed-in user\'s language', function (): void {
    $this->actingAs(User::factory()->for($this->organisation)->create(['locale' => Locale::Dutch]));

    $this->get(route('dashboard'))->assertOk()->assertSee('<html lang="nl"', false);
});

it('gives guests the language their browser prefers, else the default (§4.11)', function (?string $header, string $expected): void {
    config(['skv.locale.default' => 'en']);

    $headers = $header === null ? [] : ['Accept-Language' => $header];

    $this->get(route('login'), $headers)->assertOk()->assertSee('<html lang="'.$expected.'"', false);
})->with([
    'dutch browser' => ['nl-NL,nl;q=0.9,en;q=0.8', 'nl'],
    'english browser' => ['en-GB,en;q=0.9', 'en'],
    'unsupported language' => ['fr-FR,fr;q=0.9', 'en'],
    'no header' => [null, 'en'],
]);

it('lets guests switch the language on the auth pages', function (): void {
    $this->from(route('login'))->post(route('locale.update'), ['locale' => 'nl'])->assertRedirect(route('login'));

    $this->get(route('login'), ['Accept-Language' => 'en'])->assertOk()->assertSee('<html lang="nl"', false);
});

it('saves the choice on the user when someone is signed in', function (): void {
    $user = User::factory()->for($this->organisation)->create(['locale' => Locale::English]);
    $this->actingAs($user);

    $this->from(route('dashboard'))->post(route('locale.update'), ['locale' => 'nl'])->assertRedirect(route('dashboard'));

    expect($user->fresh()?->locale)->toBe(Locale::Dutch);
});

it('refuses an unsupported language', function (): void {
    $this->from(route('login'))->post(route('locale.update'), ['locale' => 'fr'])->assertSessionHasErrors('locale');
});

it('keeps the user\'s language on Livewire updates', function (): void {
    $user = User::factory()->for($this->organisation)->create(['locale' => Locale::Dutch, 'name' => 'Old']);
    $this->actingAs($user);
    $snapshot = pageSnapshot(route('profile.edit'), 'pages::settings.profile');

    $response = callPageAction($snapshot, 'updateProfileInformation', updates: ['name' => 'New']);

    $response->assertOk();
    expect((string) $response->getContent())->toContain('Profiel bijgewerkt.');
});

it('formats relative dates in the active language', function (): void {
    $this->actingAs(User::factory()->for($this->organisation)->create(['locale' => Locale::Dutch]));
    $this->get(route('dashboard'))->assertOk();

    expect(now()->subDay()->diffForHumans())->toContain('geleden');
});

it('does not let a request change the configured default (RL-8)', function (): void {
    config(['skv.locale.default' => 'en']);
    $this->actingAs(User::factory()->for($this->organisation)->create(['locale' => Locale::Dutch]));

    $this->get(route('dashboard'))->assertOk();

    expect(Locale::default())->toBe(Locale::English);
});
