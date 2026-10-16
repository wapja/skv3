<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages whose URL carries a secret never leak it as a referrer: the invitation accept page's token and signature
 * (RK-M12), the setup wizard's token and the Herd stage's secret (g3-13).
 */
final class SetReferrerPolicy
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
