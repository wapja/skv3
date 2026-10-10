<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use RuntimeException;

final class TenantNotResolvedException extends RuntimeException
{
    public static function forQuery(string $model): self
    {
        return new self("No tenant is bound while querying [{$model}]. Bind one with Tenant::run() or read centrally inside Tenant::bypass().");
    }

    public static function forCreating(string $model): self
    {
        return new self("Cannot create [{$model}] without an organisation: no tenant is bound and no organisation_id was given.");
    }
}
