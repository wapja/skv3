<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Reads and write locks for any-host pages and actions (§6.2, RL-8). On a tenant host tenant models stay
 * scoped to that organisation; on a central host only a super admin reads across organisations, inside
 * Tenant::bypass(). Pages never join the bypass allow-list: they read tenant models through read().
 *
 * Only tenant models are scoped. Central models (organisations, Spatie roles and pivots, sessions) are
 * filtered by the caller with organisationId(), and organisation ids sent by the client are ignored on a
 * tenant host (RK-I4).
 */
final class HostScope
{
    /**
     * Runs the callback with the host's tenant scope. The callback must run its queries itself: global
     * scopes apply when a query executes, so a builder returned from it would run outside the bypass.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws AuthorizationException
     */
    public static function read(Closure $callback): mixed
    {
        if (Tenant::check()) {
            return $callback();
        }

        self::ensureSuperAdmin();

        return Tenant::bypass($callback);
    }

    /**
     * The organisation whose central rows a page may show: the tenant on a tenant host, null (every
     * organisation) for a super admin on a central host.
     *
     * @throws AuthorizationException
     */
    public static function organisationId(): ?int
    {
        $organisation = Tenant::current();

        if ($organisation instanceof Organisation) {
            return $organisation->id;
        }

        self::ensureSuperAdmin();

        return null;
    }

    /**
     * Locks the organisation row inside the caller's transaction and returns the fresh row. Refuses another
     * tenant's organisation while a tenant is bound, then an archived one (RK-I11). Actions call it before
     * Tenant::run(): inside the run the bound tenant is always the organisation itself.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public static function lockForWrite(Organisation $organisation): Organisation
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('HostScope::lockForWrite() must run inside a database transaction.');
        }

        $locked = Organisation::withTrashed()->lockForUpdate()->findOrFail($organisation->id);
        $tenant = Tenant::current();

        if ($tenant instanceof Organisation && $tenant->id !== $locked->id) {
            throw new AuthorizationException;
        }

        if ($locked->trashed()) {
            throw ValidationException::withMessages([
                'organisation' => __('This organisation is archived.'),
            ]);
        }

        return $locked;
    }

    /** @throws AuthorizationException */
    private static function ensureSuperAdmin(): void
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof User || ! $user->isSuperAdmin()) {
            throw new AuthorizationException;
        }
    }
}
