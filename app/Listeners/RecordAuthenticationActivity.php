<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Events\HandoffConsumed;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/**
 * Records sign-in and security events in the audit log (§4.8, RL-29). All of them are tagged with the
 * host's organisation (RL-5 host mode). A failed sign-in keeps the typed value only when it is a valid
 * email address, and never any other credential (R-4). Two-factor "enabled" is the confirmation: Fortify's
 * Enabled event fires before the code is confirmed. On a tenant host a password reset is recorded only for
 * the host's own members, never for another organisation's member or a super admin (R1). Auto-discovered
 * (one registration per union member).
 */
final readonly class RecordAuthenticationActivity
{
    /** @var array<class-string, ActivityEvent> */
    private const array EVENTS = [
        Login::class => ActivityEvent::Login,
        Logout::class => ActivityEvent::Logout,
        PasswordResetLinkSent::class => ActivityEvent::PasswordResetRequested,
        PasswordReset::class => ActivityEvent::PasswordResetCompleted,
        HandoffConsumed::class => ActivityEvent::HandoffConsumed,
        TwoFactorAuthenticationFailed::class => ActivityEvent::TwoFactorFailed,
        RecoveryCodeReplaced::class => ActivityEvent::RecoveryCodeUsed,
        TwoFactorAuthenticationConfirmed::class => ActivityEvent::TwoFactorEnabled,
        TwoFactorAuthenticationDisabled::class => ActivityEvent::TwoFactorDisabled,
        RecoveryCodesGenerated::class => ActivityEvent::RecoveryCodesRegenerated,
        PasskeyRegistered::class => ActivityEvent::PasskeyRegistered,
        PasskeyDeleted::class => ActivityEvent::PasskeyDeleted,
    ];

    public function __construct(private RecordActivity $recordActivity) {}

    public function handle(Login|Failed|Logout|PasswordResetLinkSent|PasswordReset|TwoFactorAuthenticationConfirmed|TwoFactorAuthenticationDisabled|RecoveryCodesGenerated|TwoFactorAuthenticationFailed|RecoveryCodeReplaced|PasskeyRegistered|PasskeyDeleted|HandoffConsumed $event): void
    {
        if ($event instanceof Failed) {
            $this->recordFailedSignIn($event);

            return;
        }

        $user = $event->user;
        $activityEvent = self::EVENTS[$event::class] ?? null;

        if (! $user instanceof User || ! $activityEvent instanceof ActivityEvent) {
            return;
        }

        // R1: anyone can type an address into a host's reset form, so a tenant host's log names only its own
        // members. Not a super admin either: the host's admins could then find the platform's super admins.
        // The link goes to the user's own host (R1). Central hosts record every reset.
        $organisation = Tenant::current();

        if (($event instanceof PasswordResetLinkSent || $event instanceof PasswordReset) && $organisation !== null && $user->organisation_id !== $organisation->id) {
            return;
        }

        // The security page clears an abandoned, never confirmed setup on mount: no second factor changed.
        if ($event instanceof TwoFactorAuthenticationDisabled && ! $user->wasChanged('two_factor_confirmed_at')) {
            return;
        }

        // At Login the guard has not stored the user yet, so the signed-in user is named explicitly.
        $signsInOrOut = $event instanceof Login || $event instanceof Logout || $event instanceof HandoffConsumed;

        $this->recordActivity->handle($activityEvent, $user, $this->propertiesOf($event), causer: $signsInOrOut ? $user : null);
    }

    /** R-4: the credentials hold the plain password; only a valid address is kept (CV-17). */
    private function recordFailedSignIn(Failed $event): void
    {
        $typed = $event->credentials[Fortify::username()] ?? null;

        $properties = is_string($typed) && filter_var($typed, FILTER_VALIDATE_EMAIL) !== false
            ? ['email' => Str::lower($typed)]
            : ['email' => null, 'invalid_address' => true];

        $this->recordActivity->handle(ActivityEvent::LoginFailed, $event->user instanceof User ? $event->user : null, $properties);
    }

    /** @return array<string, mixed> */
    private function propertiesOf(object $event): array
    {
        return match (true) {
            $event instanceof Login => ['remember' => (bool) $event->remember],
            $event instanceof HandoffConsumed => ['host' => $event->host],
            $event instanceof PasskeyRegistered, $event instanceof PasskeyDeleted => ['passkey' => $event->passkey->name],
            default => [],
        };
    }
}
