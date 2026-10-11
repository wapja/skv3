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
 * The invitation mail (§4.5). Queued after commit, so a rolled-back invite sends nothing and a
 * transport failure leaves a remindable invitation (W10). The accept link is built when the worker
 * renders the mail, on the organisation's host (TEN-6, FU3-14), and the expiry date is written in
 * the mail's locale (X11). A mail for an invitation cancelled or accepted before the worker runs
 * fails in the worker and is never delivered.
 */
final class InvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Invitation $invitation)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('You have been invited to :organisation', ['organisation' => $this->organisationName()]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.invitation',
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
