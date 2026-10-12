<?php

declare(strict_types=1);

/** covers §6.5 */

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Support\Dashboard\LoginTrend;
use App\Tenancy\Facades\Tenant;

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

    $trend = LoginTrend::lastDays(14);

    expect($trend)->toHaveCount(14)
        ->and($trend[13])->toBe(['date' => '2026-10-12', 'logins' => 2])
        ->and($trend[10])->toBe(['date' => '2026-10-09', 'logins' => 1])
        ->and(array_sum(array_column($trend, 'logins')))->toBe(3);
});
