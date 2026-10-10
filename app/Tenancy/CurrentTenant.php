<?php

declare(strict_types=1);

namespace App\Tenancy;

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
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return (TReturn is PendingDispatch ? null : TReturn)
     */
    public function run(Organisation $organisation, Closure $callback): mixed
    {
        $previous = $this->organisation;
        $this->set($organisation);

        try {
            $result = $callback();

            // A PendingDispatch queues in its destructor; destroy it while this tenant is bound (§6.2).
            if ($result instanceof PendingDispatch) {
                unset($result);

                return null;
            }

            return $result;
        } finally {
            if ($previous instanceof Organisation) {
                $this->set($previous);
            } else {
                $this->forget();
            }
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
}
