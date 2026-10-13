<?php

declare(strict_types=1);

namespace App\Setup;

use App\Setup\Steps\BrandingStep;
use App\Setup\Steps\ClaudeStep;
use App\Setup\Steps\DatabaseStep;
use App\Setup\Steps\DoctorStep;
use App\Setup\Steps\EnvironmentStep;
use App\Setup\Steps\FrontendStep;
use App\Setup\Steps\GitStep;
use App\Setup\Steps\HerdManifestStep;
use App\Setup\Steps\HerdSiteStep;
use App\Setup\Steps\MarkerStep;
use App\Setup\Steps\SummaryStep;
use App\Setup\Support\EnvFile;
use Closure;

/**
 * Runs the setup steps of §5.3.3 in order (RL-6): a complete step is skipped unless forced, a manual() result never
 * stops the run, and a step that throws stops it before the next one. After the Herd step has run, a site that was
 * not secured switches the passkeys module off (RL-7). The CLI runs the whole list; M6b drives it one step per request.
 */
final readonly class SetupRunner
{
    /** @param  list<SetupStep>|null  $steps  null = the eleven steps of §5.3.3 (tests pass their own list) */
    public function __construct(private ?array $steps = null) {}

    /** @return list<SetupStep> the given steps, or the eleven steps of §5.3.3, in order */
    public function steps(): array
    {
        return $this->steps ?? [
            app(EnvironmentStep::class),
            app(DatabaseStep::class),
            app(HerdManifestStep::class),
            app(BrandingStep::class),
            app(FrontendStep::class),
            app(GitStep::class),
            app(ClaudeStep::class),
            app(DoctorStep::class),
            app(MarkerStep::class),
            app(HerdSiteStep::class),
            app(SummaryStep::class),
        ];
    }

    /** @param  (Closure(SetupStep, Closure(): StepResult): StepResult)|null  $around  wraps each step (the CLI's task() spinner) */
    public function run(SetupContext $context, ?Closure $around = null): SetupContext
    {
        foreach ($this->steps() as $step) {
            $wasComplete = ! $context->force && $step->isComplete($context);
            $execute = fn (): StepResult => $wasComplete ? StepResult::skipped(__('Already done')) : $step->run($context);

            $context->results[$step->key()] = $around instanceof Closure ? $around($step, $execute) : $execute();

            // A Herd step skipped as complete found the site secured, so a resumed install never turns passkeys off.
            if ($step->key() === 'herd-site' && ! $wasComplete) {
                $this->applyHttpsConsequence($context);
            }
        }

        return $context;
    }

    /**
     * §6.8, RL-7: passkeys need HTTPS. When they were chosen but the Herd step did not secure the site, the module is
     * switched off in .env and in the answers, the summary says how to turn it back on, and an existing marker is
     * rewritten so its answers and notes match .env (the doctor then no longer expects a certificate).
     */
    public function applyHttpsConsequence(SetupContext $context): void
    {
        $message = $context->results['herd-site']->message ?? '';

        if (! $context->answers->moduleEnabled('passkeys') || str_starts_with($message, HerdSiteStep::SECURED)) {
            return;
        }

        new EnvFile($context->path('.env'))->set(['MODULE_PASSKEYS' => false]);

        $context->answers = $context->answers
            ->withModules(array_diff($context->answers->modules, ['passkeys']))
            ->withHttps(false);

        $context->notes[] = __('Passkeys are off because the site has no HTTPS. To turn them on: herd secure :name, set MODULE_PASSKEYS=true, then php artisan skv:doctor --fix.', ['name' => $context->folderName()]);

        if (Marker::exists($context)) {
            Marker::write($context, Summary::build($context));
        }
    }
}
