<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\SendPasswordResetLinkRequest;

use function Illuminate\Support\defer;

/** R1: the link is sent after the response, so neither the answer nor its timing reveals whether the address exists. */
final class SendPasswordResetLinkController extends Controller
{
    public function __invoke(SendPasswordResetLinkRequest $request): Responsable
    {
        $email = Str::lower((string) $request->input(Fortify::email()));

        defer(fn (): string => Password::broker(config('fortify.passwords'))->sendResetLink([Fortify::email() => $email]));

        return app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => Password::RESET_LINK_SENT]);
    }
}
