<?php

declare(strict_types=1);

/** covers §4.10 */

use App\Models\User;

test('guests are redirected to the login page', function (): void {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function (): void {
    $user = User::factory()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});
