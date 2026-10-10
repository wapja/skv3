<?php

declare(strict_types=1);

/** covers §7.4 */

use Illuminate\Foundation\DevCommands;

it('runs the scheduler and never artisan serve under Herd', function (): void {
    $names = array_column(DevCommands::commands(), 'name');

    expect($names)->toContain('schedule')
        ->and($names)->not->toContain('server');
})->skip(fn (): bool => ! is_dir((getenv('HOME') ?: '').'/Library/Application Support/Herd'), 'Herd not installed');
