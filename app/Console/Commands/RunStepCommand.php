<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Setup\SetupContext;
use App\Setup\SetupRunner;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use App\Setup\Steps\HerdSiteStep;
use App\Setup\Steps\SummaryStep;
use App\Setup\Support\SafeMessage;
use App\Setup\Support\StateFile;
use App\Setup\Wizard\HerdState;
use App\Setup\Wizard\SetupLock;
use App\Setup\Wizard\WizardState;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Hidden;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * One setup step for the browser wizard (RL-4): the detached long steps (frontend, Claude) and, after the marker, the
 * Herd step (RL-6). The context comes from the wizard state, or for the Herd step from the Herd state; the result is
 * written as JSON (the Herd step's into the Herd state). Never writes the wizard state, which only the page writes.
 */
#[Description('Run one setup step for the browser wizard (internal)')]
#[Hidden]
#[Signature('skv:run-step {step : The step key} {--result= : Where the result goes, inside storage/app/private}')]
final class RunStepCommand extends Command
{
    public function handle(SetupRunner $runner): int
    {
        $basePath = app(SetupContext::class)->basePath;
        $resultFile = $this->option('result');

        if (! is_string($resultFile) || $resultFile === '') {
            $this->components->error(__('The result must go inside storage/app/private.'));

            return self::FAILURE;
        }

        $private = realpath($basePath.'/storage/app/private');
        $directory = realpath(dirname($resultFile));

        if ($private === false || $directory === false || ! str_starts_with($directory.'/', $private.'/')) {
            $this->components->error(__('The result must go inside storage/app/private.'));

            return self::FAILURE;
        }

        $step = collect($runner->steps())->first(fn (SetupStep $step): bool => $step->key() === $this->argument('step'));

        if (! $step instanceof SetupStep) {
            $this->components->error(__('Unknown setup step.'));

            return self::FAILURE;
        }

        $herd = $step->key() === 'herd-site';
        $state = $herd ? HerdState::load($basePath) : WizardState::load($basePath);

        // The page moved on before Herd started: the login used the secret up, or the user continued without HTTPS.
        // Running Herd now would act against that choice and rewrite the marker (RL-6).
        if ($herd && ($state === null || ($state instanceof HerdState && $state->result instanceof StepResult))) {
            return self::SUCCESS;
        }

        if ($state === null) {
            $this->components->error(__('There is no setup in progress.'));

            return self::FAILURE;
        }

        // The detection the wizard took at the start (CD-15): a detached step never detects again.
        $context = $state->context($basePath);

        try {
            $result = $runner->runStep($context, $step);
        } catch (Throwable $exception) {
            $command = $herd ? app(HerdSiteStep::class)->manualCommand($context) : 'php artisan skv:install';
            $result = $command === null ? StepResult::skipped(SafeMessage::of($exception)) : StepResult::manual($command, SafeMessage::of($exception));
        }

        if ($state instanceof HerdState) {
            SetupLock::run($basePath, function () use ($state, $basePath, $context, $step, $result, $runner): void {
                // The same while Herd ran: writing now would bring the secret back, overwrite the user's choice or
                // replace another finish()'s state (RL-6).
                $current = HerdState::load($basePath);

                // The login used the state up and removed the lock: this late child removes the lock it recreated (g3-02).
                if (! $current instanceof HerdState) {
                    SetupLock::forget($basePath);

                    return;
                }

                if (! hash_equals($state->secretHash, $current->secretHash) || $current->result instanceof StepResult) {
                    return;
                }

                // A Herd step that threw never reached runStep()'s consequence: the site is not secured (OD-2).
                if (! isset($context->results[$step->key()])) {
                    $context->results[$step->key()] = $result;
                    $runner->applyHttpsConsequence($context);
                }

                $runner->runStep($context, app(SummaryStep::class));
                $runner->finish($context, null);
                $state->absorb($context);
                $state->result = $result;
                $state->save($basePath);
            });

            return self::SUCCESS;
        }

        StateFile::writeJson($resultFile, [...$result->toArray(), 'finished_at' => Date::now()->toIso8601String()]);

        return self::SUCCESS;
    }
}
