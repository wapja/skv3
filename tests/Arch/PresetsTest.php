<?php

declare(strict_types=1);

/** covers §7.2 */

use App\Authorization\Permission;
use App\Authorization\PermissionGroup;
use App\Authorization\Role;
use App\Support\RateLimits\ThrottleKey;
use App\Tenancy\Auth\UnscopedPasskey;
use App\Tenancy\Auth\UnscopedTwoFactorLoginRequest;

arch()->preset()->php();

// Justified ignore (G1-03): ThrottleKey rebuilds ThrottleRequests' md5 storage key for a named limiter; no security hash.
arch()->preset()->security()->ignoring([
    ThrottleKey::class,
]);

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
