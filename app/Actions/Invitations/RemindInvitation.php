<?php

declare(strict_types=1);

namespace App\Actions\Invitations;

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Mail\InvitationReminderMail;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\HostScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Sends an invitation again (§4.5, C-10): renews the domain expiry, keeps the token, sets
 * reminder_sent_at and queues the reminder after commit. Allowed for an expired invitation,
 * refused once accepted (W5) and within COOLDOWN_MINUTES of the last reminder (CD-3), decided on
 * the locked row. The mail builds its link in the worker (CV-12).
 */
final readonly class RemindInvitation
{
    /** One reminder per invitation per this many minutes, whoever sends it (CD-3). */
    public const int COOLDOWN_MINUTES = 10;

    public function __construct(private RecordActivity $recordActivity) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Invitation $invitation): void
    {
        Gate::forUser($actor)->authorize('remind', $invitation);

        DB::transaction(function () use ($actor, $invitation): void {
            $organisation = HostScope::lockForWrite(Organisation::withTrashed()->findOrFail($invitation->organisation_id));

            Tenant::run($organisation, function () use ($actor, $invitation): void {
                $locked = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);

                if ($locked->isAccepted()) {
                    throw ValidationException::withMessages([
                        'invitation' => __('This invitation has already been accepted.'),
                    ]);
                }

                if ($locked->reminder_sent_at?->isAfter(now()->subMinutes(self::COOLDOWN_MINUTES)) === true) {
                    throw ValidationException::withMessages([
                        'invitation' => __('This invitation was sent again less than :minutes minutes ago.', ['minutes' => self::COOLDOWN_MINUTES]),
                    ]);
                }

                $locked->forceFill([
                    'expires_at' => now()->addDays((int) config('skv.invitations.expires_in_days', 7)),
                    'reminder_sent_at' => now(),
                ])->save();

                $invitee = $locked->user;

                $this->recordActivity->handle(ActivityEvent::InvitationReminderSent, $invitee, [
                    'invitation_id' => $locked->id,
                    'email' => $invitee->email,
                ], causer: $actor);

                Mail::to($invitee->email)->locale($invitee->locale->value)->queue(new InvitationReminderMail($locked));

                // The caller's model gets the stored values here: a refresh() after Tenant::run() would reload its
                // loaded relations without a tenant, which fails on central hosts after the reminder went out.
                $invitation->setRawAttributes($locked->getAttributes(), true);
            });
        });
    }
}
