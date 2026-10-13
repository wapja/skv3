<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/** The CSRF middleware skips itself in unit tests; this subclass checks the token anyway. */
final class CsrfEnforcingPreventRequestForgery extends PreventRequestForgery
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
