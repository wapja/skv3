<?php

declare(strict_types=1);

/** covers §6.5 */

use App\Authorization\Role;

it('gives every system role the colour of its badge (§6.5, CD-13)', function (): void {
    expect(array_combine(Role::values(), array_map(fn (Role $role): string => $role->color(), Role::cases())))
        ->toBe(['super_admin' => 'violet', 'organisation_admin' => 'zinc']);
});
