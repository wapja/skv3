<?php

declare(strict_types=1);

/** covers §4.12 */

use App\Authorization\Role;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Setup\Support\Toolchain;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('creates an active super admin without an organisation (§4.12, W8)', function (): void {
    expect(Artisan::call('skv:create-super-admin', ['email' => 'owner@example.com', '--name' => 'Owner', '--password' => 'correct-horse-battery', '--no-interaction' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('owner@example.com')->not->toContain('correct-horse-battery');

    $user = app(CurrentTenant::class)->bypass(fn (): User => User::query()->where('email', 'owner@example.com')->firstOrFail());

    expect($user->isSuperAdmin())->toBeTrue()
        ->and($user->organisation_id)->toBeNull()
        ->and(Hash::check('correct-horse-battery', $user->password))->toBeTrue()
        // A new user is inserted with the flag (BelongsToOrganisation's creating hook); SetSuperAdmin attaches the badge.
        ->and(withPermissionsTeam(null, fn (): bool => $user->hasRole(Role::SuperAdmin->value)))->toBeTrue();

    // The flag never changes for a new user, yet creating the platform's most privileged account is audited (§4.8).
    $granted = recordedActivity(ActivityEvent::SuperAdminGranted)->sole();
    expect([$granted->subject_id, $granted->causer_id])->toBe([$user->id, null]);
});

it('promotes an existing user through SetSuperAdmin (RL-9)', function (): void {
    $member = User::factory()->for(Organisation::factory()->create(['slug' => 'acme']))->create(['email' => 'member@example.com']);

    expect(Artisan::call('skv:create-super-admin', ['email' => 'member@example.com', '--no-interaction' => true]))->toBe(0);

    expect($member->fresh()?->isSuperAdmin())->toBeTrue()
        ->and(recordedActivity(ActivityEvent::SuperAdminGranted))->toHaveCount(1);
});

it('applies a given name and password to an existing user it promotes (RL-9)', function (): void {
    $member = User::factory()->for(Organisation::factory()->create(['slug' => 'acme']))->create(['email' => 'member@example.com', 'name' => 'Old Name', 'password' => 'old-password-123']);
    $rememberToken = $member->remember_token;

    expect(Artisan::call('skv:create-super-admin', ['email' => 'member@example.com', '--name' => 'New Name', '--password' => 'correct-horse-battery', '--no-interaction' => true]))->toBe(0)
        ->and(Artisan::output())->not->toContain('correct-horse-battery');

    $member = $member->fresh();

    expect($member?->isSuperAdmin())->toBeTrue()
        ->and($member?->name)->toBe('New Name')
        ->and(Hash::check('correct-horse-battery', (string) $member?->password))->toBeTrue()
        ->and($member?->remember_token)->not->toBe($rememberToken)
        ->and(recordedActivity(ActivityEvent::PasswordChanged))->toHaveCount(1)
        ->and(recordedActivity(ActivityEvent::UserUpdated))->toHaveCount(1);
});

it('keeps an existing user\'s password when the new one is too weak (RL-9)', function (): void {
    $member = User::factory()->for(Organisation::factory()->create(['slug' => 'acme']))->create(['email' => 'member@example.com', 'password' => 'old-password-123']);

    expect(Artisan::call('skv:create-super-admin', ['email' => 'member@example.com', '--password' => 'short', '--no-interaction' => true]))->toBe(1);

    expect($member->fresh()?->isSuperAdmin())->toBeFalse()
        ->and(Hash::check('old-password-123', (string) $member->fresh()?->password))->toBeTrue();
});

it('refuses a user who is not active (RL-9)', function (): void {
    $pending = User::factory()->for(Organisation::factory()->create(['slug' => 'acme']))->pending()->create(['email' => 'invited@example.com']);

    expect(Artisan::call('skv:create-super-admin', ['email' => 'invited@example.com', '--no-interaction' => true]))->toBe(1);

    expect($pending->fresh()?->isSuperAdmin())->toBeFalse();
});

it('needs an email and a password without interaction', function (): void {
    expect(Artisan::call('skv:create-super-admin', ['--no-interaction' => true]))->toBe(1)
        ->and(Artisan::call('skv:create-super-admin', ['email' => 'new@example.com', '--no-interaction' => true]))->toBe(1)
        ->and(app(CurrentTenant::class)->bypass(fn (): bool => User::query()->where('email', 'new@example.com')->exists()))->toBeFalse();
});
