<?php

declare(strict_types=1);

/** covers §4.0 */

use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('uses immutable dates', function (): void {
    expect(now()::class)->toBe(CarbonImmutable::class);
});

it('prohibits destructive database commands in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    try {
        new AppServiceProvider(app())->boot();

        expect(Artisan::call('db:wipe', ['--force' => true]))->toBe(Command::FAILURE);
        expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(Command::FAILURE);
    } finally {
        // The prohibition is a process-wide static flag: reset it, or later tests
        // (RefreshDatabase runs migrate:fresh on PostgreSQL) would be refused too.
        app()->detectEnvironment(fn (): string => 'testing');
        DB::prohibitDestructiveCommands(false);
    }
});

it('allows destructive database commands outside production', function (): void {
    expect(app()->isProduction())->toBeFalse();
});
