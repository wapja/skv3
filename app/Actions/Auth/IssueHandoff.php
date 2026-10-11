<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\HandoffToken;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Hosts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Issues a single-use sign-in link for another host (§6.4): valid 60 seconds, bound to the user and the
 * target host, only for relative paths. Super admins may enter any host; members only their own
 * organisation's host (used after a slug rename). Session cookies stay host-only. Whether the user may
 * still sign in at all (active, not deleted) is decided when the link is used, by the host login rule.
 *
 * A handoff is a sign-in that skips the second factor: callers pass only the authenticated actor, never
 * a user taken from input (FU3-1).
 */
final class IssueHandoff
{
    public const int TTL_SECONDS = 60;

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function handle(User $user, string $targetHost, string $path = '/dashboard'): string
    {
        $targetHost = Str::lower($targetHost);

        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            throw new InvalidArgumentException('A handoff path must be a relative path on the target host.');
        }

        if (! $this->mayEnter($user, $targetHost)) {
            throw new AuthorizationException('This user may not sign in on that host.');
        }

        $token = Str::random(64);

        HandoffToken::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'target_host' => $targetHost,
            'path' => $path,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);

        return Hosts::url($targetHost, '/auth/handoff/'.$token);
    }

    private function mayEnter(User $user, string $host): bool
    {
        if (! $user->isSuperAdmin()) {
            try {
                return $host === Hosts::homeOf($user);
            } catch (LogicException) {
                // FU3-4: a member whose organisation is archived (or missing) has no host to enter.
                return false;
            }
        }

        return in_array($host, [Hosts::apex(), Hosts::admin()], true) || $this->isOrganisationHost($host);
    }

    private function isOrganisationHost(string $host): bool
    {
        $slug = Hosts::slugOf($host);

        return $slug !== null && Organisation::query()->where('slug', $slug)->exists();
    }
}
