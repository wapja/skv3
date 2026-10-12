<?php

declare(strict_types=1);

/** covers §4.1, §6.5 */

use App\Models\Organisation;
use App\Support\Branding;

it('shows the organisation\'s name in the brand on its host and the platform name centrally (§4.1)', function (): void {
    config(['branding.name' => 'Platform Name']);
    $organisation = Organisation::factory()->create(['slug' => 'acme', 'name' => 'Acme Corp']);

    asMember($organisation);
    expect(Branding::name())->toBe('Acme Corp');
    // The profile page has no Organisation card (§4.10), so the name there can only come from the brand.
    $this->get(route('profile.edit'))->assertOk()->assertSee('Acme Corp')->assertSee('<title>Profile settings - Platform Name</title>', false);

    asSuperAdmin();
    onAdminHost();
    $this->get(route('dashboard'))->assertOk()->assertSee('Platform Name')->assertDontSee('Acme Corp');
});

it('maps a configured accent to the html element and falls back to neutral (RL-5)', function (string $configured, string $expected): void {
    config(['branding.accent' => $configured]);
    onTenant(Organisation::factory()->create(['slug' => 'acme']));

    $this->get(route('login'))->assertOk()->assertSee('data-accent="'.$expected.'"', false);
})->with([
    'a preset' => ['indigo', 'indigo'],
    'upper case' => ['Emerald', 'emerald'],
    'unknown' => ['hotpink', 'neutral'],
    'empty' => ['', 'neutral'],
]);

it('ships a CSS rule for every accent preset', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    foreach (array_diff(Branding::ACCENTS, ['neutral']) as $accent) {
        expect($css)->toContain("[data-accent=\"{$accent}\"]");
    }
});

it('counts branding as customised once an accent or a logo is set', function (): void {
    config(['branding.accent' => 'neutral', 'branding.logo' => null]);
    expect(Branding::isCustomised())->toBeFalse();

    config(['branding.accent' => 'blue']);
    expect(Branding::isCustomised())->toBeTrue();

    config(['branding.accent' => 'neutral', 'branding.logo' => 'images/logo.svg']);
    expect(Branding::isCustomised())->toBeTrue()
        ->and(Branding::logoUrl())->toEndWith('/images/logo.svg');
});
