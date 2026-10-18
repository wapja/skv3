<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;

/**
 * Answers 403 to PATH where the CSRF middleware sits in the web group, ahead of EnforceImpersonation, so the 403 page
 * renders before a running impersonation is re-checked (RL-20). Every other request passes through as usual.
 */
final class ForbiddingPreventRequestForgery extends PreventRequestForgery
{
    public const string PATH = '_forbidden-probe';

    /** @param  Request  $request */
    public function handle($request, Closure $next): mixed
    {
        abort_if($request->is(self::PATH), 403);

        return parent::handle($request, $next);
    }
}
