<?php

declare(strict_types=1);

/** covers §5.3.2, §5.3.3 */

use App\Models\User;
use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\InstallPreflight;
use App\Setup\Marker;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('takes the installed answers from the marker with the MODULE_* flags of .env on top (DEC-30 (29))', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['modules' => ['passkeys', 'impersonation']], Detection::none(), $context->folderName());
    Marker::write($context, []);
    // The sandbox .env is .env.example, which turns every module on: .env switches passkeys off over the marker, and backups off as the marker has it.
    new EnvFile($context->path('.env'))->set(['MODULE_PASSKEYS' => false, 'MODULE_BACKUPS' => false]);

    expect(new InstallPreflight($context)->installedAnswers()['modules'])->toBe(['impersonation']);
});

it('refuses the built-in demo default next to an own super admin (DEC-30 (29))', function (): void {
    $context = setupSandbox();
    User::factory()->superAdmin()->create(['email' => 'owner@example.com']);
    $answers = Answers::fromArray([], Detection::none(), $context->folderName());
    $preflight = new InstallPreflight($context);

    expect($preflight->refusesDemoDefault($answers, [], [], Detection::none()))->toBeTrue()
        ->and($preflight->refusesDemoDefault($answers, ['start_with' => 'demo'], [], Detection::none()))->toBeFalse();
});

it('never refuses the demo start an earlier install chose (DEC-30, g2-28)', function (): void {
    $context = setupSandbox();
    User::factory()->superAdmin()->create(['email' => 'owner@example.com']);
    $installed = ['start_with' => 'demo'];
    $answers = Answers::fromArray([], Detection::none(), $context->folderName(), $installed);

    expect($answers->startWith)->toBe('demo')
        ->and(new InstallPreflight($context)->refusesDemoDefault($answers, [], $installed, Detection::none()))->toBeFalse();
});
