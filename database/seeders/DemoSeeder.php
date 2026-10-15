<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Invitations\AcceptInvitation;
use App\Actions\Invitations\InviteUser;
use App\Actions\Organisations\CreateOrganisation;
use App\Actions\Roles\CreateRole;
use App\Authorization\Permission;
use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Enums\Locale;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\Hosts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Optional demo data (§4.12, RL-8): a super admin and the organisations demo1 and demo2, each with an admin, a user
 * and a pending invitation, built through the real actions so the audit log has entries (DEC-28). Emails derive
 * from the apex; every demo account shares the password "password". Only in local and testing. Idempotent.
 */
final class DemoSeeder extends Seeder
{
    public const array SLUGS = ['demo1', 'demo2'];

    public const string PASSWORD = 'password';

    /** The custom role of the demo user and the pending invitation (RL-8: InviteUser needs at least one role). */
    private const string MEMBER_ROLE = 'Member';

    /**
     * @param  string|null  $apex  the apex to derive emails and hosts from; null reads the configured one (setup passes its detected host)
     * @return list<array{email: string, role: string, host: string}>
     */
    public static function accounts(?string $apex = null): array
    {
        $apex = $apex === null ? Hosts::apex() : Str::lower($apex);
        $adminHost = Str::lower((string) config('tenancy.admin_subdomain')).'.'.$apex;
        $accounts = [['email' => "superadmin@{$apex}", 'role' => Role::SuperAdmin->value, 'host' => $adminHost]];

        foreach (self::SLUGS as $slug) {
            $accounts[] = ['email' => "admin@{$slug}.{$apex}", 'role' => Role::OrganisationAdmin->value, 'host' => "{$slug}.{$apex}"];
            $accounts[] = ['email' => "user@{$slug}.{$apex}", 'role' => self::MEMBER_ROLE, 'host' => "{$slug}.{$apex}"];
        }

        return $accounts;
    }

    public function run(SetSuperAdmin $setSuperAdmin, CreateOrganisation $createOrganisation, CreateRole $createRole, InviteUser $inviteUser, AcceptInvitation $acceptInvitation): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The demo seeder runs only in the local and testing environments.');
        }

        // Invitations are accepted at once: send their mails now, while the token still exists.
        $queue = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            $superAdmin = $this->superAdmin($setSuperAdmin);
            $apex = Hosts::apex();

            foreach (self::SLUGS as $index => $slug) {
                if (Tenant::bypass(fn (): bool => Organisation::query()->withTrashed()->where('slug', $slug)->exists())) {
                    continue;
                }

                $organisation = $createOrganisation->handle($superAdmin, 'Demo '.($index + 1), $slug, null, "admin@{$slug}.{$apex}", Locale::default());
                $memberRole = [(int) $createRole->handle($superAdmin, $organisation, self::MEMBER_ROLE, [Permission::UsersView])->getKey()];

                Tenant::run($organisation, function () use ($organisation, $superAdmin, $inviteUser, $acceptInvitation, $memberRole, $slug, $apex): void {
                    $acceptInvitation->handle($this->openInvitationFor("admin@{$slug}.{$apex}"), 'Demo admin', self::PASSWORD);
                    $acceptInvitation->handle($inviteUser->handle($superAdmin, $organisation, "user@{$slug}.{$apex}", $memberRole, Locale::default()), 'Demo user', self::PASSWORD);
                    $inviteUser->handle($superAdmin, $organisation, "invited@{$slug}.{$apex}", $memberRole, Locale::default());
                });
            }
        } finally {
            config(['queue.default' => $queue]);
        }
    }

    private function superAdmin(SetSuperAdmin $setSuperAdmin): User
    {
        $email = 'superadmin@'.Hosts::apex();
        $existing = Tenant::bypass(fn (): ?User => User::query()->where('email', $email)->first());

        if ($existing instanceof User) {
            return $existing;
        }

        $user = User::factory()->superAdmin()->create(['name' => 'Demo super admin', 'email' => $email, 'password' => self::PASSWORD]);
        // Inserted with the flag (W8): handleCreated() attaches the badge and records the grant (§4.8).
        $setSuperAdmin->handleCreated($user);

        return $user;
    }

    private function openInvitationFor(string $email): Invitation
    {
        return Invitation::query()
            ->whereNull('accepted_at')
            ->whereHas('user', fn (Builder $query): Builder => $query->where('email', $email))
            ->latest('id')
            ->firstOrFail();
    }
}
