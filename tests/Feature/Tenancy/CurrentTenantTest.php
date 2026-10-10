<?php

declare(strict_types=1);

/** covers §6.2 */

use App\Authorization\PermissionsTeam;
use App\Models\Organisation;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Events\TenantForgotten;
use App\Tenancy\Events\TenantResolved;
use App\Tenancy\Facades\Tenant;
use Illuminate\Support\Facades\Event;

it('binds an organisation and sets the permissions team', function (): void {
    $organisation = Organisation::factory()->create();
    $resolved = [];
    Event::listen(TenantResolved::class, function (TenantResolved $event) use (&$resolved): void {
        $resolved[] = $event->organisation->id;
    });

    Tenant::set($organisation);

    expect(Tenant::current()?->is($organisation))->toBeTrue()
        ->and(Tenant::check())->toBeTrue()
        ->and(PermissionsTeam::current())->toBe($organisation->id)
        ->and($resolved)->toBe([$organisation->id]);
});

it('forgets the tenant and resets the permissions team', function (): void {
    Tenant::set(Organisation::factory()->create());
    $forgotten = 0;
    Event::listen(TenantForgotten::class, function () use (&$forgotten): void {
        $forgotten++;
    });

    Tenant::forget();

    expect(Tenant::current())->toBeNull()
        ->and(PermissionsTeam::current())->toBeNull()
        ->and($forgotten)->toBe(1);
});

it('runs a callback for an organisation and restores the previous tenant', function (): void {
    [$outer, $inner] = Organisation::factory()->count(2)->create()->all();
    Tenant::set($outer);

    $seen = Tenant::run($inner, fn (): ?int => Tenant::current()?->id);

    expect($seen)->toBe($inner->id)
        ->and(Tenant::current()?->id)->toBe($outer->id)
        ->and(PermissionsTeam::current())->toBe($outer->id);
});

it('forgets the tenant after run when none was bound, also on exceptions', function (): void {
    $organisation = Organisation::factory()->create();

    expect(fn () => Tenant::run($organisation, fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class);

    expect(Tenant::check())->toBeFalse()->and(PermissionsTeam::current())->toBeNull();
});

it('nests bypass and restores it on exceptions', function (): void {
    $depths = Tenant::bypass(fn (): array => [Tenant::isBypassed(), Tenant::bypass(fn (): bool => Tenant::isBypassed()), Tenant::isBypassed()]);

    expect($depths)->toBe([true, true, true])->and(Tenant::isBypassed())->toBeFalse();

    expect(fn () => Tenant::bypass(fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class);
    expect(Tenant::isBypassed())->toBeFalse();
});

it('proxies the scoped instance', function (): void {
    $organisation = Organisation::factory()->create();

    app(CurrentTenant::class)->set($organisation);

    expect(Tenant::current()?->id)->toBe($organisation->id);
});
