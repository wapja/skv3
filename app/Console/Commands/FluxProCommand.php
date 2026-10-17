<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Setup\Support\Toolchain;
use App\Support\Skv;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessSignaledException;

/**
 * Installs the optional Flux Pro layer (§6.7, RL-12): adds the composer.fluxui.dev repository and requires
 * livewire/flux-pro with the credentials Composer already has, then clears compiled views, because Pro branches are
 * stripped at compile time while Pro is missing (DEC-29). It marks composer.json with extra.skv.flux-pro first, so an
 * app that commits Pro on purpose skips the kit's committed-files test (CD-13); Composer's lock hash covers `extra`,
 * so the mark goes in before the require. It never writes credentials and never prints them.
 *
 * An install that does not finish undoes what it added to composer.json, the mark and the repository (L7b-72), and
 * names the manual command for a key Composer would not remove. Ctrl-C or SIGTERM is trapped while pcntl is loaded
 * (L7b-81): Composer, in the same process group, reverts its own change and re-raises the signal, so Symfony throws
 * ProcessSignaledException instead of returning a failed result; that and a timeout are caught and the same undo
 * runs; no later step starts.
 */
#[Description('Install Flux Pro with your Composer credentials (§6.7)')]
#[Signature('skv:flux-pro')]
final class FluxProCommand extends Command
{
    private bool $interrupted = false;

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

        // Artisan keeps one instance of the command per process: a second run starts uninterrupted.
        $this->interrupted = false;
        $this->trap(fn (): array => [SIGINT, SIGTERM], function (): void {
            $this->interrupted = true;
        });

        /** @var list<string> $added the composer.json keys the steps so far have written, undone in reverse */
        $added = [];

        foreach ([
            ['extra.skv', [$composer, 'config', 'extra.skv.flux-pro', 'true', '--json']],
            ['repositories.flux-pro', [$composer, 'config', 'repositories.flux-pro', 'composer', 'https://composer.fluxui.dev']],
            [null, [$composer, 'require', 'livewire/flux-pro', '--no-interaction']],
        ] as [$key, $command]) {
            if ($this->interrupted) {
                $this->undo($toolchain, $composer, $added);
                $this->components->error(__('Flux Pro was not installed: the command was interrupted.'));

                return self::FAILURE;
            }

            if ($key !== null) {
                $added[] = $key;
            }

            try {
                $result = $this->process($toolchain, 900)->run($command);
            } catch (ProcessSignaledException|ProcessTimedOutException $exception) {
                // Ctrl-C: Composer re-raises the signal, so Symfony throws instead of returning a failed result; a
                // timeout throws too (L7b-72, L7b-81).
                $this->undo($toolchain, $composer, $added);
                $this->components->error($this->interrupted || $exception instanceof ProcessSignaledException
                    ? __('Flux Pro was not installed: the command was interrupted.')
                    : __('Composer could not install Flux Pro.'));

                return self::FAILURE;
            }

            if ($result->failed()) {
                // An install that did not finish must not mark the app as committing Flux Pro (CD-13).
                $this->undo($toolchain, $composer, $added);
                $this->components->error($this->interrupted ? __('Flux Pro was not installed: the command was interrupted.') : $this->lastLine($result));

                return self::FAILURE;
            }
        }

        $this->callSilently('view:clear');
        $this->components->info(__('Flux Pro installed. Its components are available after this step; compiled views were cleared.'));

        return self::SUCCESS;
    }

    /**
     * Removes the composer.json keys an unfinished install added, newest first (CD-13, L7b-72). A key Composer does not
     * remove is named with the command that does, so the app never keeps the mark without Flux Pro unnoticed.
     *
     * @param  list<string>  $keys
     */
    private function undo(Toolchain $toolchain, string $composer, array $keys): void
    {
        foreach (array_reverse($keys) as $key) {
            try {
                $failed = $this->process($toolchain, 60)->run([$composer, 'config', '--unset', $key])->failed();
            } catch (ProcessSignaledException|ProcessTimedOutException) {
                // A second Ctrl-C or a hung Composer: the key may still be there, so the manual command is named.
                $failed = true;
            }

            if ($failed) {
                $this->components->warn(__('Could not remove :key from composer.json. Run: composer config --unset :key', ['key' => $key]));
            }
        }
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
