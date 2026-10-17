<?php

declare(strict_types=1);

/** covers §6.5 */

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Support\Dashboard\LoginTrend;
use App\Support\Skv;
use App\Tenancy\Facades\Tenant;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

afterEach(fn () => Skv::useFluxPro(null));

it('counts this host\'s sign-ins per day for the last 14 days, oldest first, zeros included (RL-15)', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $other = Organisation::factory()->create(['slug' => 'other']);
    $admin = asOrgAdmin($acme);

    app(RecordActivity::class)->handle(ActivityEvent::Login, $admin);
    app(RecordActivity::class)->handle(ActivityEvent::Login, $admin);
    $this->travelTo(now()->subDays(3));
    app(RecordActivity::class)->handle(ActivityEvent::Login, $admin);
    $this->travelBack();
    $this->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));
    Tenant::run($other, fn () => app(RecordActivity::class)->handle(ActivityEvent::Login, $admin));
    // The window's edges: the first minute of its first day counts, the last minute before it does not (A84r).
    $this->travelTo(now()->setDate(2026, 9, 29)->setTime(0, 1));
    app(RecordActivity::class)->handle(ActivityEvent::Login, $admin);
    $this->travelTo(now()->setDate(2026, 9, 28)->setTime(23, 59));
    app(RecordActivity::class)->handle(ActivityEvent::Login, $admin);
    $this->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));

    $trend = LoginTrend::lastDays(14);

    expect($trend)->toHaveCount(14)
        ->and($trend[0])->toBe(['date' => '2026-09-29', 'logins' => 1])
        ->and($trend[13])->toBe(['date' => '2026-10-12', 'logins' => 2])
        ->and($trend[10])->toBe(['date' => '2026-10-09', 'logins' => 1])
        ->and(array_sum(array_column($trend, 'logins')))->toBe(4);
});

it('lists consecutive days whichever date class the application uses (G5-08)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00:00'));
    asOrgAdmin(Organisation::factory()->create(['slug' => 'acme']));
    // AppServiceProvider makes every date immutable; LoginTrend must not depend on it.
    Date::use(Carbon::class);

    try {
        $dates = array_column(LoginTrend::lastDays(3), 'date');
    } finally {
        Date::use(CarbonImmutable::class);
    }

    expect($dates)->toBe(['2026-10-10', '2026-10-11', '2026-10-12']);
});

it('offers the dashboard sign-in trend only to users who may view the activity log (§6.5)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);

    $trendFor = function (): mixed {
        Skv::useFluxPro(false);
        $dashboard = Livewire::test('pages::dashboard');
        Skv::useFluxPro(true);

        return $dashboard->get('loginTrend');
    };

    asMember($organisation);
    expect($trendFor())->toBeNull();

    asOrgAdmin($organisation);
    expect($trendFor())->toBeArray()->toHaveCount(14);
});
