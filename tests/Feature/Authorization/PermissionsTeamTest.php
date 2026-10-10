<?php

declare(strict_types=1);

/** covers §6.3 */

use App\Authorization\PermissionsTeam;

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
