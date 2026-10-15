<?php

declare(strict_types=1);

/** covers §4.12 */

use App\Enums\ActivityEvent;
use App\Enums\Locale;
use App\Enums\UserStatus;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Facades\Tenant;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

it('seeds a super admin and demo1 and demo2, each with an admin, a user and a pending invitation (§4.12, DEC-28)', function (): void {
    $this->seed(DemoSeeder::class);

    $apex = config('tenancy.apex_domain');
    // Users are a tenant model (strict mode): read across organisations through the bypass.
    $superAdmin = app(CurrentTenant::class)->bypass(fn (): User => User::query()->where('email', "superadmin@{$apex}")->firstOrFail());

    expect($superAdmin->isSuperAdmin())->toBeTrue()
        ->and($superAdmin->organisation_id)->toBeNull()
        ->and(Hash::check(DemoSeeder::PASSWORD, $superAdmin->password))->toBeTrue();

    foreach (DemoSeeder::SLUGS as $slug) {
        $organisation = Organisation::query()->where('slug', $slug)->firstOrFail();

        Tenant::run($organisation, function () use ($organisation, $slug, $apex): void {
            $admin = User::query()->where('email', "admin@{$slug}.{$apex}")->firstOrFail();
            $member = User::query()->where('email', "user@{$slug}.{$apex}")->firstOrFail();

            expect($admin->status)->toBe(UserStatus::Active)
                ->and($admin->organisation_id)->toBe($organisation->id)
                ->and(Hash::check(DemoSeeder::PASSWORD, $admin->password))->toBeTrue()
                ->and($member->status)->toBe(UserStatus::Active)
                ->and(Invitation::query()->whereNull('accepted_at')->count())->toBe(1);
        });
    }

    expect(recordedActivity(ActivityEvent::OrganisationCreated))->toHaveCount(2)
        ->and(recordedActivity(ActivityEvent::InvitationAccepted))->toHaveCount(4)
        ->and(recordedActivity(ActivityEvent::SuperAdminGranted)->sole()->subject_id)->toBe($superAdmin->id);
});

it('is idempotent (§4.12)', function (): void {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(Organisation::query()->whereIn('slug', DemoSeeder::SLUGS)->count())->toBe(2)
        ->and(app(CurrentTenant::class)->bypass(fn (): int => User::query()->where('is_super_admin', true)->count()))->toBe(1)
        ->and(recordedActivity(ActivityEvent::SuperAdminGranted))->toHaveCount(1);
});

it('refuses to run in production (§4.12)', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    // --force: without it db:seed asks to confirm in production and cancels before the seeder runs.
    $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
})->throws(RuntimeException::class, 'never runs in production');

it('gives the demo user the Member role and sends the invitation mails right away (RL-8)', function (): void {
    config(['queue.default' => 'database']);

    $this->seed(DemoSeeder::class);

    $organisation = Organisation::query()->where('slug', 'demo1')->firstOrFail();
    $apex = config('tenancy.apex_domain');

    Tenant::run($organisation, function () use ($organisation, $apex): void {
        $member = User::query()->where('email', "user@demo1.{$apex}")->firstOrFail();

        expect(withPermissionsTeam($organisation->id, fn (): array => $member->getRoleNames()->all()))->toContain('Member');
    });

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(config('queue.default'))->toBe('database');
});

it('lists the demo accounts for the setup summary', function (): void {
    $apex = config('tenancy.apex_domain');

    expect(array_column(DemoSeeder::accounts(), 'email'))->toBe([
        "superadmin@{$apex}",
        "admin@demo1.{$apex}", "user@demo1.{$apex}",
        "admin@demo2.{$apex}", "user@demo2.{$apex}",
    ]);
});

it('replaces the temporary development login (DEC-26)', function (): void {
    $this->seed();

    expect(app(CurrentTenant::class)->bypass(fn (): bool => User::query()->where('email', 'test@example.com')->exists()))->toBeFalse()
        ->and(Organisation::query()->whereIn('slug', DemoSeeder::SLUGS)->count())->toBe(2);
});

it('runs the demo actions with their model events through the database seeder (DEC-28)', function (): void {
    $created = 0;
    Event::listen('eloquent.created: '.Organisation::class, function () use (&$created): void {
        $created++;
    });

    $this->seed();

    expect($created)->toBe(2);
});

it('creates the demo accounts in the app\'s default language (A64)', function (): void {
    config(['skv.locale.default' => 'en']);

    $this->seed(DemoSeeder::class);

    $apex = config('tenancy.apex_domain');
    $locale = fn (string $email): ?Locale => Tenant::bypass(fn (): ?Locale => User::query()->where('email', $email)->first()?->locale);

    expect($locale("admin@demo1.{$apex}"))->toBe(Locale::English)
        ->and($locale("user@demo1.{$apex}"))->toBe(Locale::English);
});
