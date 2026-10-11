<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use LogicException;

/**
 * The invitation reminder (§4.5). Like InvitationMail: queued after commit, the accept link built
 * in the worker on the organisation's host (CV-12; it also rebuilds links after a slug rename, X13)
 * and dates in the mail's locale (X11).
 */
final class InvitationReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Invitation $invitation)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Reminder: your invitation to :organisation is waiting', ['organisation' => $this->organisationName()]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.invitation-reminder',
            with: [
                'organisation' => $this->organisationName(),
                'acceptUrl' => $this->invitation->acceptUrl(),
                'expiresOn' => $this->expiresOn(),
            ],
        );
    }

    /** The expiry date in the mail's locale (X11); the mailer sets the app locale while it renders. */
    private function expiresOn(): string
    {
        $expiresAt = $this->invitation->expires_at->copy();
        $expiresAt->locale(App::currentLocale());

        return $expiresAt->isoFormat('LL');
    }

    private function organisationName(): string
    {
        return $this->invitation->organisation->name
            ?? throw new LogicException("Invitation [{$this->invitation->id}] has no active organisation.");
    }
}
