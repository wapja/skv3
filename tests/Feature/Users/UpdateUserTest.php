<?php

declare(strict_types=1);

/** covers §4.4 */

use App\Actions\Settings\ConfirmEmailChange;
use App\Actions\Settings\RequestEmailChange;
use App\Actions\Users\UpdateUser;
use App\Authorization\Permission;
use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Enums\Locale;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\EmailChangedByAdministrator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as RoleModel;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

/**
 * The validation errors an action throws, or [] when it succeeds.
 *
 * @return array<string, array<int, string>>
 */
$errorsOf = function (Closure $action): array {
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
};

it('lets an organisation admin rename a member and change their address (USR-12)', function (): void {
    Notification::fake();
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create(['email' => 'old@example.com']);
    $admin = asOrgAdmin($organisation);

    app(UpdateUser::class)->handle($admin, $member, '  New Name ', 'New@Example.com');

    expect($member->refresh()->name)->toBe('New Name')
        ->and($member->email)->toBe('new@example.com');
});

it('accepts an address that only a deleted user holds (W9)', function (): void {
    Notification::fake();
    $organisation = Organisation::factory()->create();
    User::factory()->for($organisation)->create(['email' => 'former@example.com'])->delete();
    User::factory()->create(['email' => 'elsewhere-former@example.com'])->delete();
    $member = User::factory()->for($organisation)->create();
    $admin = asOrgAdmin($organisation);

    app(UpdateUser::class)->handle($admin, $member, $member->name, 'former@example.com');
    expect($member->refresh()->email)->toBe('former@example.com');

    app(UpdateUser::class)->handle($admin, $member, $member->name, 'elsewhere-former@example.com');
    expect($member->refresh()->email)->toBe('elsewhere-former@example.com');
});

it('answers a generic message for an address used in another organisation or by a super admin (W9)', function (string $holder) use ($errorsOf): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create(['email' => 'member@example.com']);
    match ($holder) {
        'super admin' => User::factory()->superAdmin()->create(['email' => 'taken@example.com']),
        // SetSuperAdmin keeps the organisation, so only the super admin flag hides this holder.
        'promoted super admin' => app(SetSuperAdmin::class)->handle(User::factory()->for($organisation)->create(['email' => 'taken@example.com']), true),
        default => User::factory()->create(['email' => 'taken@example.com']),
    };

    $admin = asOrgAdmin($organisation);

    expect($errorsOf(fn () => app(UpdateUser::class)->handle($admin, $member, $member->name, 'taken@example.com')))
        ->toBe(['email' => [__('This address can\'t be used.')]])
        ->and($member->refresh()->email)->toBe('member@example.com');
})->with(['another organisation', 'super admin', 'promoted super admin']);

it('names the conflict for an address used in the own organisation', function () use ($errorsOf): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    User::factory()->for($organisation)->create(['email' => 'colleague@example.com']);
    $admin = asOrgAdmin($organisation);

    expect($errorsOf(fn () => app(UpdateUser::class)->handle($admin, $member, $member->name, 'colleague@example.com')))
        ->toBe(['email' => [__('This address is already used in this organisation.')]]);
});

it('names an open invitation for a pending holder in the own organisation (OR-I3)', function () use ($errorsOf): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    User::factory()->for($organisation)->pending()->create(['email' => 'invited@example.com']);
    $admin = asOrgAdmin($organisation);

    expect($errorsOf(fn () => app(UpdateUser::class)->handle($admin, $member, $member->name, 'invited@example.com')))
        ->toBe(['email' => [__('This address already has an open invitation.')]]);
});

it('stores addresses in lower case and refuses a case variant through validation, not a database error (W9, X2)', function () use ($errorsOf): void {
    Notification::fake();
    $organisation = Organisation::factory()->create();
    $first = User::factory()->for($organisation)->create();
    $second = User::factory()->for($organisation)->create();
    $admin = asOrgAdmin($organisation);

    app(UpdateUser::class)->handle($admin, $first, $first->name, 'Mixed.Case@Example.COM');

    expect($first->refresh()->email)->toBe('mixed.case@example.com')
        ->and($errorsOf(fn () => app(UpdateUser::class)->handle($admin, $second, $second->name, 'MIXED.case@example.com')))
        ->toBe(['email' => [__('This address is already used in this organisation.')]]);
});

it('refuses to edit a user of another organisation or a super admin (DEC-26 (12))', function (): void {
    $organisation = Organisation::factory()->create();
    $outsider = User::factory()->create();
    $superAdmin = User::factory()->superAdmin()->create();
    $admin = asOrgAdmin($organisation);

    expect(fn () => app(UpdateUser::class)->handle($admin, $outsider, 'Renamed', $outsider->email))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateUser::class)->handle($admin, $superAdmin, 'Renamed', $superAdmin->email))->toThrow(AuthorizationException::class);
});

it('refuses a custom role changing an organisation admin\'s address (RL-26)', function (): void {
    $organisation = Organisation::factory()->create();
    $target = User::factory()->for($organisation)->create(['email' => 'admin@example.com']);
    withPermissionsTeam($organisation->id, fn (): User => $target->assignRole(Role::OrganisationAdmin->value));
    $helpdesk = RoleModel::query()->create(['name' => 'helpdesk', 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $helpdesk->givePermissionTo([Permission::UsersView->value, Permission::UsersUpdate->value]);
    $actor = asMember($organisation);
    withPermissionsTeam($organisation->id, fn (): User => $actor->assignRole($helpdesk));
    $actor->unsetRelation('roles');

    expect(fn () => app(UpdateUser::class)->handle($actor, $target, $target->name, 'attacker@example.com'))
        ->toThrow(AuthorizationException::class);
    expect($target->refresh()->email)->toBe('admin@example.com');
});

it('refuses changing your own address here (E1, DEC-27 (7))', function () use ($errorsOf): void {
    $organisation = Organisation::factory()->create();
    $admin = asOrgAdmin($organisation);
    $address = $admin->email;

    expect($errorsOf(fn () => app(UpdateUser::class)->handle($admin, $admin, $admin->name, 'mine@example.com')))
        ->toBe(['email' => [__('Change your own address on your profile page.')]]);

    app(UpdateUser::class)->handle($admin, $admin, 'Renamed Admin', $address);

    expect($admin->refresh()->name)->toBe('Renamed Admin')
        ->and($admin->email)->toBe($address);
});

it('refuses changing a pending user\'s address (RK-B2)', function () use ($errorsOf): void {
    $organisation = Organisation::factory()->create();
    $pending = User::factory()->for($organisation)->pending()->create(['email' => 'invited@example.com']);
    $admin = asOrgAdmin($organisation);

    expect($errorsOf(fn () => app(UpdateUser::class)->handle($admin, $pending, $pending->name, 'other@example.com')))
        ->toBe(['email' => [__('Cancel the invitation and invite the new address.')]]);

    app(UpdateUser::class)->handle($admin, $pending, 'Invited Person', 'invited@example.com');

    expect($pending->refresh()->name)->toBe('Invited Person')
        ->and($pending->email)->toBe('invited@example.com');
});

it('notifies the old address after an administrator changed it (D-14)', function (): void {
    Notification::fake();
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create(['email' => 'old@example.com']);
    $admin = asOrgAdmin($organisation);

    app(UpdateUser::class)->handle($admin, $member, 'Only Renamed', 'old@example.com');
    Notification::assertNothingSent();

    app(UpdateUser::class)->handle($admin, $member, $member->name, 'new@example.com');

    Notification::assertSentOnDemand(
        EmailChangedByAdministrator::class,
        fn (EmailChangedByAdministrator $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notification->newEmail === 'new@example.com'
            && ($notifiable->routes['mail'] ?? null) === 'old@example.com',
    );
    expect(new EmailChangedByAdministrator('new@example.com')->toMail(new AnonymousNotifiable)->introLines)
        ->toContain(__('An administrator changed the email address of your account to :email.', ['email' => 'new@example.com']));
});

it('writes the notice in the target\'s language, not the administrator\'s (G3-03)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create(['email' => 'old@example.com', 'locale' => Locale::English]);
    $admin = asOrgAdmin($organisation);
    $admin->forceFill(['locale' => Locale::Dutch])->save();
    // The Dutch administrator's request answers in Dutch.
    app()->setLocale('nl');

    app(UpdateUser::class)->handle($admin, $member, $member->name, 'new@example.com');

    $transport = Mail::driver()->getSymfonyTransport();
    $sent = $transport instanceof ArrayTransport ? $transport->messages()->first() : null;
    $mail = $sent instanceof SentMessage ? $sent->getOriginalMessage() : null;

    expect($mail)->toBeInstanceOf(Email::class)
        ->and($mail instanceof Email ? $mail->getSubject() : null)->toBe('An administrator changed your email address');
});

it('invalidates a pending self-service email change and the old address\'s reset tokens (FU3-7)', function (): void {
    Notification::fake();
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create(['email' => 'old@example.com']);
    DB::table('password_reset_tokens')->insert(['email' => 'old@example.com', 'token' => 'x', 'created_at' => now()]);
    app(RequestEmailChange::class)->handle($member, 'self-chosen@example.com');
    $link = confirmationLinkFor('self-chosen@example.com');
    $admin = asOrgAdmin($organisation);

    app(UpdateUser::class)->handle($admin, $member, $member->name, 'admin-chosen@example.com');

    expect(Cache::has(RequestEmailChange::cacheKey($member)))->toBeFalse()
        ->and(DB::table('password_reset_tokens')->where('email', 'old@example.com')->exists())->toBeFalse();

    $this->actingAs($member->refresh())->withSession(['auth.password_confirmed_at' => time()])->get($link)
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', __('This confirmation link is no longer valid.'));

    expect($member->refresh()->email)->toBe('admin-chosen@example.com');
});

it('turns a unique-index race into a validation error, also when confirming an email change (FU3-8)', function () use ($errorsOf): void {
    Notification::fake();
    $organisation = Organisation::factory()->create();
    $elsewhere = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create(['email' => 'old@example.com']);
    $admin = asOrgAdmin($organisation);
    // Another request takes the new address after validation passed, just before the update runs.
    User::updating(function (User $user) use ($elsewhere): void {
        if ($user->isDirty('email')) {
            DB::table('users')->insert([
                'organisation_id' => $elsewhere->id,
                'name' => 'Racer',
                'email' => $user->email,
                'password' => 'x',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    expect($errorsOf(fn () => app(UpdateUser::class)->handle($admin, $member, $member->name, 'raced@example.com')))
        ->toBe(['email' => [__('This address can\'t be used.')]])
        ->and($member->refresh()->email)->toBe('old@example.com');

    app(RequestEmailChange::class)->handle($member, 'confirmed@example.com');
    $nonce = Str::between(confirmationLinkFor('confirmed@example.com'), 'nonce=', '&');

    expect($errorsOf(fn () => app(ConfirmEmailChange::class)->handle($member, 'confirmed@example.com', $nonce)))
        ->toBe(['email' => [__('This address can\'t be used.')]])
        ->and($member->refresh()->email)->toBe('old@example.com');
});
