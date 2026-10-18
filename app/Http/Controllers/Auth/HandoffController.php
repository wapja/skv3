<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Users\StopImpersonation;
use App\Events\HandoffConsumed;
use App\Http\Controllers\Controller;
use App\Models\HandoffToken;
use App\Models\User;
use App\Tenancy\Auth\HostLoginRule;
use App\Tenancy\Auth\Impersonation;
use App\Tenancy\Hosts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Consumes a handoff link on its target host (§6.4). A guest is signed in. The user the link was issued
 * for, already signed in here, just continues: no new sign-in, so the session and the remember token stay
 * as they are. A different signed-in user is asked first, and only the CSRF-protected confirmation POST
 * replaces this browser's session; a GET never replaces another user's session (FU3-2, RL-22).
 * An impersonating session is always asked first, and consuming ends the impersonation (logged) before
 * the sign-in (RL-20).
 */
final class HandoffController extends Controller
{
    public function __construct(private readonly StopImpersonation $stopImpersonation) {}

    public function show(Request $request, HostLoginRule $rule, string $token): RedirectResponse|Response
    {
        $handoff = $this->usableHandoff($request, $token);
        $user = $handoff instanceof HandoffToken ? $this->userOf($handoff, $rule) : null;

        if (! $handoff instanceof HandoffToken || ! $user instanceof User) {
            return $this->refuse();
        }

        $current = $request->user();

        if ($current instanceof User && (! $current->is($user) || Impersonation::isActive())) {
            return response()
                ->view('pages::auth.handoff-confirm', [
                    'currentName' => $current->name,
                    'targetName' => $user->name,
                    'action' => route('auth.handoff.store', ['token' => $token]),
                ])
                ->header('Referrer-Policy', 'no-referrer');
        }

        return $this->consume($request, $handoff, $user);
    }

    public function store(Request $request, HostLoginRule $rule, string $token): RedirectResponse
    {
        $handoff = $this->usableHandoff($request, $token);
        $user = $handoff instanceof HandoffToken ? $this->userOf($handoff, $rule) : null;

        if (! $handoff instanceof HandoffToken || ! $user instanceof User) {
            return $this->refuse();
        }

        return $this->consume($request, $handoff, $user);
    }

    /**
     * Claims the token, signs the user in unless they already are, and redirects to the token's path:
     * absolute on the target host so the redirect never depends on a URL origin forced earlier.
     */
    private function consume(Request $request, HandoffToken $handoff, User $user): RedirectResponse
    {
        if (! $this->claim($handoff)) {
            return $this->refuse();
        }

        // RL-20: a running impersonation ends (logged) before the session is replaced, and never takes the
        // same-user shortcut below.
        $wasImpersonating = Impersonation::isActive();
        $this->stopImpersonation->handle('handoff');

        $current = $request->user();

        if ($wasImpersonating || ! $current instanceof User || ! $current->is($user)) {
            $this->signIn($request, $user);
        }

        event(new HandoffConsumed($user, $handoff->target_host));

        return redirect()->to(Hosts::url($handoff->target_host, $handoff->path))->header('Referrer-Policy', 'no-referrer');
    }

    /** An unconsumed, unexpired token issued for this host. Reading it changes nothing. */
    private function usableHandoff(Request $request, string $token): ?HandoffToken
    {
        return HandoffToken::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('target_host', Str::lower($request->getHost()))
            ->first();
    }

    /**
     * The token's user, read without the tenant scope, when the link was issued under their current password and the
     * host login rule lets them in here. A password changed or reset since the link was issued, by any path, ends it,
     * as does a link issued before links were bound to the password (CD-3).
     */
    private function userOf(HandoffToken $handoff, HostLoginRule $rule): ?User
    {
        $user = Auth::createUserProvider('users')?->retrieveById($handoff->user_id);

        return $user instanceof User && $handoff->isBoundTo($user) && $rule->allows($user) ? $user : null;
    }

    /** Atomic: only one request can mark the token as consumed, and only before it expires. */
    private function claim(HandoffToken $handoff): bool
    {
        return HandoffToken::query()
            ->whereKey($handoff->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update(['consumed_at' => now()]) === 1;
    }

    /**
     * Replaces this browser's session only. logoutCurrentDevice() leaves the previous user's remember
     * token alone (logout() would cycle it and sign them out everywhere); nothing from the earlier
     * session (password confirmation, intended URL) carries over.
     */
    private function signIn(Request $request, User $user): void
    {
        $guard = Auth::guard('web');

        if ($guard->check()) {
            $guard->logoutCurrentDevice();
        }

        $request->session()->invalidate();
        $guard->login($user);
        $request->session()->regenerateToken();
    }

    private function refuse(): RedirectResponse
    {
        return to_route('login')->with('status', __('This sign-in link is invalid or has expired.'));
    }
}
