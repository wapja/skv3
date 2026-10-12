<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

use App\Authorization\Role;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Support\Branding;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\HostScope;
use Illuminate\Database\Eloquent\Model;

/**
 * The dashboard's getting-started list (§6.5, RL-11): super admins on central hosts, organisation admins on
 * their own host. Tenant models are read through HostScope like any page (App\Support is not a bypass namespace).
 */
final class OnboardingChecklist
{
    /** @return list<array{key: string, label: string, done: bool, url: string|null}> */
    public static function for(User $user): array
    {
        $twoFactor = ['key' => 'two-factor', 'label' => __('Enable two-factor authentication'),
            'done' => $user->hasEnabledTwoFactorAuthentication(), 'url' => route('security.edit')];

        if ($user->isSuperAdmin() && ! Tenant::check()) {
            return [
                ['key' => 'create-organisation', 'label' => __('Create an organisation'),
                    'done' => Organisation::query()->exists(), 'url' => route('organisations.create')],
                ['key' => 'invite-admin', 'label' => __('Invite the first administrator of an organisation'),
                    'done' => HostScope::read(fn (): bool => Invitation::query()->exists()
                        || User::query()->whereNotNull('organisation_id')->exists()),
                    'url' => route('organisations.index')],
                $twoFactor,
                ['key' => 'branding', 'label' => __('Set your branding'), 'done' => Branding::isCustomised(), 'url' => null],
            ];
        }

        // FU2-4: match the global template by id, never by name. Read the roles only on a tenant host,
        // where the tenancy listener has set the permissions team.
        if (Tenant::check() && ! $user->isSuperAdmin() && self::holdsAdminTemplate($user)) {
            return [
                // Every invitation creates a pending user, so another user row covers invited and active colleagues.
                // An Invitation check would count the admin's own accepted invitation.
                ['key' => 'invite-colleague', 'label' => __('Invite a colleague'),
                    'done' => HostScope::read(fn (): bool => User::query()->whereKeyNot($user->id)->exists()),
                    'url' => route('users.invite')],
                $twoFactor,
            ];
        }

        return [];
    }

    /** @param  list<array{done: bool}>  $items */
    public static function isComplete(array $items): bool
    {
        return array_filter($items, fn (array $item): bool => ! $item['done']) === [];
    }

    /** The roles relation is filtered on the current team; the template id is read once, not per role. */
    private static function holdsAdminTemplate(User $user): bool
    {
        $templateId = Role::templateId();

        return $user->roles->contains(fn (Model $role): bool => (int) $role->getKey() === $templateId);
    }
}
