<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\App;
use LogicException;

/**
 * The invitation mails (§4.5). Queued after commit, so a rolled-back invite sends nothing and a transport failure
 * leaves a remindable invitation (W10). The job carries the invitation's id, never the model (G4-05): the worker
 * reads the invitation again under the job's tenant (JobTenancy) and sends nothing once it was cancelled, accepted
 * or has expired, instead of failing with a job that `queue:retry` can only fail again. The accept link is built
 * when the worker renders the mail, on the organisation's host (TEN-6, FU3-14; CV-12, X13), and dates are written
 * in the mail's locale (X11).
 */
abstract class InvitationMailable extends Mailable implements ShouldQueue
{
    use Queueable;

    public readonly int $invitationId;

    public function __construct(Invitation $invitation)
    {
        $this->invitationId = $invitation->id;
        $this->afterCommit();
    }

    /**
     * Sends only while the invitation still waits for its invitee (G4-05).
     *
     * @param  MailFactory|Mailer  $mailer
     */
    public function send($mailer): ?SentMessage
    {
        $invitation = Invitation::query()->find($this->invitationId);

        if ($invitation === null || $invitation->isAccepted() || $invitation->token === null || $invitation->isExpired()) {
            return null;
        }

        return parent::send($mailer);
    }

    /** The stored invitation; the mail is built from it when it is sent or rendered, never when it is queued. */
    protected function invitation(): Invitation
    {
        return Invitation::query()->with('organisation')->find($this->invitationId)
            ?? throw new LogicException("Invitation [{$this->invitationId}] no longer exists.");
    }

    protected function organisationName(Invitation $invitation): string
    {
        return $invitation->organisation->name
            ?? throw new LogicException("Invitation [{$invitation->id}] has no active organisation.");
    }

    /** The expiry date in the mail's locale (X11); the mailer sets the app locale while it renders. */
    protected function expiresOn(Invitation $invitation): string
    {
        $expiresAt = $invitation->expires_at->copy();
        $expiresAt->locale(App::currentLocale());

        return $expiresAt->isoFormat('LL');
    }
}
