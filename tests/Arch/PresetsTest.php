<?php

declare(strict_types=1);
use App\Authorization\Permission;
use App\Authorization\PermissionGroup;
use App\Authorization\Role;

/** covers §7.2 */
arch()->preset()->php();

arch()->preset()->security();

// Justified ignores (§7.2, DEC-26): the authorization enums live in App\Authorization (§6.1),
// the tenancy exceptions in App\Tenancy\Exceptions, and two Fortify/Passkeys classes are
// extended to load users without the tenant scope. ignoring() matches class-name prefixes, and
// names of classes that later tasks create are harmless until then.
arch()->preset()->laravel()->ignoring([
    Permission::class,
    PermissionGroup::class,
    Role::class,
    'App\Tenancy\Exceptions',
    'App\Tenancy\Auth\UnscopedTwoFactorLoginRequest',
    'App\Tenancy\Auth\UnscopedPasskey',
]);
