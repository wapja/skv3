<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Answers;
use App\Setup\StepResult;
use App\Setup\Summary;
use App\Setup\Support\Toolchain;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('lists the addresses, the demo logins with their password and the manual commands (§5.3.3 step 11)', function (): void {
    $context = setupSandbox();
    $context->results['frontend'] = StepResult::manual('npm ci && npm run build', 'Node is missing');

    $summary = Summary::build($context);

    expect($summary['urls'])->toContain('http://'.$context->detection->host)
        ->and(array_column($summary['logins'], 'email'))->toBe(array_column(DemoSeeder::accounts($context->detection->host), 'email'))
        ->and(array_unique(array_column($summary['logins'], 'password')))->toBe([DemoSeeder::PASSWORD])
        ->and($summary['manual'])->toBe([['step' => 'frontend', 'command' => 'npm ci && npm run build', 'reason' => 'Node is missing']]);
});

it('names the super admin login without a password (§5.3.3 step 11)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray([
        'start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com', 'super_admin_password' => 'correct-horse-battery',
    ], $context->detection, $context->folderName());

    $summary = Summary::build($context);

    expect($summary['logins'])->toBe([['email' => 'owner@example.com', 'password' => null, 'url' => 'http://admin.'.$context->detection->host]])
        ->and(json_encode($summary, JSON_THROW_ON_ERROR))->not->toContain('correct-horse-battery');
});
