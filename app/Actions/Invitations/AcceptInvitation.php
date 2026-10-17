<?php

declare(strict_types=1);

namespace App\Actions\Invitations;

use App\Actions\Audit\RecordActivity;
use App\Concerns\ProfileValidationRules;
use App\Enums\ActivityEvent;
use App\Enums\UserStatus;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Rules\NotAPasswordHash;
use App\Tenancy\HostScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Accepts an invitation (§4.5) on the invitation's tenant host. The organisation, the invitee's row and the
 * invitation are locked in that order (CD-5, G4-07), and the invitation's state is re-checked inside the
 * transaction (X9); the pending user must still exist, still be pending and not be a super admin (RK-M3, §6.3),
 * the invitee chooses their own name (W14), and the stored token copy is cleared so the link can never be rebuilt
 * (RK-B3). The user is saved quietly because this action records its own event (RL-6). Signing in is the
 * caller's job.
 */
final readonly class AcceptInvitation
{
    use ProfileValidationRules;

    public function __construct(private RecordActivity $recordActivity) {}

    /** @throws ValidationException */
    public function handle(Invitation $invitation, string $name, string $password): User
    {
        $name = trim($name);

        Validator::make(['name' => $name, 'password' => $password], [
            'name' => $this->nameRules(),
            'password' => ['required', 'string', Password::default(), new NotAPasswordHash],
        ])->validate();

        return DB::transaction(function () use ($invitation, $name, $password): User {
            // CD-5 (G4-07): the organisation, then the invitee's row, then the invitation, the order ArchiveOrganisation
            // takes too before its cascade reaches the invitations. An archived organisation is refused here.
            HostScope::lockForWrite(Organisation::withTrashed()->findOrFail($invitation->organisation_id));
            $invitee = User::withTrashed()->lockForUpdate()->find($invitation->user_id);
            $locked = Invitation::query()->lockForUpdate()->find($invitation->id)
                ?? throw ValidationException::withMessages(['invitation' => __('This invitation is no longer valid.')]);

            if ($locked->isAccepted()) {
                throw ValidationException::withMessages([
                    'invitation' => __('This invitation has already been accepted. Please log in.'),
                ]);
            }

            if ($locked->isExpired()) {
                throw ValidationException::withMessages(['invitation' => __('This invitation has expired.')]);
            }

            // A pending user who became a super admin is never activated through a link (§6.3).
            if (! $invitee instanceof User || $invitee->trashed() || $invitee->status !== UserStatus::PendingActivation || $invitee->isSuperAdmin()) {
                throw ValidationException::withMessages(['invitation' => __('This invitation is no longer valid.')]);
            }

            $invitee->forceFill([
                'name' => $name,
                'password' => $password,
                'status' => UserStatus::Active,
            ])->saveQuietly();

            $locked->forceFill(['accepted_at' => now(), 'token' => null])->save();

            $this->recordActivity->handle(ActivityEvent::InvitationAccepted, $invitee, [
                'invitation_id' => $locked->id,
                'email' => $invitee->email,
            ], causer: $invitee);

            return $invitee;
        });
    }
}
