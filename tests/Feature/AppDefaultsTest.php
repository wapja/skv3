<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('uses immutable dates', function () {
    expect(now())->toBeInstanceOf(CarbonImmutable::class);
});

it('prohibits destructive database commands in production', function () {
    app()->detectEnvironment(fn () => 'production');

    try {
        (new AppServiceProvider(app()))->boot();

        $this->artisan('db:wipe', ['--force' => true])->assertFailed();
        $this->artisan('migrate:fresh', ['--force' => true])->assertFailed();
    } finally {
        // The prohibition is a process-wide static flag: reset it, or later tests
        // (RefreshDatabase runs migrate:fresh on PostgreSQL) would be refused too.
        app()->detectEnvironment(fn () => 'testing');
        DB::prohibitDestructiveCommands(false);
    }
});

it('allows destructive database commands outside production', function () {
    expect(app()->isProduction())->toBeFalse();
});
