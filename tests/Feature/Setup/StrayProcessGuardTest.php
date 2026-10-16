<?php

declare(strict_types=1);

/** covers §7.2 */

use Illuminate\Support\Facades\Process;

it('refuses a process that no fake names, also in a file without its own fake (RL-12)', function (): void {
    expect(fn () => Process::run(['true']))->toThrow(RuntimeException::class, 'without a matching fake');
});
