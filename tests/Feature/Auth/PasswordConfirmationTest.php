<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Models\User;

test('confirm password screen can be rendered', function (): void {
    $user = User::factory()->create();
    onTenant($user->organisation ?? throw new LogicException('The user has no organisation.'));

    $response = $this->actingAs($user)->get(route('password.confirm'));

    $response->assertOk();
});
