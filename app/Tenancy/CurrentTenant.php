<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Authorization\PermissionsTeam;
use App\Models\Organisation;
use App\Tenancy\Events\TenantForgotten;
use App\Tenancy\Events\TenantResolved;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * The organisation of the current request or job (§6.2). Registered as a scoped singleton,
 * so the queue worker starts every job without one.
 */
final class CurrentTenant
{
    private ?Organisation $organisation = null;

    private int $bypassDepth = 0;

    public function __construct(private readonly Dispatcher $events) {}

    public function current(): ?Organisation
    {
        return $this->organisation;
    }

    public function check(): bool
    {
        return $this->organisation instanceof Organisation;
    }

    public function set(Organisation $organisation): void
    {
        $this->organisation = $organisation;
        $this->events->dispatch(new TenantResolved($organisation));
    }

    public function forget(): void
    {
        $this->organisation = null;
        $this->events->dispatch(new TenantForgotten);
    }

    /**
     * Runs the callback for one organisation. The innermost context wins: a surrounding bypass is
     * suspended while this tenant is bound. Afterwards the tenant, the bypass and the Spatie team
     * found on entry are restored (a withPermissionsTeam() team survives a nested run).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return (TReturn is PendingDispatch ? null : TReturn)
     */
    public function run(Organisation $organisation, Closure $callback): mixed
    {
        $previous = $this->organisation;
        $previousTeam = PermissionsTeam::current();
        $this->set($organisation);
        $previousBypass = $this->suspendBypass();

        try {
            $result = $callback();

            // A PendingDispatch queues in its destructor; destroy it while this tenant is bound (§6.2).
            if ($result instanceof PendingDispatch) {
                unset($result);

                return null;
            }

            return $result;
        } finally {
            $this->resumeBypass($previousBypass);

            if ($previous instanceof Organisation) {
                $this->set($previous);
            } else {
                $this->forget();
            }

            PermissionsTeam::set($previousTeam);
        }
    }

    /**
     * Reads tenant models across organisations. Allowed only in the namespaces listed in §6.2
     * (enforced by tests/Arch/TenancyRulesTest.php).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function bypass(Closure $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    public function isBypassed(): bool
    {
        return $this->bypassDepth > 0;
    }

    /** Lifts every surrounding bypass while a tenant is bound; returns the depth for resumeBypass(). */
    public function suspendBypass(): int
    {
        $depth = $this->bypassDepth;
        $this->bypassDepth = 0;

        return $depth;
    }

    public function resumeBypass(int $depth): void
    {
        $this->bypassDepth = $depth;
    }
}
