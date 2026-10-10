<?php

declare(strict_types=1);

/** covers §4.3, §4.6 */

use App\Models\Organisation;
use Illuminate\Support\Facades\Gate;

it('lets an organisation admin view and edit only their own organisation', function (): void {
    $own = Organisation::factory()->create();
    $other = Organisation::factory()->create();
    $gate = Gate::forUser(asOrgAdmin($own));

    expect($gate->allows('viewAny', Organisation::class))->toBeTrue()
        ->and($gate->allows('view', $own))->toBeTrue()
        ->and($gate->allows('update', $own))->toBeTrue()
        ->and($gate->allows('view', $other))->toBeFalse()
        ->and($gate->allows('update', $other))->toBeFalse();

    foreach (['delete', 'restore', 'forceDelete'] as $ability) {
        expect($gate->allows($ability, $own))->toBeFalse($ability);
    }

    expect($gate->allows('create', Organisation::class))->toBeFalse();
});

it('reserves create, archive, restore and purge for super admins', function (): void {
    $active = Organisation::factory()->create();
    $archived = Organisation::factory()->create();
    $archived->delete();
    $gate = Gate::forUser(asSuperAdmin());

    expect($gate->allows('create', Organisation::class))->toBeTrue()
        ->and($gate->allows('delete', $active))->toBeTrue()
        ->and($gate->allows('delete', $archived))->toBeFalse()
        ->and($gate->allows('restore', $archived))->toBeTrue()
        ->and($gate->allows('forceDelete', $archived))->toBeTrue()
        ->and($gate->allows('forceDelete', $active))->toBeFalse();
});
