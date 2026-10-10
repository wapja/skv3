<?php

declare(strict_types=1);

/** covers §6.2 */

use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Hosts;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Drawer\Utils;
use Livewire\Livewire;
use Tests\Fixtures\TenantProbe;

beforeEach(function (): void {
    Livewire::component('tenant-probe', TenantProbe::class);
    Gate::define('nobody', fn (): bool => false);

    Route::middleware('web')->group(function (): void {
        Route::tenant(function (): void {
            Route::get('/_tenant-only', fn (): string => 'tenant');
            Route::get('/_user/{user}', fn (User $user): string => $user->name);
            Route::get('/_probe', fn (): string => Blade::render('<livewire:tenant-probe />'));
        });
        Route::central(function (): void {
            Route::get('/_central-only', fn (): string => 'central');
        });
        Route::middleware('can:nobody')->group(function (): void {
            Route::tenant(function (): void {
                Route::get('/_guarded', fn (): string => 'never');
            });
        });
    });
});

it('serves tenant routes on tenant hosts only', function (): void {
    asSuperAdmin();
    $organisation = Organisation::factory()->create();

    $this->get(tenant_url($organisation, '/_tenant-only'))->assertOk()->assertSee('tenant');
    $this->get(Hosts::url(Hosts::apex(), '/_tenant-only'))->assertNotFound();
    $this->get(Hosts::url(Hosts::admin(), '/_tenant-only'))->assertNotFound();
});

it('serves central routes on central hosts only', function (): void {
    asSuperAdmin();
    $organisation = Organisation::factory()->create();

    $this->get(Hosts::url(Hosts::apex(), '/_central-only'))->assertOk();
    $this->get(Hosts::url(Hosts::admin(), '/_central-only'))->assertOk();
    $this->get(tenant_url($organisation, '/_central-only'))->assertNotFound();
});

it('checks the host before can: middleware', function (): void {
    // As a guest: can:nobody answers 403 if it runs before EnsureTenantHost, and 404 otherwise.
    // A super admin would pass can:nobody through SuperAdminGate (Task 12) and hide a wrong order.
    $this->get(Hosts::url(Hosts::apex(), '/_guarded'))->assertNotFound();
});

it('checks the host before route-model binding', function (bool $signedIn): void {
    $member = User::factory()->for(Organisation::factory())->create();

    if ($signedIn) {
        asSuperAdmin();
    }

    $this->get(Hosts::url(Hosts::apex(), '/_user/'.$member->id))->assertNotFound();
})->with([
    'guest' => [false],
    'super admin' => [true],
]);

it('keeps the host rule on forged Livewire updates from the wrong host', function (): void {
    asSuperAdmin();
    $organisation = Organisation::factory()->create();
    $html = $this->get(tenant_url($organisation, '/_probe'))->assertOk()->getContent();
    $snapshot = Utils::extractAttributeDataFromHtml((string) $html, 'wire:snapshot');
    $payload = ['components' => [[
        'snapshot' => json_encode($snapshot),
        'updates' => [],
        'calls' => [['method' => 'increment', 'params' => [], 'path' => '', 'metadata' => []]],
    ]]];

    $this->postJson(tenant_url($organisation, Livewire::getUpdateUri()), $payload, ['X-Livewire' => '1'])->assertOk();
    Livewire::flushState(); // a new request cycle, as in production: PersistentMiddleware remembers routes it already handled
    $this->postJson(Hosts::url(Hosts::apex(), Livewire::getUpdateUri()), $payload, ['X-Livewire' => '1'])->assertNotFound();
});
