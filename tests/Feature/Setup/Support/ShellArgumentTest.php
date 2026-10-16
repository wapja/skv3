<?php

declare(strict_types=1);

/** covers §5.3.1, §5.4 */

use App\Setup\Support\ShellArgument;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('leaves a value no shell treats specially as it is, so ordinary names stay readable (RL-6)', function (string $value): void {
    expect(ShellArgument::quote($value))->toBe($value);
})->with(['my-app', 'MyApp_2', 'v1.2.3', '--site=my-app', '/Users/me/Herd/my-app', 'admin@example.com', 'a+b%c:d']);

it('single-quotes a value with a space or a shell character (RL-6, g2-05)', function (string $value, string $quoted): void {
    expect(ShellArgument::quote($value))->toBe($quoted);
})->with([
    'a space' => ['my app', "'my app'"],
    'a command' => ['app;rm -rf ~', "'app;rm -rf ~'"],
    'a variable' => ['$HOME', "'\$HOME'"],
    'a single quote' => ["it's", "'it'\\''s'"],
    'nothing' => ['', "''"],
]);
