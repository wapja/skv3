<?php

declare(strict_types=1);

/** covers §4.12 */

use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Setup\Support\Toolchain;
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
