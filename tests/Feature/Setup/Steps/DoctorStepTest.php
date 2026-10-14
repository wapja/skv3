<?php

declare(strict_types=1);

/** covers §5.3.3, §5.4 */

use App\Enums\CheckStatus;
use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Doctor\Doctor;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Steps\DoctorStep;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('defers the Herd and token checks to after the Herd step (A6, RL-8)', function (): void {
    expect(DoctorStep::DEFERRED)->toBe(['herd-site', 'herd-secured', 'setup-token']);

    $context = setupSandbox(new Detection(herd: true, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: false, host: 'x.test', scheme: 'http'));
    $results = app(Doctor::class)->run(DoctorContext::fromSetup($context, app(Toolchain::class)), deferred: DoctorStep::DEFERRED);

    foreach (DoctorStep::DEFERRED as $key) {
        expect($results[$key]->status)->toBe(CheckStatus::Skipped)
            ->and($results[$key]->message)->toBe(__('Checked after the Herd step'));
    }
});

it('checks the answers being installed when there is no marker yet (A6, RL-8)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['claude' => true], Detection::none(), $context->folderName());

    $doctor = DoctorContext::fromSetup($context, app(Toolchain::class));

    expect($doctor->answer('claude'))->toBeTrue()
        ->and($doctor->marker['start_with'] ?? null)->toBe('demo');
});
