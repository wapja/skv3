<?php

declare(strict_types=1);

/** covers §4.12 */

use App\Enums\ActivityEvent;
use App\Enums\Locale;
use App\Enums\UserStatus;
use App\Models\Organisation;
use App\Models\User;
use App\Setup\Support\Toolchain;
use App\Tenancy\Facades\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
    config(['queue.default' => 'sync', 'mail.default' => 'array']);
});

it('creates an organisation and invites its first admin through CreateOrganisation (DEC-28)', function (): void {
    asSuperAdmin();

    expect(Artisan::call('skv:create-organisation', ['name' => 'Acme', 'slug' => 'acme', 'admin-email' => 'boss@example.com', '--no-interaction' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('boss@example.com')
        ->and(Organisation::query()->where('slug', 'acme')->exists())->toBeTrue()
        ->and(recordedActivity(ActivityEvent::OrganisationCreated))->toHaveCount(1)
        ->and(recordedActivity(ActivityEvent::InvitationSent))->toHaveCount(1);
});

it('asks for a super admin first (RL-9)', function (): void {
    expect(Artisan::call('skv:create-organisation', ['name' => 'Acme', 'slug' => 'acme', 'admin-email' => 'boss@example.com', '--no-interaction' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('skv:create-super-admin');
});

it('reports validation errors without a stack trace', function (): void {
    asSuperAdmin();

    expect(Artisan::call('skv:create-organisation', ['name' => 'Acme', 'slug' => 'admin', 'admin-email' => 'boss@example.com', '--no-interaction' => true]))->toBe(1)
        ->and(Artisan::output())->toContain(__('This address is reserved.'))->not->toContain('ValidationException')
        ->and(Organisation::query()->count())->toBe(0);
});

it('acts as the super admin --as names, and refuses another address without promoting it (CD-7)', function (): void {
    User::factory()->superAdmin()->create(['email' => 'first@example.com']);
    $chosen = User::factory()->superAdmin()->create(['email' => 'second@example.com']);
    $member = User::factory()->create(['email' => 'member@example.com']);

    $refused = Artisan::call('skv:create-organisation', ['name' => 'Beta', 'slug' => 'beta', 'admin-email' => 'b@example.com', '--as' => 'member@example.com', '--no-interaction' => true]);
    $refusedOutput = Artisan::output();
    $exit = Artisan::call('skv:create-organisation', ['name' => 'Acme', 'slug' => 'acme', 'admin-email' => 'boss@example.com', '--as' => 'Second@Example.com', '--no-interaction' => true]);

    expect($refused)->toBe(1)
        ->and($refusedOutput)->toContain('member@example.com')
        ->and(Organisation::query()->where('slug', 'beta')->exists())->toBeFalse()
        ->and($exit)->toBe(0)
        ->and(recordedActivity(ActivityEvent::OrganisationCreated)->sole()->causer_id)->toBe($chosen->id)
        // L-46: the refused --as user is still a plain member.
        ->and(Tenant::bypass(fn (): ?bool => $member->fresh()?->is_super_admin))->toBeFalse();
});

it('never acts as a switched-off super admin (CD-7)', function (): void {
    User::factory()->superAdmin()->create(['email' => 'off@example.com', 'status' => UserStatus::Disabled]);
    User::factory()->superAdmin()->create(['email' => 'waiting@example.com', 'status' => UserStatus::PendingActivation]);
    $active = User::factory()->superAdmin()->create(['email' => 'on@example.com']);

    $refused = Artisan::call('skv:create-organisation', ['name' => 'Beta', 'slug' => 'beta', 'admin-email' => 'b@example.com', '--as' => 'off@example.com', '--no-interaction' => true]);
    $refusedOutput = Artisan::output();
    $pending = Artisan::call('skv:create-organisation', ['name' => 'Gamma', 'slug' => 'gamma', 'admin-email' => 'g@example.com', '--as' => 'waiting@example.com', '--no-interaction' => true]);
    $exit = Artisan::call('skv:create-organisation', ['name' => 'Acme', 'slug' => 'acme', 'admin-email' => 'boss@example.com', '--no-interaction' => true]);

    expect($refused)->toBe(1)
        ->and($refusedOutput)->toContain(__('No active super admin has the address :email.', ['email' => 'off@example.com']))
        ->and($pending)->toBe(1)
        ->and(Organisation::query()->whereIn('slug', ['beta', 'gamma'])->exists())->toBeFalse()
        ->and(recordedActivity(ActivityEvent::InvitationSent))->toHaveCount(1)
        ->and($exit)->toBe(0)
        ->and(recordedActivity(ActivityEvent::OrganisationCreated)->sole()->causer_id)->toBe($active->id);
});

it('invites the first admin in the app\'s default language unless --locale says otherwise (A64)', function (): void {
    config(['skv.locale.default' => 'en']);
    User::factory()->superAdmin()->create();

    Artisan::call('skv:create-organisation', ['name' => 'Acme', 'slug' => 'acme', 'admin-email' => 'boss@example.com', '--no-interaction' => true]);
    Artisan::call('skv:create-organisation', ['name' => 'Beta', 'slug' => 'beta', 'admin-email' => 'beta@example.com', '--locale' => 'nl', '--no-interaction' => true]);

    $locale = fn (string $email): ?Locale => Tenant::bypass(fn (): ?Locale => User::query()->where('email', $email)->first()?->locale);

    expect($locale('boss@example.com'))->toBe(Locale::English)
        ->and($locale('beta@example.com'))->toBe(Locale::Dutch);
});
