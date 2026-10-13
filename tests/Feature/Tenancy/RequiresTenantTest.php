<?php

declare(strict_types=1);

/** covers §6.2 */

use App\Models\Organisation;
use Livewire\Livewire;
use Tests\Fixtures\RequiresTenantProbe;

it('answers 404 for a marked action on a central host and runs it on a tenant host', function (string $host): void {
    $organisation = Organisation::factory()->create();

    if ($host === 'tenant') {
        onTenant($organisation);
    } elseif ($host === 'apex') {
        onApex();
    } else {
        onAdminHost();
    }

    $component = Livewire::test(RequiresTenantProbe::class)->call('run');

    if ($host === 'tenant') {
        $component->assertSet('runs', 1);
    } else {
        $component->assertNotFound();
    }
})->with(['tenant', 'apex', 'admin']);
