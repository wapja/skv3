<?php

declare(strict_types=1);

/** covers §4.4, §4.5 */

use App\Actions\Invitations\CancelInvitation;
use App\Actions\Users\RestoreUser;
use App\Enums\ActivityEvent;
use App\Enums\UserStatus;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

it('deletes the invitation, archives the pending user and records it (INV-5)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);
    $invitation = inviteMember($organisation, $admin);

    app(CancelInvitation::class)->handle($admin, $invitation);

    $invitee = User::withTrashed()->find($invitation->user_id);
    $cancelled = recordedActivity(ActivityEvent::InvitationCancelled)->sole();

    expect(Invitation::withTrashed()->whereKey($invitation->id)->exists())->toBeFalse()
        ->and($invitee?->trashed())->toBeTrue()
        ->and($invitee?->status)->toBe(UserStatus::PendingActivation)
        ->and($cancelled->subject_id)->toBe($invitation->user_id)
        ->and($cancelled->causer_id)->toBe($admin->id)
        ->and($cancelled->properties?->get('email'))->toBe('invitee@example.com');
});

it('refuses to cancel an accepted invitation', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);
    $invitation = inviteMember($organisation, $admin);
    $invitation->forceFill(['accepted_at' => now()])->save();

    expect(fn () => app(CancelInvitation::class)->handle($admin, $invitation))
        ->toThrow(ValidationException::class, __('This invitation has already been accepted.'));
    expect(Invitation::query()->whereKey($invitation->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($invitation->user_id)->exists())->toBeTrue();
});

it('lets the same address be invited again after cancelling (INV-14)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);
    app(CancelInvitation::class)->handle($admin, inviteMember($organisation, $admin));

    $again = inviteMember($organisation, $admin);

    expect($again->user->email)->toBe('invitee@example.com')
        ->and(User::withTrashed()->where('email', 'invitee@example.com')->count())->toBe(2);
});

it('keeps a cancelled invitee out of restore (C-9, OR-M4)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);
    $invitation = inviteMember($organisation, $admin);
    app(CancelInvitation::class)->handle($admin, $invitation);
    $invitee = User::withTrashed()->findOrFail($invitation->user_id);

    expect(fn () => app(RestoreUser::class)->handle($admin, $invitee))->toThrow(ValidationException::class);
    expect(User::withTrashed()->findOrFail($invitation->user_id)->trashed())->toBeTrue();
});

it('refuses another organisation\'s admin and lets a super admin (INV-8)', function (): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    $invitation = inviteMember($acme);
    $betaAdmin = asOrgAdmin($beta);

    expect(fn () => app(CancelInvitation::class)->handle($betaAdmin, $invitation))->toThrow(AuthorizationException::class);

    $superAdmin = asSuperAdmin();
    onAdminHost();
    app(CancelInvitation::class)->handle($superAdmin, $invitation);

    expect(Tenant::run($acme, fn (): bool => Invitation::withTrashed()->whereKey($invitation->id)->exists()))->toBeFalse();
});
