<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The invitation mail (§4.5); see InvitationMailable for when it is sent and how its link is built. */
final class InvitationMail extends InvitationMailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('You have been invited to :organisation', ['organisation' => $this->organisationName($this->invitation())]),
        );
    }

    public function content(): Content
    {
        $invitation = $this->invitation();

        return new Content(
            markdown: 'mail.invitation',
            with: [
                'organisation' => $this->organisationName($invitation),
                'acceptUrl' => $invitation->acceptUrl(),
                'expiresOn' => $this->expiresOn($invitation),
            ],
        );
    }
}
