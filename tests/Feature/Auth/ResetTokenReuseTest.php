<?php

declare(strict_types=1);

/** covers §4.2, §4.4, §4.5 */

use App\Actions\Invitations\CancelInvitation;
use App\Actions\Organisations\ArchiveOrganisation;
use App\Actions\Settings\RequestEmailChange;
use App\Actions\Users\DeleteUser;
use App\Actions\Users\UpdateUser;
use App\Authorization\SetSuperAdmin;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/** A super admin who is not signed in: password.update is a guest route. */
$superAdmin = function (): User {
    $user = User::factory()->superAdmin()->create();
    app(SetSuperAdmin::class)->handle($user, true);

    return $user;
};

$hasResetToken = fn (string $email): bool => DB::table('password_reset_tokens')->where('email', $email)->exists();

it('refuses a deleted user\'s reset link once their address belongs to another account', function () use ($superAdmin): void {
    // Fails before the fix: reset tokens are keyed by address only, so the leaver's link set the successor's password.
    Notification::fake();
    $organisation = Organisation::factory()->create();
    $leaver = User::factory()->for($organisation)->create(['email' => 'desk@example.com']);
    $successor = User::factory()->for($organisation)->create();
    $token = Password::broker()->createToken($leaver);
    $actor = $superAdmin();
    onTenant($organisation);

    app(DeleteUser::class)->handle($actor, $leaver);
    app(UpdateUser::class)->handle($actor, $successor, $successor->name, 'desk@example.com');

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => 'desk@example.com',
        'password' => 'new-password-1',
        'password_confirmation' => 'new-password-1',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('password', (string) $successor->fresh()?->password))->toBeTrue();
});

it('forgets the reset links of a user who is deleted, archived with the organisation or whose invitation is cancelled', function (string $removal) use ($superAdmin, $hasResetToken): void {
    $organisation = Organisation::factory()->create();
    $bystander = User::factory()->for(Organisation::factory())->create();
    $actor = $superAdmin();
    onTenant($organisation);
    $invitation = $removal === 'cancelled invitation' ? inviteMember($organisation, $actor, 'leaver@example.com') : null;
    $leaver = $invitation instanceof Invitation ? $invitation->user : User::factory()->for($organisation)->create(['email' => 'leaver@example.com']);
    Password::broker()->createToken($leaver);
    Password::broker()->createToken($bystander);

    if ($invitation instanceof Invitation) {
        app(CancelInvitation::class)->handle($actor, $invitation);
    } elseif ($removal === 'archived organisation') {
        app(ArchiveOrganisation::class)->handle($actor, $organisation);
    } else {
        app(DeleteUser::class)->handle($actor, $leaver);
    }

    expect($hasResetToken('leaver@example.com'))->toBeFalse()
        ->and($hasResetToken($bystander->email))->toBeTrue();
})->with(['deleted user', 'archived organisation', 'cancelled invitation']);

it('forgets reset links issued for an address before it is given to a user', function (string $assignment) use ($superAdmin, $hasResetToken): void {
    Notification::fake();
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $actor = $superAdmin();
    // Left behind by an earlier holder of the address, who no longer exists.
    DB::table('password_reset_tokens')->insert(['email' => 'desk@example.com', 'token' => Hash::make('earlier-holder'), 'created_at' => now()]);
    onTenant($organisation);

    if ($assignment === 'admin edit') {
        app(UpdateUser::class)->handle($actor, $member, $member->name, 'desk@example.com');
    } elseif ($assignment === 'email change') {
        app(RequestEmailChange::class)->handle($member, 'desk@example.com');
        $this->actingAs($member)->withSession(['auth.password_confirmed_at' => time()])
            ->get(confirmationLinkFor('desk@example.com'))
            ->assertRedirect(route('profile.edit'));
    } else {
        inviteMember($organisation, $actor, 'desk@example.com');
    }

    expect(DB::table('users')->where('email', 'desk@example.com')->whereNull('deleted_at')->exists())->toBeTrue()
        ->and($hasResetToken('desk@example.com'))->toBeFalse();
})->with(['admin edit', 'email change', 'invitation']);
