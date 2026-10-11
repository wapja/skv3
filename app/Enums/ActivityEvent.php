<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Str;
use LogicException;

/**
 * The audit vocabulary (§4.8). The value is stored as the entry's event and description, group() as its
 * log name. Entries of the authentication, security and impersonation groups are tagged with the host's
 * organisation, every other entry with its subject's (RL-5). Not the vendor's
 * Spatie\Activitylog\Enums\ActivityEvent (created, updated, …), which the kit does not use.
 */
enum ActivityEvent: string
{
    case Login = 'auth.login';
    case LoginFailed = 'auth.login_failed';
    case Logout = 'auth.logout';
    case PasswordResetRequested = 'auth.password_reset_requested';
    case PasswordResetCompleted = 'auth.password_reset_completed';
    case HandoffConsumed = 'auth.handoff_consumed';
    case TwoFactorFailed = 'auth.two_factor_failed';
    case RecoveryCodeUsed = 'auth.recovery_code_used';
    case PasswordChanged = 'security.password_changed';
    case TwoFactorEnabled = 'security.two_factor_enabled';
    case TwoFactorDisabled = 'security.two_factor_disabled';
    case RecoveryCodesRegenerated = 'security.recovery_codes_regenerated';
    case PasskeyRegistered = 'security.passkey_registered';
    case PasskeyDeleted = 'security.passkey_deleted';
    case UserUpdated = 'user.updated';
    case UserStatusChanged = 'user.status_changed';
    case UserDeleted = 'user.deleted';
    case UserRestored = 'user.restored';
    case UserRolesChanged = 'user.roles_changed';
    case SuperAdminGranted = 'user.super_admin_granted';
    case SuperAdminRevoked = 'user.super_admin_revoked';
    case InvitationSent = 'invitation.sent';
    case InvitationReminderSent = 'invitation.reminder_sent';
    case InvitationAccepted = 'invitation.accepted';
    case InvitationCancelled = 'invitation.cancelled';
    case OrganisationCreated = 'organisation.created';
    case OrganisationUpdated = 'organisation.updated';
    case OrganisationArchived = 'organisation.archived';
    case OrganisationRestored = 'organisation.restored';
    case OrganisationPurged = 'organisation.purged';
    case RoleCreated = 'role.created';
    case RoleUpdated = 'role.updated';
    case RoleDeleted = 'role.deleted';
    case ImpersonationStarted = 'impersonation.start';
    case ImpersonationStopped = 'impersonation.stop';

    public function label(): string
    {
        return match ($this) {
            self::Login => __('Signed in'),
            self::LoginFailed => __('Sign-in failed'),
            self::Logout => __('Signed out'),
            self::PasswordResetRequested => __('Password reset requested'),
            self::PasswordResetCompleted => __('Password reset completed'),
            self::HandoffConsumed => __('Signed in with a handoff link'),
            self::TwoFactorFailed => __('Two-factor code rejected'),
            self::RecoveryCodeUsed => __('Recovery code used'),
            self::PasswordChanged => __('Password changed'),
            self::TwoFactorEnabled => __('Two-factor authentication enabled'),
            self::TwoFactorDisabled => __('Two-factor authentication disabled'),
            self::RecoveryCodesRegenerated => __('Recovery codes regenerated'),
            self::PasskeyRegistered => __('Passkey added'),
            self::PasskeyDeleted => __('Passkey removed'),
            self::UserUpdated => __('User updated'),
            self::UserStatusChanged => __('User status changed'),
            self::UserDeleted => __('User deleted'),
            self::UserRestored => __('User restored'),
            self::UserRolesChanged => __('Roles changed'),
            self::SuperAdminGranted => __('Super admin granted'),
            self::SuperAdminRevoked => __('Super admin revoked'),
            self::InvitationSent => __('Invitation sent'),
            self::InvitationReminderSent => __('Invitation resent'),
            self::InvitationAccepted => __('Invitation accepted'),
            self::InvitationCancelled => __('Invitation cancelled'),
            self::OrganisationCreated => __('Organisation created'),
            self::OrganisationUpdated => __('Organisation updated'),
            self::OrganisationArchived => __('Organisation archived'),
            self::OrganisationRestored => __('Organisation restored'),
            self::OrganisationPurged => __('Organisation purged'),
            self::RoleCreated => __('Role created'),
            self::RoleUpdated => __('Role updated'),
            self::RoleDeleted => __('Role deleted'),
            self::ImpersonationStarted => __('Impersonation started'),
            self::ImpersonationStopped => __('Impersonation stopped'),
        };
    }

    /** The log name: authentication, security, users, invitations, organisations, roles or impersonation. */
    public function group(): string
    {
        return match (Str::before($this->value, '.')) {
            'auth' => 'authentication',
            'security' => 'security',
            'user' => 'users',
            'invitation' => 'invitations',
            'organisation' => 'organisations',
            'role' => 'roles',
            'impersonation' => 'impersonation',
            default => throw new LogicException("Activity event [{$this->value}] has no group."),
        };
    }

    /** RL-5 host mode: tagged with the host's organisation (central = none), whatever the subject. */
    public function tagsByHost(): bool
    {
        return in_array($this->group(), ['authentication', 'security', 'impersonation'], true);
    }
}
