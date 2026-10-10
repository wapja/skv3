<?php

declare(strict_types=1);

/** covers §7.2 */

use App\Authorization\Permission;
use App\Authorization\PermissionGroup;
use App\Authorization\Role;
use App\Tenancy\Auth\UnscopedPasskey;
use App\Tenancy\Auth\UnscopedTwoFactorLoginRequest;

arch()->preset()->php();

arch()->preset()->security();

// Justified ignores (§7.2, DEC-26): the authorization enums live in App\Authorization (§6.1),
// the tenancy exceptions in App\Tenancy\Exceptions, and two Fortify/Passkeys classes are
// extended to load users without the tenant scope. ignoring() matches class-name prefixes.
arch()->preset()->laravel()->ignoring([
    Permission::class,
    PermissionGroup::class,
    Role::class,
    'App\Tenancy\Exceptions',
    UnscopedTwoFactorLoginRequest::class,
    UnscopedPasskey::class,
]);
