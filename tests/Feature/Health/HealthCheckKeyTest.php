<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Setup\Steps\EnvironmentStep;
use App\Support\Health\HealthCheckKey;

it('counts only a string of at least 32 characters as a configured key (CD-4, F15)', function (mixed $configured, bool $usable): void {
    config(['skv.health_check.key' => $configured]);

    expect(HealthCheckKey::configured())->toBe($usable ? $configured : null)
        ->and(HealthCheckKey::isUsable($configured))->toBe($usable);
})->with([
    'unset' => [null, false],
    'blank' => ['', false],
    'cast from HEALTH_CHECK_KEY=true' => [true, false],
    'a word' => ['monitor', false],
    'one short' => [str_repeat('k', 31), false],
    'the minimum' => [str_repeat('k', 32), true],
    'as setup writes it' => [str_repeat('k', 40), true],
]);

it('matches only the configured key, and nothing while that key is too short (CD-4)', function (): void {
    config(['skv.health_check.key' => str_repeat('k', 40)]);

    expect(HealthCheckKey::matches(str_repeat('k', 40)))->toBeTrue()
        ->and(HealthCheckKey::matches(str_repeat('k', 39)))->toBeFalse()
        ->and(HealthCheckKey::matches(null))->toBeFalse()
        ->and(HealthCheckKey::matches([str_repeat('k', 40)]))->toBeFalse();

    config(['skv.health_check.key' => 'monitor']);

    expect(HealthCheckKey::matches('monitor'))->toBeFalse();
});

it('generates keys that the rule accepts (CD-4)', function (): void {
    expect(EnvironmentStep::GENERATED['HEALTH_CHECK_KEY'])->toBeGreaterThanOrEqual(HealthCheckKey::MIN_LENGTH);
});
