<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;

/**
 * Fortify's password confirmation (§4.2), with the password checked to be a string first: Fortify passes
 * $request->input('password') to ConfirmPassword, whose ?string parameter turned an array into a 500 instead of a
 * validation error (as G1-02 for the reset).
 */
final class ConfirmPasswordController extends ConfirmablePasswordController
{
    public function store(Request $request): Responsable
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        return parent::store($request);
    }
}
