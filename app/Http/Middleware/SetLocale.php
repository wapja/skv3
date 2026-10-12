<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Answers every web request, Livewire updates included, in the request's language (§4.11). */
final class SetLocale
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        Locales::apply(Locales::forRequest($request));

        return $next($request);
    }
}
