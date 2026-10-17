<?php

declare(strict_types=1);

/** covers §4.5.4, §6.3 */

use App\Authorization\PermissionsTeam;
use App\Models\Organisation;
use App\Tenancy\Facades\Tenant;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\RecordTenantJob;

it('runs a callback in a team and restores the previous team', function (): void {
    PermissionsTeam::set(3);

    $inside = PermissionsTeam::run(7, fn (): ?int => PermissionsTeam::current());

    expect($inside)->toBe(7)->and(PermissionsTeam::current())->toBe(3);
});

it('restores the previous team when the callback throws (W11)', function (): void {
    PermissionsTeam::set(null);

    expect(fn (): mixed => PermissionsTeam::run(5, fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class, 'boom');

    expect(PermissionsTeam::current())->toBeNull();
});

it('exposes the helper function', function (): void {
    expect(withPermissionsTeam(9, fn (): ?int => PermissionsTeam::current()))->toBe(9);
});

it('keeps the chosen team after a nested Tenant::run()', function (): void {
    $organisation = Organisation::factory()->create();

    $after = withPermissionsTeam(42, function () use ($organisation): ?int {
        Tenant::run($organisation, fn (): null => null);

        return PermissionsTeam::current();
    });

    expect($after)->toBe(42);
});

it('keeps the chosen team after a synchronous job', function (bool $withTenant): void {
    config(['queue.default' => 'sync']);

    if ($withTenant) {
        Tenant::set(Organisation::factory()->create());
    }

    $after = withPermissionsTeam(42, function (): ?int {
        Bus::dispatch(new RecordTenantJob);

        return PermissionsTeam::current();
    });

    expect(Cache::get('job.ran'))->toBeTrue()
        ->and($after)->toBe(42);
})->with([
    'no tenant bound' => [false],
    'another tenant bound' => [true],
]);
