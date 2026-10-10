<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Events\HandoffConsumed;
use App\Http\Controllers\Controller;
use App\Models\HandoffToken;
use App\Models\User;
use App\Tenancy\Auth\HostLoginRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** Consumes a handoff link on its target host and signs the user in there (§6.4). */
final class HandoffController extends Controller
{
    public function __invoke(Request $request, HostLoginRule $rule, string $token): RedirectResponse
    {
        $handoff = HandoffToken::query()->where('token_hash', hash('sha256', $token))->first();

        if (! $handoff instanceof HandoffToken || ! $this->claim($handoff, $request)) {
            return $this->refuse();
        }

        $user = Auth::createUserProvider('users')?->retrieveById($handoff->user_id);

        if (! $user instanceof User || ! $rule->allows($user)) {
            return $this->refuse();
        }

        // A handoff always starts a clean session: nothing from an earlier user (password confirmation,
        // intended URL, later impersonation) carries over.
        if (Auth::check()) {
            Auth::logout();
        }

        $request->session()->invalidate();
        Auth::login($user);
        $request->session()->regenerateToken();

        event(new HandoffConsumed($user, $handoff->target_host));

        return redirect()->to($handoff->path)->header('Referrer-Policy', 'no-referrer');
    }

    /** Atomic: only one request can mark an unexpired token for this host as consumed. */
    private function claim(HandoffToken $handoff, Request $request): bool
    {
        return HandoffToken::query()
            ->whereKey($handoff->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('target_host', Str::lower($request->getHost()))
            ->update(['consumed_at' => now()]) === 1;
    }

    private function refuse(): RedirectResponse
    {
        return to_route('login')->with('status', __('This sign-in link is invalid or has expired.'));
    }
}
