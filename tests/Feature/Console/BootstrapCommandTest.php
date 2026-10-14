<?php

declare(strict_types=1);

/** covers §5.2, RL-10 */

use App\Setup\SetupContext;
use App\Setup\Support\Bootstrap;
use App\Setup\Support\BootstrapRunner;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('points to the browser setup at the project APP_URL, or to skv:install (RL-10)', function (): void {
    Process::fake(["*'artisan' 'migrate'*" => Process::result('Migrated')]);
    $context = setupSandbox();
    $bootstrap = new Bootstrap($context->basePath, app(Toolchain::class));
    app()->instance(SetupContext::class, $context);
    app()->instance(Bootstrap::class, $bootstrap);
    app()->instance(BootstrapRunner::class, $bootstrap);

    expect(Artisan::call('skv:bootstrap', ['--no-interaction' => true], $output = new BufferedOutput))->toBe(0)
        ->and($output->fetch())->toContain(__('Open :url to finish setting up in the browser, or run php artisan skv:install.', ['url' => 'http://localhost']));
});
