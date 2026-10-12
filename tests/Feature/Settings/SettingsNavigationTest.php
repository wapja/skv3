<?php

declare(strict_types=1);

/** covers §4.10 */

use App\Models\User;

it('marks the current settings page on its tenant host, also after a Livewire update', function (): void {
    $this->actingAs($user = User::factory()->create());
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));

    $currentItems = function (string $html): array {
        preg_match_all('/<a href="([^"]*)" data-current[^>]*data-flux-navlist-item/', $html, $matches);

        return $matches[1];
    };

    expect($currentItems((string) $this->get(route('profile.edit'))->assertOk()->getContent()))
        ->toBe([route('profile.edit', absolute: false)]);

    $update = callPageAction(pageSnapshot(route('profile.edit'), 'pages::settings.profile'), '$refresh')->assertOk();

    expect($currentItems((string) $update->json('components.0.effects.html')))
        ->toBe([route('profile.edit', absolute: false)]);
});
