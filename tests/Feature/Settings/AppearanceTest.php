<?php

declare(strict_types=1);

/** covers §4.10, §6.5 */

use App\Actions\Settings\UpdateAppearance;
use App\Enums\ActivityEvent;
use App\Enums\Appearance;
use App\Models\Organisation;
use App\Models\User;
use Livewire\Livewire;

it('stores the appearance chosen on the settings page through the user menu (DEC-12)', function (): void {
    $user = asMember(Organisation::factory()->create(['slug' => 'acme']));
    $snapshot = pageSnapshot(route('appearance.edit'), 'user-menu');

    callPageAction($snapshot, 'saveAppearance', ['dark'])->assertOk();

    expect($user->fresh()?->appearance)->toBe(Appearance::Dark);
});

it('refuses an unknown appearance', function (): void {
    $user = asMember(Organisation::factory()->create(['slug' => 'acme']));

    Livewire::test('user-menu', ['variant' => 'sidebar'])
        ->call('saveAppearance', 'neon')
        ->assertHasErrors('appearance');

    expect($user->fresh()?->appearance)->toBe(Appearance::System);
});

it('defaults new users to the system appearance', function (): void {
    expect(User::factory()->create()->fresh()?->appearance)->toBe(Appearance::System);
});

it('saves the appearance quietly, without an audit entry', function (): void {
    $user = User::factory()->create();

    app(UpdateAppearance::class)->handle($user, Appearance::Light);

    expect($user->fresh()?->appearance)->toBe(Appearance::Light)
        ->and(recordedActivity(ActivityEvent::UserUpdated))->toHaveCount(0);
});

it('binds the switch to Flux and saves every change through Livewire', function (): void {
    asMember(Organisation::factory()->create(['slug' => 'acme']));

    $this->get(route('appearance.edit'))->assertOk()
        ->assertSee('x-model="$flux.appearance"', false)
        ->assertSee('$wire.saveAppearance', false);
});
