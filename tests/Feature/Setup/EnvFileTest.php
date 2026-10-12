<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Support\EnvFile;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
});

it('writes variables atomically and keeps the rest of the file (§5.3.3 step 1)', function (): void {
    $context = setupSandbox();
    $env = new EnvFile($context->path('.env'));

    $env->set(['APP_NAME' => 'My App', 'MODULE_BACKUPS' => false, 'NEW_KEY' => 'with space']);

    expect($env->get('APP_NAME'))->toBe('My App')
        ->and($env->get('MODULE_BACKUPS'))->toBe('false')
        ->and($env->get('NEW_KEY'))->toBe('with space')
        ->and($env->get('SESSION_COOKIE'))->toBe('app_session')
        ->and(glob($context->path('.env*.tmp')) ?: [])->toBe([]);
});

it('leaves existing values alone unless asked to overwrite', function (): void {
    $env = new EnvFile(setupSandbox()->path('.env'));

    $env->set(['APP_NAME' => 'First']);
    $env->set(['APP_NAME' => 'Second'], overwrite: false);

    expect($env->get('APP_NAME'))->toBe('First');
});
