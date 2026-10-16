<?php

declare(strict_types=1);

namespace App\Setup\Http;

use App\Setup\SetupContext;
use App\Setup\SetupMode;
use App\Setup\SetupToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * The wizard's gate (§5.3.4, RL-2), also Livewire persistent middleware. It requires:
 * - setup mode
 * - a direct request (RL-4)
 * - the one-time token
 * - once begin() pinned a host, that host or its admin host (RL-5)
 *
 * The token arrives once in the query, and its hash then lives in a new (file) session. Seeding a super admin
 * therefore never locks the wizard out, and a session id planted beforehand never becomes authorised (g3-26).
 * Everything else is a 404.
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
            $request->session()->regenerate();
            // StartSession stores fullUrl() as _previous.url after this returns, and fullUrl() reads QUERY_STRING (g3-27).
            $request->query->remove('token');
            $request->server->set('QUERY_STRING', Arr::query($request->query->all()));

            // Out of the address bar, the history and the Referer.
            if ($request->isMethod('GET')) {
                return redirect()->to($request->url());
            }
        }

        abort_unless(self::authorised($request), 404);

        return $next($request);
    }

    /**
     * The session holds the current token's hash, and once begin() pinned a host the request comes on it or on its
     * admin host (RL-5); the page re-checks this in mount() and before every action. Before begin() the page opens on
     * any host, so it can name the address to use, and begin() refuses to pin a host the wizard does not accept.
     */
    public static function authorised(Request $request): bool
    {
        $stored = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
        $hash = app(SetupToken::class)->hash();

        if (! is_string($stored) || $hash === null || ! hash_equals($hash, $stored)
            || $request->attributes->get(SetupMode::ATTRIBUTE) !== true || ! DirectRequest::isDirect($request)) {
            return false;
        }

        $pinned = SetupHostGuard::pinnedWizardHost(app(SetupContext::class)->basePath);

        return $pinned === null || SetupHostGuard::allows($request, $pinned);
    }
}
