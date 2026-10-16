<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Enums\UserStatus;
use App\Models\User;
use App\Rules\AvailableEmail;
use App\Tenancy\HostScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Restores a deleted user (§4.4, DEC-26 (11)). restore is not always-checked, so the domain guards
 * live here and apply to super admins too: never while the organisation is archived (restore the
 * organisation instead), never when the address is in use again (W9), and never for a pending user,
 * whose invitation was cancelled (C-9). The archived check reads the organisation row locked first in the
 * transaction (CD-5), so a restore and an archive of the same organisation take turns.
 */
final readonly class RestoreUser
{
    public function __construct(private RecordActivity $recordActivity) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, User $target): void
    {
        Gate::forUser($actor)->authorize('restore', $target);

        if (! $target->trashed()) {
            return;
        }

        if ($target->status === UserStatus::PendingActivation) {
            throw ValidationException::withMessages(['user' => [__('This user never accepted their invitation. Invite the address again.')]]);
        }

        try {
            DB::transaction(function () use ($actor, $target): void {
                // CD-5: the organisation first, so an archive that commits while this restore waits is seen.
                $organisation = HostScope::lockOrganisation($target->organisation_id);

                if ($organisation?->trashed() === true) {
                    throw ValidationException::withMessages(['user' => [__('Restore the organisation first.')]]);
                }

                Validator::make(
                    ['email' => $target->email],
                    ['email' => [new AvailableEmail($target->organisation_id, $target->id)]],
                )->validate();

                $target->restore();

                $this->recordActivity->handle(ActivityEvent::UserRestored, $target, causer: $actor);
            });
        } catch (UniqueConstraintViolationException) {
            // The address was taken between validation and restore (FU3-8).
            $target->refresh();

            throw ValidationException::withMessages(['email' => [__('This address can\'t be used.')]]);
        }
    }
}
