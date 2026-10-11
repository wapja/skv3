<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the old address after an administrator changed it (§4.4, D-14); the M3 parity of EmailChangeRequested. */
final class EmailChangedByAdministrator extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $newEmail)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage()
            ->subject(__('An administrator changed your email address'))
            ->line(__('An administrator changed the email address of your account to :email.', ['email' => $this->newEmail]))
            ->line(__('From now on you sign in with that address.'))
            ->line(__('If you did not expect this, contact your administrator.'));
    }
}
