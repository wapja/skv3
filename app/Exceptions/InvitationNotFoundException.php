<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** An unknown or cancelled invitation token on this host: a friendly 404 (§4.5, DEC-28 (15)). Not reported. */
final class InvitationNotFoundException extends RuntimeException
{
    public function render(Request $request): Response
    {
        return response()->view('errors.invitation-invalid', [], 404);
    }
}
