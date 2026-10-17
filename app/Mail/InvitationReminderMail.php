<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The invitation reminder (§4.5): like InvitationMail, it rebuilds the accept link in the worker, also after a slug
 * rename (CV-12, X13).
 */
final class InvitationReminderMail extends InvitationMailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Reminder: your invitation to :organisation is waiting', ['organisation' => $this->organisationName($this->invitation())]),
        );
    }

    public function content(): Content
    {
        $invitation = $this->invitation();

        return new Content(
            markdown: 'mail.invitation-reminder',
            with: [
                'organisation' => $this->organisationName($invitation),
                'acceptUrl' => $invitation->acceptUrl(),
                'expiresOn' => $this->expiresOn($invitation),
            ],
        );
    }
}
