<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('uses immutable dates', function (): void {
    expect(now())->toBeInstanceOf(CarbonImmutable::class);
});

it('prohibits destructive database commands in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    try {
        new AppServiceProvider(app())->boot();

        $this->artisan('db:wipe', ['--force' => true])->assertFailed();
        $this->artisan('migrate:fresh', ['--force' => true])->assertFailed();
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
