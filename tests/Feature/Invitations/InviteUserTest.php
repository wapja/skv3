<?php

declare(strict_types=1);

/** covers §4.5, §4.6, Appendix A W8, W9, W10, W11, W13, W14 */

use App\Actions\Invitations\InviteUser;
use App\Authorization\Permission;
use App\Authorization\PermissionsTeam;
use App\Authorization\Role;
use App\Enums\ActivityEvent;
use App\Enums\Locale;
use App\Enums\UserStatus;
use App\Mail\InvitationMail;
use App\Models\Activity;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as RoleModel;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

$templateRoleId = fn (): int => (int) RoleModel::query()->whereNull('team_id')->where('name', Role::OrganisationAdmin->value)->value('id');

$teamRoleWith = function (Organisation $organisation, string $name, Permission ...$permissions): RoleModel {
    $role = RoleModel::query()->create(['name' => $name, 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $role->givePermissionTo(array_map(fn (Permission $permission): string => $permission->value, $permissions));

    return $role;
};

$holderOf = function (Organisation $organisation, RoleModel $role): User {
    $user = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $user->assignRole($role));
    $user->unsetRelation('roles');

    return $user;
};

// The messages the array mailer delivered (real sync or database queue; never Mail::fake(), RK-I8).
$deliveredMails = function (): array {
    $transport = Mail::driver()->getSymfonyTransport();
    $mails = [];

    if ($transport instanceof ArrayTransport) {
        foreach ($transport->messages() as $sent) {
            $message = $sent instanceof SentMessage ? $sent->getOriginalMessage() : null;

            if ($message instanceof Email) {
                $mails[] = $message;
            }
        }
    }

    return $mails;
};

it('creates a pending user with roles, an invitation and a queued mail (INV-1)', function (): void {
    config(['queue.default' => 'database']);
    // Whole seconds: the datetime columns store no microseconds.
    $this->freezeSecond();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);

    $invitation = inviteMember($organisation, $admin);

    $invitee = User::query()->where('email', 'invitee@example.com')->sole();
    expect($invitee->status)->toBe(UserStatus::PendingActivation)
        ->and($invitee->name)->toBe('invitee@example.com')
        ->and($invitee->locale)->toBe(Locale::default())
        ->and(withPermissionsTeam($organisation->id, fn (): array => $invitee->getRoleNames()->all()))->toBe([Role::OrganisationAdmin->value])
        ->and($invitation->user_id)->toBe($invitee->id)
        ->and($invitation->invited_by)->toBe($admin->id)
        ->and($invitation->organisation_id)->toBe($organisation->id)
        ->and($invitation->expires_at->equalTo(now()->addDays(7)))->toBeTrue()
        ->and($invitation->reminder_sent_at)->toBeNull()
        ->and($invitation->accepted_at)->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and((string) DB::table('jobs')->value('payload'))->toContain('InvitationMail');
});

it('requires at least one role (W14)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);

    expect(fn () => app(InviteUser::class)->handle($admin, $organisation, 'invitee@example.com', [], Locale::English))
        ->toThrow(ValidationException::class, __('Choose at least one role.'));
    expect(User::query()->where('email', 'invitee@example.com')->exists())->toBeFalse();
});

it('sets the organisation explicitly and never creates a super admin, also from the admin host (W8)', function (string $host): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);

    if ($host === 'tenant') {
        $actor = asOrgAdmin($organisation);
    } else {
        $actor = asSuperAdmin();
        onAdminHost();
    }

    $invitation = inviteMember($organisation, $actor);
    $invitee = Tenant::run($organisation, fn (): ?User => User::query()->where('email', 'invitee@example.com')->first());

    expect($invitee?->organisation_id)->toBe($organisation->id)
        ->and($invitee?->is_super_admin)->toBeFalse()
        ->and($invitee?->isSuperAdmin())->toBeFalse()
        ->and($invitation->organisation_id)->toBe($organisation->id)
        ->and($invitation->invited_by)->toBe($actor->id);
})->with(['tenant', 'admin']);

it('never treats a user made without an organisation as a super admin (W8, FU2-11)', function (): void {
    $user = User::factory()->make(['organisation_id' => null]);

    expect($user->isSuperAdmin())->toBeFalse()
        ->and($user->mayExistWithoutOrganisation())->toBeFalse();
});

it('stores the token only as a hash and an encrypted copy, never in plain text (W13, RK-M10)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $invitation = inviteMember($organisation, asOrgAdmin($organisation));
    $token = $invitation->token ?? throw new LogicException('The invitation keeps no token.');
    $stored = DB::table('invitations')->where('id', $invitation->id);

    expect($token)->toHaveLength(64)
        ->and($stored->value('token_hash'))->toBe(hash('sha256', $token))
        ->and($stored->value('token'))->not->toBe($token)
        ->and(Crypt::decryptString((string) $stored->value('token')))->toBe($token)
        ->and(json_encode([DB::table('invitations')->get(), DB::table('users')->get()], JSON_THROW_ON_ERROR))->not->toContain($token);
});

it('sends no mail while the transaction is open and exactly one after commit (W10, RK-I8)', function () use ($deliveredMails): void {
    // Real sync queue and array mailer: Mail::fake() ignores afterCommit (RK-I8).
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);

    DB::transaction(function () use ($organisation, $admin, $deliveredMails): void {
        inviteMember($organisation, $admin);

        expect($deliveredMails())->toBe([]);
    });

    expect($deliveredMails())->toHaveCount(1);
});

it('persists and sends nothing when inviting fails inside the transaction (W10)', function () use ($deliveredMails): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);

    // The pending user is saved before the roles are resolved, so this fails half-way through.
    expect(fn () => app(InviteUser::class)->handle($admin, $organisation, 'invitee@example.com', [999999], Locale::English))
        ->toThrow(ValidationException::class, __('One or more roles are not available for this organisation.'));

    expect(User::withTrashed()->where('email', 'invitee@example.com')->exists())->toBeFalse()
        ->and(Invitation::withTrashed()->count())->toBe(0)
        ->and($deliveredMails())->toBe([]);
});

it('marks the invitation mail afterCommit', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $mail = new InvitationMail(inviteMember($organisation, asOrgAdmin($organisation)));

    expect(class_implements($mail))->toHaveKey(ShouldQueue::class)
        ->and($mail->afterCommit)->toBeTrue();
});

it('keeps the invitation when the mail transport fails in the worker (W10, C-23)', function (): void {
    config([
        'queue.default' => 'database',
        'mail.default' => 'failing',
        'mail.mailers.failing' => ['transport' => 'failing'],
    ]);
    Mail::extend('failing', fn (): TransportInterface => new class implements TransportInterface
    {
        public function __toString(): string
        {
            return 'failing';
        }

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            throw new TransportException('The mail server is down.');
        }
    });
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);
    $invitation = inviteMember($organisation, $admin);

    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true, '--tries' => 1]);
    // The worker resets the scoped CurrentTenant before each job; bind the host's tenant again.
    onTenant($organisation);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(Invitation::query()->whereKey($invitation->id)->exists())->toBeTrue()
        ->and(User::query()->where('email', 'invitee@example.com')->first()?->status)->toBe(UserStatus::PendingActivation)
        ->and(Gate::forUser($admin)->allows('remind', $invitation))->toBeTrue();
});

it('restores the permissions team when inviting fails, on a tenant host and on the admin host (W11)', function (string $host): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);

    if ($host === 'tenant') {
        $actor = asOrgAdmin($organisation);
    } else {
        $actor = asSuperAdmin();
        onAdminHost();
    }

    $teamBefore = PermissionsTeam::current();

    expect(fn () => app(InviteUser::class)->handle($actor, $organisation, 'invitee@example.com', [999999], Locale::English))
        ->toThrow(ValidationException::class);
    expect(PermissionsTeam::current())->toBe($teamBefore)
        ->and(Tenant::current()?->id)->toBe($host === 'tenant' ? $organisation->id : null);
})->with(['tenant', 'admin']);

it('answers the generic message for an address of another organisation or a super admin (W9)', function (string $holder): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);

    if ($holder === 'another organisation') {
        User::factory()->for(Organisation::factory())->create(['email' => 'taken@example.com']);
    } else {
        User::factory()->superAdmin()->create(['email' => 'taken@example.com']);
    }

    $admin = asOrgAdmin($organisation);

    expect(fn (): Invitation => inviteMember($organisation, $admin, 'taken@example.com'))
        ->toThrow(ValidationException::class, __("This address can't be used."));
})->with(['another organisation', 'a super admin']);

it('names an open invitation in the own organisation (OR-I3)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);
    inviteMember($organisation, $admin);

    expect(fn (): Invitation => inviteMember($organisation, $admin))
        ->toThrow(ValidationException::class, __('This address already has an open invitation.'));
});

it('stores the address in lower case (X2)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);

    $invitation = inviteMember($organisation, $admin, '  Invitee@Example.COM ');

    expect($invitation->user->email)->toBe('invitee@example.com')
        ->and(recordedActivity(ActivityEvent::InvitationSent)->sole()->properties?->get('email'))->toBe('invitee@example.com');
});

it('needs users.create and roles.assign and only hands out roles the inviter holds (X5, OR-M7)', function () use ($teamRoleWith, $holderOf, $templateRoleId): void {
    $organisation = onTenant(Organisation::factory()->create(['slug' => 'acme']));
    $creatorOnly = $holderOf($organisation, $teamRoleWith($organisation, 'creator', Permission::UsersCreate));
    $recruiterRole = $teamRoleWith($organisation, 'recruiter', Permission::UsersView, Permission::UsersCreate, Permission::RolesAssign);
    $recruiter = $holderOf($organisation, $recruiterRole);

    expect(fn () => app(InviteUser::class)->handle($creatorOnly, $organisation, 'first@example.com', [(int) $recruiterRole->id], Locale::English))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(InviteUser::class)->handle($recruiter, $organisation, 'second@example.com', [$templateRoleId()], Locale::English))
        ->toThrow(ValidationException::class, __('You can only give or take away roles whose permissions you have yourself.'));

    $invitation = app(InviteUser::class)->handle($recruiter, $organisation, 'third@example.com', [(int) $recruiterRole->id], Locale::English);

    expect(withPermissionsTeam($organisation->id, fn (): array => $invitation->user->getRoleNames()->all()))->toBe(['recruiter'])
        ->and(User::query()->whereIn('email', ['first@example.com', 'second@example.com'])->exists())->toBeFalse();
});

it('refuses inviting into another organisation, except for super admins', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    $admin = asOrgAdmin($acme);

    expect(fn (): Invitation => inviteMember($beta, $admin))->toThrow(AuthorizationException::class);

    $superAdmin = asSuperAdmin();
    onAdminHost();

    expect(inviteMember($beta, $superAdmin)->organisation_id)->toBe($beta->id);
});

it('refuses an archived organisation and another organisation from a tenant host (RK-I11)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    $superAdmin = asSuperAdmin();
    onTenant($acme);

    expect(fn (): Invitation => inviteMember($beta, $superAdmin))->toThrow(AuthorizationException::class);

    $beta->delete();
    onAdminHost();

    expect(fn (): Invitation => inviteMember($beta, $superAdmin))
        ->toThrow(ValidationException::class, __('This organisation is archived.'));
    expect(DB::table('invitations')->count())->toBe(0)
        ->and(DB::table('users')->where('email', 'invitee@example.com')->exists())->toBeFalse();
});

it('records exactly user.roles_changed and invitation.sent, with no token or link in the properties (OR-M10, RK-M11)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);

    $invitation = inviteMember($organisation, $admin);
    $token = $invitation->token ?? throw new LogicException('The invitation keeps no token.');
    $sent = recordedActivity(ActivityEvent::InvitationSent)->sole();

    expect(Activity::query()->orderBy('id')->pluck('event')->all())
        ->toBe([ActivityEvent::UserRolesChanged->value, ActivityEvent::InvitationSent->value])
        ->and($sent->subject_id)->toBe($invitation->user_id)
        ->and($sent->causer_id)->toBe($admin->id)
        ->and($sent->organisation_id)->toBe($organisation->id)
        ->and($sent->properties?->keys()->sort()->values()->all())->toBe(['email', 'invitation_id', 'roles'])
        ->and($sent->properties?->get('roles'))->toBe([Role::OrganisationAdmin->value]);

    $rows = json_encode(DB::table('activity_log')->get(), JSON_THROW_ON_ERROR);

    expect($rows)->not->toContain($token)
        ->not->toContain($invitation->token_hash)
        ->not->toContain('signature')
        ->not->toContain('invitations\/accept')
        ->not->toContain('invitations/accept');
});

it('refuses to mass-assign organisation_id on invitations (CV-15)', function (): void {
    expect(fn (): Invitation => new Invitation(['organisation_id' => Organisation::factory()->create()->id]))
        ->toThrow(MassAssignmentException::class);
});
