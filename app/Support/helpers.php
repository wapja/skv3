<?php

declare(strict_types=1);

use App\Models\Organisation;
use App\Tenancy\Hosts;

if (! function_exists('tenant_url')) {
    /** Absolute URL on an organisation's subdomain, keeping the request's scheme and port (§6.2). */
    function tenant_url(Organisation|string $organisation, string $path = '/'): string
    {
        $slug = $organisation instanceof Organisation ? $organisation->slug : $organisation;

        return Hosts::url(Hosts::tenant($slug), $path);
    }
}
