<?php

declare(strict_types=1);

namespace App\Setup\Http;

use App\Setup\SetupMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** First middleware of the web group, before the tenant resolver and StartSession (RL-1, §5.3.4). */
final readonly class EnterSetupMode
{
    public function __construct(private SetupMode $mode) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->mode->active()) {
            $this->mode->applyRuntime();
            $request->attributes->set(SetupMode::ATTRIBUTE, true);
        }

        return $next($request);
    }
}
