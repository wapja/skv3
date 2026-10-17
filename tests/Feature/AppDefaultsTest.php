<?php

declare(strict_types=1);

/** covers §4.0.1, §4.0.2 */

use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

it('uses immutable dates', function (): void {
    expect(now()::class)->toBe(CarbonImmutable::class);
});

it('prohibits destructive database commands in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    try {
        new AppServiceProvider(app())->boot();

        expect(Artisan::call('db:wipe', ['--force' => true]))->toBe(Command::FAILURE);
        expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(Command::FAILURE)
            ->and(Model::preventsLazyLoading())->toBeFalse();
    } finally {
        // The prohibition is a process-wide static flag: reset it, or later tests
        // (RefreshDatabase runs migrate:fresh on PostgreSQL) would be refused too.
        app()->detectEnvironment(fn (): string => 'testing');
        DB::prohibitDestructiveCommands(false);
        Model::shouldBeStrict(true);
    }
});

it('allows destructive database commands outside production', function (): void {
    new AppServiceProvider(app())->boot();

    expect(app()->isProduction())->toBeFalse()
        ->and(new ReflectionProperty(WipeCommand::class, 'prohibitedFromRunning')->getValue())->toBeFalse()
        ->and(new ReflectionProperty(FreshCommand::class, 'prohibitedFromRunning')->getValue())->toBeFalse();
});

it('makes Eloquent strict outside production', function (): void {
    expect(Model::preventsLazyLoading())->toBeTrue()
        ->and(Model::preventsSilentlyDiscardingAttributes())->toBeTrue()
        ->and(Model::preventsAccessingMissingAttributes())->toBeTrue();
});

it('applies the strict password rule everywhere but local development and the test suite (§4.0.1, CD-10)', function (string $environment, bool $accepted): void {
    app()->detectEnvironment(fn (): string => $environment);

    try {
        // Ten lower-case letters: the lax rule (8 characters) accepts it; the strict one refuses it on length and
        // case before it asks the breach API, so the test sends nothing over the network.
        $validator = Validator::make(['password' => 'abcdefghij'], ['password' => Password::default()]);

        expect($validator->passes())->toBe($accepted);
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
})->with([
    'production' => ['production', false],
    'staging' => ['staging', false],
    'local' => ['local', true],
    'testing' => ['testing', true],
]);
