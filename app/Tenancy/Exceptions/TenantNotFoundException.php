<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use App\Tenancy\Hosts;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** Rendered as the friendly tenant 404 with a link to the apex (§4.1). Not reported. */
final class TenantNotFoundException extends RuntimeException
{
    public function render(Request $request): Response
    {
        return response()->view('errors.tenant-not-found', [
            'apexUrl' => Hosts::url(Hosts::apex()),
        ], 404);
    }
}
