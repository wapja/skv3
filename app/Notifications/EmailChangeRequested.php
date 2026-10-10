<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the current address when someone asks to change it (E1). */
final class EmailChangeRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $newEmail) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage()
            ->subject(__('Your email address is about to change'))
            ->line(__('Someone asked to change the email address of your account to :email.', ['email' => $this->newEmail]))
            ->line(__('Nothing changes until the link sent to that address is followed.'))
            ->line(__('If this was not you, change your password and contact your administrator.'));
    }
}
