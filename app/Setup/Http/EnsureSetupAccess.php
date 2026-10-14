<?php

declare(strict_types=1);

namespace App\Setup\Http;

use App\Setup\SetupMode;
use App\Setup\SetupToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The wizard's gate (§5.3.4, RL-2), also Livewire persistent middleware: setup mode, a direct request, and the
 * one-time token. The token arrives once in the query; its hash then lives in the (file) session, so seeding a super
 * admin never locks the wizard out. Everything else is a 404.
 */
final readonly class EnsureSetupAccess
{
    public const string SESSION_KEY = 'setup.token';

    public function __construct(private SetupToken $token) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->attributes->get(SetupMode::ATTRIBUTE) === true && DirectRequest::isDirect($request), 404);

        $candidate = $request->query('token');

        if (is_string($candidate)) {
            abort_unless($this->token->matches($candidate), 404);
            $request->session()->put(self::SESSION_KEY, $this->token->hash());

            // Out of the address bar, the history and the Referer.
            if ($request->isMethod('GET')) {
                return redirect()->to($request->url());
            }
        }

        abort_unless(self::authorised($request), 404);

        return $next($request);
    }

    /** The session holds the current token's hash; the page re-checks this in mount() and before every action. */
    public static function authorised(Request $request): bool
    {
        $stored = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
        $hash = app(SetupToken::class)->hash();

        return is_string($stored) && $hash !== null && hash_equals($hash, $stored)
            && $request->attributes->get(SetupMode::ATTRIBUTE) === true && DirectRequest::isDirect($request);
    }
}
