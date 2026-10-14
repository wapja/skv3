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
use Illuminate\Support\Str;

/**
 * Runs the setup steps of §5.3.3 in order (RL-6): a complete step is skipped unless forced, a manual() result never
 * stops the run, and a step that throws stops it before the next one. Before the first step and after every step,
 * the process's runtime config follows what the steps wrote to .env. After the Herd step has run, a site that was
 * not secured is recorded as http and switches the passkeys module off (RL-7, OD-2). A run that finishes rewrites an
 * existing marker with its answers, so the next re-run keeps what this one changed, and a re-run names the .env keys
 * it changed (DEC-30). The CLI runs the whole list; the browser wizard calls runStep() once per request and finish()
 * at the end.
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
        $before = Marker::exists($context) ? new EnvFile($context->path('.env'))->all() : null;
        $context->syncRuntimeConfig();

        foreach ($this->steps() as $step) {
            $this->runStep($context, $step, $around);
        }

        $this->finish($context, $before);

        return $context;
    }

    /**
     * One step under the runner's rules (RL-6, A12): skipped when complete unless forced; after the Herd step has run,
     * the HTTPS consequence; then the runtime config follows .env. The wrapper sees the step before its completeness
     * check, so a check that throws is reported under the right step. The browser wizard calls this once per request.
     *
     * @param  (Closure(SetupStep, Closure(): StepResult): StepResult)|null  $around
     */
    public function runStep(SetupContext $context, SetupStep $step, ?Closure $around = null): StepResult
    {
        $wasComplete = false;
        $execute = function () use ($context, $step, &$wasComplete): StepResult {
            $wasComplete = ! $context->force && $step->isComplete($context);

            return $wasComplete ? StepResult::skipped(__('Already done')) : $step->run($context);
        };

        $result = $around instanceof Closure ? $around($step, $execute) : $execute();
        $context->results[$step->key()] = $result;

        // A Herd step skipped as complete found the site secured, so a resumed install never turns passkeys off.
        if ($step->key() === 'herd-site' && ! $wasComplete) {
            $this->applyHttpsConsequence($context);
        }

        $context->syncRuntimeConfig();

        return $result;
    }

    /**
     * The end of a run: a re-run names the .env keys it changed (never their values), and an existing marker is
     * rewritten with the final answers and summary (DEC-30 (14), (29)).
     *
     * @param  array<string, string>|null  $environmentBefore  .env before the run, when a marker existed
     */
    public function finish(SetupContext $context, ?array $environmentBefore): void
    {
        if ($environmentBefore !== null) {
            $after = new EnvFile($context->path('.env'))->all();
            $changed = array_keys(array_diff_assoc($after, $environmentBefore) + array_diff_assoc($environmentBefore, $after));

            if ($changed !== []) {
                $context->notes[] = __('Setup changed :keys in .env.', ['keys' => implode(', ', $changed)]);
            }
        }

        if (Marker::exists($context)) {
            Marker::write($context, Summary::build($context));
        }
    }

    /**
     * §6.8, RL-7, OD-2: when HTTPS was chosen but the Herd step did not secure the site, APP_URL stays http and the
     * answers say so (the doctor then expects no certificate). Passkeys need HTTPS, so they are switched off in .env
     * and in the answers. The summary says how to secure the site later, and an existing marker is rewritten. Without
     * Herd, an APP_URL that already is https on the detected host is served over HTTPS by something else (a
     * TLS-terminating proxy), so nothing changes.
     */
    public function applyHttpsConsequence(SetupContext $context): void
    {
        $message = $context->results['herd-site']->message ?? '';

        if (! $context->answers->https || str_starts_with($message, HerdSiteStep::SECURED) || $this->servedOverHttpsWithoutHerd($context)) {
            return;
        }

        $name = $context->folderName();

        if ($context->answers->moduleEnabled('passkeys')) {
            new EnvFile($context->path('.env'))->set(['MODULE_PASSKEYS' => false]);
            $context->answers = $context->answers->withModules(array_diff($context->answers->modules, ['passkeys']));
            $context->notes[] = __('Passkeys are off because the site has no HTTPS. To turn them on: herd secure :name, set MODULE_PASSKEYS=true, then php artisan skv:doctor --fix.', ['name' => $name]);
        } else {
            $context->notes[] = __('The site has no HTTPS. To secure it: herd secure :name, then php artisan skv:install --force.', ['name' => $name]);
        }

        $context->answers = $context->answers->withHttps(false);

        if (Marker::exists($context)) {
            Marker::write($context, Summary::build($context));
        }
    }

    private function servedOverHttpsWithoutHerd(SetupContext $context): bool
    {
        $appUrl = (string) new EnvFile($context->path('.env'))->get('APP_URL');

        return ! $context->detection->herd
            && parse_url($appUrl, PHP_URL_SCHEME) === 'https'
            && Str::lower((string) parse_url($appUrl, PHP_URL_HOST)) === Str::lower($context->detection->host);
    }
}
