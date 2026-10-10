<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use App\Notifications\EmailChangeRequested;
use App\Tenancy\Hosts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Starts an email change (E1): the new address gets a signed link on the user's own host, the
 * current address gets a notice. Nothing changes until ConfirmEmailChange runs. The link carries a
 * nonce whose hash is cached per user, so only the newest link works, and only once.
 */
final class RequestEmailChange
{
    use ProfileValidationRules;

    public const int EXPIRES_IN_MINUTES = 60;

    public static function cacheKey(User $user): string
    {
        return 'email-change.'.$user->id;
    }

    /** @throws ValidationException */
    public function handle(User $user, string $email): void
    {
        $email = Str::lower(trim($email));

        if ($email === $user->email) {
            return;
        }

        Validator::make(['email' => $email], ['email' => $this->emailRules($user->id)])->validate();

        $nonce = Str::random(40);
        Cache::put(self::cacheKey($user), hash('sha256', $nonce.'|'.$email), now()->addMinutes(self::EXPIRES_IN_MINUTES));

        $url = Hosts::url(Hosts::homeOf($user), URL::temporarySignedRoute(
            'email-change.confirm',
            now()->addMinutes(self::EXPIRES_IN_MINUTES),
            ['user' => $user->id, 'email' => $email, 'nonce' => $nonce],
            absolute: false,
        ));

        Notification::route('mail', $email)->notify(new ConfirmEmailChange($url));
        // The notice goes to the address as it is now, even if the worker runs after the change was confirmed.
        Notification::route('mail', $user->email)->notify(new EmailChangeRequested($email));
    }
}
