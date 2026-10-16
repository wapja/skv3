<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\NewPasswordController;

/**
 * Fortify's password reset (§4.2), with the address checked to be a string first (G1-02): Fortify lower-cases the
 * address before it validates it, so an array answered 500 instead of a validation error.
 */
final class ResetPasswordController extends NewPasswordController
{
    public function store(Request $request): Responsable
    {
        $request->validate([Fortify::email() => ['required', 'string']]);

        return parent::store($request);
    }
}
