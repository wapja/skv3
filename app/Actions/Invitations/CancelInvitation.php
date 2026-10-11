<?php

declare(strict_types=1);

namespace App\Actions\Invitations;

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\HostScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Cancels an open invitation (§4.5): the invitation is force-deleted (a tenant model with soft
 * deletes, so "hard delete" means forceDelete, C-8) and the pending user is soft-deleted, which
 * frees the address for a new invitation (INV-14) and keeps the user out of restore (C-9).
 * Refused once accepted.
 */
final readonly class CancelInvitation
{
    public function __construct(private RecordActivity $recordActivity) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Invitation $invitation): void
    {
        Gate::forUser($actor)->authorize('cancel', $invitation);

        DB::transaction(function () use ($actor, $invitation): void {
            $organisation = HostScope::lockForWrite(Organisation::withTrashed()->findOrFail($invitation->organisation_id));

            Tenant::run($organisation, function () use ($actor, $invitation): void {
                $locked = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);

                if ($locked->isAccepted()) {
                    throw ValidationException::withMessages([
                        'invitation' => __('This invitation has already been accepted.'),
                    ]);
                }

                $invitee = $locked->user;

                $locked->forceDelete();
                $invitee->delete();

                $this->recordActivity->handle(ActivityEvent::InvitationCancelled, $invitee, [
                    'invitation_id' => $locked->id,
                    'email' => $invitee->email,
                ], causer: $actor);
            });
        });
    }
}
