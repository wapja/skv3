<?php

declare(strict_types=1);

namespace App\Actions\Organisations;

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Restores an archived organisation with the rows its archive cascaded (§4.3; restore = organisations.delete,
 * which only super admins hold, C-27). The row is locked and re-checked (RK-I10); a restore is refused while
 * an address of a user it would bring back is now held by another account (C-15). Central hosts only: an
 * archived organisation's host does not resolve, so a bound tenant is always another organisation.
 */
final readonly class RestoreOrganisation
{
    public function __construct(private RecordActivity $recordActivity) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Organisation $organisation): void
    {
        Gate::forUser($actor)->authorize('restore', $organisation);

        if (Tenant::check()) {
            throw new AuthorizationException('Archived organisations are restored on a central host.');
        }

        DB::transaction(function () use ($actor, $organisation): void {
            $locked = Organisation::withTrashed()->lockForUpdate()->findOrFail($organisation->id);

            if (! $locked->trashed()) {
                throw ValidationException::withMessages(['organisation' => __('This organisation is not archived.')]);
            }

            $this->ensureAddressesAreFree($locked);
            $locked->restore();

            $this->recordActivity->handle(ActivityEvent::OrganisationRestored, $locked, [
                'name' => $locked->name,
                'slug' => $locked->slug,
            ], causer: $actor);
        });
    }

    /**
     * The cascade restores the users archived with the organisation (same deleted_at); the partial unique
     * index on users.email would refuse any whose address a non-deleted user holds by now.
     *
     * @throws ValidationException
     */
    private function ensureAddressesAreFree(Organisation $organisation): void
    {
        $addresses = DB::table('users')
            ->where('organisation_id', $organisation->id)
            ->where('deleted_at', $organisation->deleted_at)
            ->pluck('email')
            ->map(fn (mixed $email): string => (string) $email)
            ->all();

        if ($addresses === []) {
            return;
        }

        $taken = DB::table('users')
            ->whereNull('deleted_at')
            ->whereIn('email', $addresses)
            ->orderBy('email')
            ->pluck('email')
            ->map(fn (mixed $email): string => (string) $email)
            ->all();

        if ($taken !== []) {
            throw ValidationException::withMessages([
                'email' => __('These addresses are now used by other accounts: :addresses.', ['addresses' => implode(', ', $taken)]),
            ]);
        }
    }
}
