<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Actions\Settings\RequestEmailChange;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the new address; the change applies only after this link is followed (E1). */
final class ConfirmEmailChange extends Notification implements ShouldQueue
{
    use Queueable;

    /** The link is built during the request, on the user's own host (§6.2). */
    public function __construct(public readonly string $url)
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
            ->subject(__('Confirm your new email address'))
            ->line(__('Confirm that you want to use this email address for your account.'))
            ->action(__('Confirm email address'), $this->url)
            ->line(__('This link expires in :count minutes.', ['count' => RequestEmailChange::EXPIRES_IN_MINUTES]))
            ->line(__('If you did not ask for this, you can ignore this email.'));
    }
}
