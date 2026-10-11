<?php

declare(strict_types=1);

/** covers §6.5 (interim navigation, RL-11) */

use App\Models\Organisation;

it('shows the administration links that match the actor\'s permissions', function (string $actor, array $visible): void {
    $organisation = Organisation::factory()->create();

    match ($actor) {
        'member' => asMember($organisation),
        'organisation admin' => asOrgAdmin($organisation),
        default => asSuperAdmin(),
    };

    if ($actor === 'super admin') {
        onAdminHost();
    }

    $response = $this->get(route('dashboard'))->assertOk();

    // Later tasks append their own link tests to this file.
    foreach (['users.index'] as $link) {
        in_array($link, $visible, true)
            ? $response->assertSee('href="'.route($link).'"', false)
            : $response->assertDontSee('href="'.route($link).'"', false);
    }

    $visible === []
        ? $response->assertDontSee(__('Administration'))
        : $response->assertSee(__('Administration'));
})->with([
    'member' => ['member', []],
    'organisation admin' => ['organisation admin', ['users.index']],
    'super admin' => ['super admin', ['users.index']],
]);
