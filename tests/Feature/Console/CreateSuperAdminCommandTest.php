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
});

it('promotes an existing user through SetSuperAdmin (RL-9)', function (): void {
    $member = User::factory()->for(Organisation::factory()->create(['slug' => 'acme']))->create(['email' => 'member@example.com']);

    expect(Artisan::call('skv:create-super-admin', ['email' => 'member@example.com', '--no-interaction' => true]))->toBe(0);

    expect($member->fresh()?->isSuperAdmin())->toBeTrue()
        ->and(recordedActivity(ActivityEvent::SuperAdminGranted))->toHaveCount(1);
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
