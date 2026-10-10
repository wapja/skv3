<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\ConfirmEmailChange;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class ConfirmEmailChangeController extends Controller
{
    public function __invoke(Request $request, ConfirmEmailChange $confirmEmailChange, string $user): RedirectResponse
    {
        $current = $request->user();

        abort_unless($current instanceof User && (string) $current->id === $user, 403);

        try {
            $confirmEmailChange->handle($current, (string) $request->query('email'), (string) $request->query('nonce'));
        } catch (ValidationException $exception) {
            return to_route('profile.edit')->with('status', (string) Arr::first(Arr::flatten($exception->errors())));
        }

        return to_route('profile.edit')->with('status', __('Your email address has been changed.'));
    }
}
