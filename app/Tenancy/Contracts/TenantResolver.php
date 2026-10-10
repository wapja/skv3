<?php

declare(strict_types=1);

namespace App\Tenancy\Contracts;

use App\Models\Organisation;
use App\Tenancy\Exceptions\TenantNotFoundException;
use Illuminate\Http\Request;

interface TenantResolver
{
    /**
     * The organisation for a tenant host, or null for a central host.
     *
     * @throws TenantNotFoundException for unknown, archived, reserved or foreign hosts
     */
    public function resolve(Request $request): ?Organisation;
}
