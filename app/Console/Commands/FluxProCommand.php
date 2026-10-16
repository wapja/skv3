<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Setup\Support\Toolchain;
use App\Support\Skv;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Installs the optional Flux Pro layer (§6.7, RL-12): adds the composer.fluxui.dev repository and requires
 * livewire/flux-pro with the credentials Composer already has, then clears compiled views, because Pro branches are
 * stripped at compile time while Pro is missing (DEC-29). It marks composer.json with extra.skv.flux-pro first, so an
 * app that commits Pro on purpose skips the kit's committed-files test (CD-13); Composer's lock hash covers `extra`,
 * so the mark goes in before the require. It never writes credentials and never prints them.
 */
#[Description('Install Flux Pro with your Composer credentials (§6.7)')]
#[Signature('skv:flux-pro')]
final class FluxProCommand extends Command
{
    public function handle(Toolchain $toolchain): int
    {
        if (Skv::hasFluxPro()) {
            $this->callSilently('view:clear');
            $this->components->info(__('Flux Pro is already installed.'));

            return self::SUCCESS;
        }

        $composer = $toolchain->which('composer') ?? 'composer';

        // The output is the credentials themselves: it is only tested for being non-empty, never shown or logged.
        $credentials = $this->process($toolchain, 60)->run([$composer, 'config', 'http-basic.composer.fluxui.dev']);

        if ($credentials->failed() || trim($credentials->output()) === '') {
            $this->components->error(__('Add your Flux Pro credentials first: composer config --global http-basic.composer.fluxui.dev <email> <license-key>'));

            return self::FAILURE;
        }

        foreach ([
            [$composer, 'config', 'extra.skv.flux-pro', 'true', '--json'],
            [$composer, 'config', 'repositories.flux-pro', 'composer', 'https://composer.fluxui.dev'],
            [$composer, 'require', 'livewire/flux-pro', '--no-interaction'],
        ] as $command) {
            $result = $this->process($toolchain, 900)->run($command);

            if ($result->failed()) {
                // An install that did not finish must not mark the app as committing Flux Pro (CD-13).
                $this->process($toolchain, 60)->run([$composer, 'config', '--unset', 'extra.skv']);
                $this->components->error($this->lastLine($result));

                return self::FAILURE;
            }
        }

        $this->callSilently('view:clear');
        $this->components->info(__('Flux Pro installed. Its components are available after this step; compiled views were cleared.'));

        return self::SUCCESS;
    }

    /** The last line Composer printed (it prints no credentials), or a generic message when it printed nothing. */
    private function lastLine(ProcessResult $result): string
    {
        $output = trim($result->errorOutput()) !== '' ? trim($result->errorOutput()) : trim($result->output());

        return $output !== '' ? Str::afterLast($output, "\n") : __('Composer could not install Flux Pro.');
    }

    private function process(Toolchain $toolchain, int $timeout): PendingProcess
    {
        return Process::path(base_path())->env($toolchain->environment())->timeout($timeout);
    }
}
