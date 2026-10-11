<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Tenancy\Auth\HostLoginRule;
use App\Tenancy\Auth\Impersonation;

/**
 * Ends this session's impersonation (§4.7, RL-20). impersonation.stop is logged first, while the session
 * still says who impersonated whom, with an explicit causer: the impersonator, or nobody plus
 * properties.impersonator_id when they no longer exist (RK-M6). Then the impersonator is back in their own
 * account when they still qualify (exist, active, allowed on this host, unchanged password); otherwise
 * this browser is signed out. Returns the restored impersonator, or null.
 */
final readonly class StopImpersonation
{
    public function __construct(
        private RecordActivity $recordActivity,
        private HostLoginRule $rule,
    ) {}

    /** @param  string|null  $endedBecause  why the stop was not asked for (module_disabled, target_unavailable, target_credentials_changed, no_longer_allowed, impersonator_unavailable, impersonator_credentials_changed, handoff, logout) */
    public function handle(?string $endedBecause = null): ?User
    {
        if (! Impersonation::isActive()) {
            return null;
        }

        // EnforceImpersonation calls this before Authenticate ran: from here on nothing in this request may
        // resolve the guard's user from the impersonator's remember-me cookie when the target is gone (RK-I1).
        Impersonation::ignoreRememberCookie();

        $impersonator = Impersonation::impersonator();
        $properties = ['impersonated_as' => Impersonation::targetId()];

        if (! $impersonator instanceof User) {
            $properties['impersonator_id'] = Impersonation::impersonatorId();
        }

        if ($endedBecause !== null) {
            $properties['ended_because'] = $endedBecause;
        }

        $this->recordActivity->handle(
            ActivityEvent::ImpersonationStopped,
            Impersonation::target(),
            $properties,
            causer: $impersonator,
            defaultCauser: false,
        );

        if ($impersonator instanceof User
            && $this->rule->allows($impersonator)
            && Impersonation::impersonatorPasswordUnchanged($impersonator)) {
            return Impersonation::leave();
        }

        Impersonation::signOut();

        return null;
    }
}
