<?php

declare(strict_types=1);

namespace App\Setup\Wizard;

use App\Setup\Answers;
use App\Setup\Detector;
use App\Setup\Http\SetupHostGuard;
use App\Setup\InstallPreflight;
use App\Setup\Marker;
use App\Setup\SetupContext;
use App\Setup\SetupRunner;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use App\Setup\Steps\HerdSiteStep;
use App\Setup\Steps\MarkerStep;
use App\Setup\Steps\SummaryStep;
use App\Setup\Support\SafeMessage;
use App\Setup\Support\StateFile;
use App\Setup\Support\Toolchain;
use App\Tenancy\Facades\Tenant;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Drives the setup steps for the browser wizard (§5.3.4, RL-4): one step per request, the long ones detached through
 * the launcher, every result kept in the wizard state, every call inside Tenant::bypass() (strict mode: the doctor
 * and the seeders read tenant models). Steps 9–11 follow in finish() and the Herd stage (RL-6). Every load-check-save
 * runs under the SetupLock, so a reload or a second tab never runs a step twice (§5.3.4).
 */
final readonly class WizardRunner
{
    /** @var list<string> */
    public const array PAGE_STEPS = ['environment', 'database', 'herd-manifest', 'branding', 'frontend', 'git', 'claude', 'doctor'];

    /** A detached step whose log has not moved for this long is reported as stuck. */
    private const int STALL_SECONDS = 1800;

    public function __construct(
        private SetupRunner $runner,
        private Detector $detector,
        private StepLauncher $launcher,
        private Toolchain $toolchain,
    ) {}

    /**
     * @param  string  $requestHost  the request's host with its port, when it has one (getHttpHost())
     * @return array{answers: Answers, host: ?string, problem: ?string} the form's defaults, the pinned host (null: refused) and why it is refused
     */
    public function defaults(string $requestHost): array
    {
        ['host' => $host, 'problem' => $problem] = $this->host($requestHost);
        $context = app(SetupContext::class);

        return [
            'answers' => Answers::fromArray([], $this->detector->detect($host ?? 'localhost'), $context->folderName(), $this->installed($context)),
            'host' => $host,
            'problem' => $problem,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  string  $requestHost  the request's host with its port, when it has one (getHttpHost())
     *
     * @throws ValidationException
     */
    public function begin(array $input, string $requestHost): WizardState
    {
        $running = fn (): never => throw ValidationException::withMessages(['host' => __('Setup is already running.')]);

        // A second tab or browser never resets a running setup: its answers and results stay (§5.3.4).
        return SetupLock::run(app(SetupContext::class)->basePath, function () use ($input, $requestHost, $running): WizardState {
            if ($this->state() instanceof WizardState) {
                $running();
            }

            ['host' => $host, 'problem' => $problem] = $this->host($requestHost);

            if ($host === null) {
                throw ValidationException::withMessages(['host' => (string) $problem]);
            }

            $context = app(SetupContext::class);
            $detection = $this->detector->detect($host);
            $installed = $this->installed($context);
            $answers = Answers::fromArray($input, $detection, $context->folderName(), $installed);

            if (Tenant::bypass(fn (): bool => new InstallPreflight($context)->refusesDemoDefault($answers, $input, $installed, $detection))) {
                throw ValidationException::withMessages(['start_with' => __('A super admin already exists, so setup does not add the demo data by default. Pass "start_with": "demo" to add it anyway, or "super_admin" with your own login.')]);
            }

            $state = new WizardState($host, $answers);
            $state->save($context->basePath);

            return $state;
        }, busy: $running);
    }

    /** The wizard state; a file that is not a state this kit wrote is deleted and reads as none. */
    public function state(): ?WizardState
    {
        $basePath = app(SetupContext::class)->basePath;

        try {
            return WizardState::load($basePath);
        } catch (JsonException|InvalidArgumentException) {
            WizardState::forget($basePath);

            return null;
        }
    }

    /** @return array{key: string, state: 'done'|'running'|'failed', message: string}|null null once steps 1–8 all have a result */
    public function advance(): ?array
    {
        $this->extendTimeLimit();
        $basePath = app(SetupContext::class)->basePath;

        // While another request runs a step (a reload, a second tab), the page keeps polling: that step is running.
        return SetupLock::run($basePath, fn (): ?array => Tenant::bypass(function () use ($basePath): ?array {
            $state = $this->current() ?? throw new RuntimeException(__('There is no setup in progress.'));
            $context = $state->context($basePath, $this->detector->detect($state->host));
            $context->syncRuntimeConfig();
            $step = $this->nextStep($state);

            if (! $step instanceof SetupStep) {
                return null;
            }

            if ($step->isLong()) {
                return $this->advanceLong($state, $context, $step);
            }

            try {
                $result = $this->runner->runStep($context, $step);
            } catch (Throwable $exception) {
                return ['key' => $step->key(), 'state' => 'failed', 'message' => SafeMessage::of($exception)];
            }

            $state->absorb($context);
            $state->save($basePath);

            return ['key' => $step->key(), 'state' => 'done', 'message' => $result->message];
        }), busy: fn (): array => ['key' => $this->nextKey(), 'state' => 'running', 'message' => '']);
    }

    /** Forgets a step's result, so the next advance() runs it again (the page's "Re-check"). */
    public function recheck(string $key): void
    {
        if (! in_array($key, self::PAGE_STEPS, true)) {
            return;
        }

        $basePath = app(SetupContext::class)->basePath;

        SetupLock::run($basePath, function () use ($key, $basePath): void {
            $state = $this->current();

            if (! $state instanceof WizardState) {
                return;
            }

            unset($state->results[$key]);

            foreach (['json', 'log', 'exit', 'started'] as $extension) {
                @unlink($this->stepFile($basePath, $key, $extension));
            }

            $state->save($basePath);
        }, busy: $this->busy(...));
    }

    /** Step 9 in this request, then step 10 detached (RL-6); returns the secret the page polls with. */
    public function finish(): string
    {
        $basePath = app(SetupContext::class)->basePath;

        return SetupLock::run($basePath, fn (): string => Tenant::bypass(function () use ($basePath): string {
            $state = $this->current() ?? throw new RuntimeException(__('There is no setup in progress.'));

            if ($this->nextStep($state) instanceof SetupStep) {
                throw new RuntimeException(__('Finish the earlier steps first.'));
            }

            $context = $state->context($basePath, $this->detector->detect($state->host));
            $context->syncRuntimeConfig();
            $this->runner->runStep($context, app(MarkerStep::class));

            [$herd, $secret] = HerdState::begin($basePath, $context, $state->host);
            $root = rtrim($basePath, '/');
            $started = $this->launcher->start($basePath, 'herd-site', $root.'/'.HerdState::PATH, $root.'/'.HerdState::LOG, $root.'/'.HerdState::EXIT);

            if (! $started) {
                $this->settleWithoutHttps($herd, __('The Herd step could not start in the background.'));
            }

            return $secret;
        }), busy: $this->busy(...));
    }

    /**
     * Ends the Herd stage without a secured site (OD-2, RL-6): the Herd step could not start, or the user continued
     * without HTTPS. The HTTPS consequence applies, and the marker is rewritten with the manual `herd secure`. The
     * callers hold the SetupLock.
     */
    public function settleWithoutHttps(HerdState $herd, string $message): void
    {
        Tenant::bypass(function () use ($herd, $message): void {
            $basePath = app(SetupContext::class)->basePath;
            $context = $herd->context($basePath, $this->detector->detect($herd->host));
            $context->results['herd-site'] = StepResult::manual('herd secure '.$context->folderName(), $message);
            $this->runner->applyHttpsConsequence($context);
            $this->runner->finish($context, null);
            $herd->absorb($context);
            $herd->result = $context->results['herd-site'];
            $herd->save($basePath);
        });
    }

    /**
     * The server-side confirmation of an overdue Herd step (§5.3.4, RL-6): its process exited without a result, or the
     * poll's 60 seconds are over. When Herd now serves (and secures) the site, the stage ends as the detached step
     * would have ended it. Returns the Herd step's result, or null while Herd confirms nothing; a state that another
     * finish() replaced or the login used up is left alone (under the SetupLock, like every Herd-state writer).
     */
    public function confirmHerd(HerdState $herd): ?StepResult
    {
        $basePath = app(SetupContext::class)->basePath;

        return SetupLock::run($basePath, function () use ($herd, $basePath): ?StepResult {
            $current = HerdState::load($basePath);

            if (! $current instanceof HerdState || ! hash_equals($herd->secretHash, $current->secretHash)) {
                return null;
            }

            // The detached step may have answered since this request loaded the state: its answer stands (RL-6).
            if ($current->result instanceof StepResult) {
                return $current->result;
            }

            if ($this->toolchain->herd() === null) {
                return null;
            }

            return Tenant::bypass(function () use ($current, $basePath): ?StepResult {
                $context = $current->context($basePath, $this->detector->detect($current->host));
                $result = app(HerdSiteStep::class)->confirm($context);

                if (! $result instanceof StepResult) {
                    return null;
                }

                $context->results['herd-site'] = $result;
                $this->runner->applyHttpsConsequence($context);
                $this->runner->runStep($context, app(SummaryStep::class));
                $this->runner->finish($context, null);
                $current->absorb($context);
                $current->result = $result;
                $current->save($basePath);

                return $result;
            });
        });
    }

    /** @return list<array{key: string, label: string, status: ?string, message: string, command: ?string, long: bool}> */
    public function progress(): array
    {
        $state = $this->state();
        $rows = [];

        foreach ($this->runner->steps() as $step) {
            if (! in_array($step->key(), self::PAGE_STEPS, true)) {
                continue;
            }

            $result = $state?->results[$step->key()] ?? null;
            $rows[] = [
                'key' => $step->key(),
                'label' => $step->label(),
                'status' => $result?->status,
                'message' => $result->message ?? '',
                'command' => $result?->command,
                'long' => $step->isLong(),
            ];
        }

        return $rows;
    }

    /**
     * Five minutes for the step of this web request (RL-4). A console process (a test worker) keeps its own limit:
     * set_time_limit() would turn the CLI's unlimited default into 300 seconds for everything it runs afterwards.
     */
    private function extendTimeLimit(): void
    {
        if (! app()->runningInConsole()) {
            set_time_limit(300);
        }
    }

    /** The state under the SetupLock; none once the marker exists, so nothing writes it again after step 9 (RL-5). */
    private function current(): ?WizardState
    {
        return Marker::exists(app(SetupContext::class)) ? null : $this->state();
    }

    /** The step another request is running while it holds the lock; read only, a broken state is left to that request. */
    private function nextKey(): string
    {
        try {
            $state = WizardState::load(app(SetupContext::class)->basePath);
        } catch (JsonException|InvalidArgumentException) {
            return '';
        }

        return ($state instanceof WizardState ? $this->nextStep($state)?->key() : null) ?? '';
    }

    private function busy(): never
    {
        throw new RuntimeException(__('Setup is busy in another tab.'));
    }

    private function nextStep(WizardState $state): ?SetupStep
    {
        foreach ($this->runner->steps() as $step) {
            if (in_array($step->key(), self::PAGE_STEPS, true) && ! isset($state->results[$step->key()])) {
                return $step;
            }
        }

        return null;
    }

    /** @return array{key: string, state: 'done'|'running'|'failed', message: string} */
    private function advanceLong(WizardState $state, SetupContext $context, SetupStep $step): array
    {
        $basePath = $context->basePath;
        $key = $step->key();
        $resultFile = $this->stepFile($basePath, $key, 'json');

        if (is_file($resultFile)) {
            try {
                $result = StepResult::fromArray(StateFile::readJson($resultFile) ?? []);
            } catch (Throwable) {
                $result = StepResult::manual($this->fallbackCommand($key), __('The step wrote no readable result.'));
            }

            return $this->record($state, $context, $key, $result);
        }

        if (is_file($this->stepFile($basePath, $key, 'exit'))) {
            return $this->record($state, $context, $key, StepResult::manual($this->fallbackCommand($key), __('The step stopped without a result; see :log.', ['log' => WizardState::STEPS.'/'.$key.'.log'])));
        }

        $started = $this->stepFile($basePath, $key, 'started');

        if (is_file($started)) {
            $log = $this->stepFile($basePath, $key, 'log');
            $lastActivity = max((int) @filemtime($started), (int) @filemtime($log));

            return Date::now()->getTimestamp() - $lastActivity > self::STALL_SECONDS
                ? $this->record($state, $context, $key, StepResult::manual($this->fallbackCommand($key), __('The step has not finished; see :log.', ['log' => WizardState::STEPS.'/'.$key.'.log'])))
                : ['key' => $key, 'state' => 'running', 'message' => ''];
        }

        if (! $context->force && $step->isComplete($context)) {
            return $this->record($state, $context, $key, StepResult::skipped(__('Already done')));
        }

        StateFile::write($started, (string) Date::now()->getTimestamp());

        if (! $this->launcher->start($basePath, $key, $resultFile, $this->stepFile($basePath, $key, 'log'), $this->stepFile($basePath, $key, 'exit'))) {
            return $this->record($state, $context, $key, StepResult::manual($this->fallbackCommand($key), __('The step could not start in the background.')));
        }

        return ['key' => $key, 'state' => 'running', 'message' => ''];
    }

    /** @return array{key: string, state: 'done', message: string} */
    private function record(WizardState $state, SetupContext $context, string $key, StepResult $result): array
    {
        $state->results[$key] = $result;
        $state->save($context->basePath);

        return ['key' => $key, 'state' => 'done', 'message' => $result->message];
    }

    /** A step's file in the steps directory; a key that is not a step key (a path, say) is refused, never resolved. */
    private function stepFile(string $basePath, string $key, string $extension): string
    {
        if (preg_match('/^[a-z][a-z-]*$/', $key) !== 1) {
            throw new InvalidArgumentException('Setup: not a step key.');
        }

        return rtrim($basePath, '/').'/'.WizardState::STEPS.'/'.$key.'.'.$extension;
    }

    private function fallbackCommand(string $key): string
    {
        return $key === 'frontend' ? 'npm ci && npm run build' : 'php artisan skv:install';
    }

    /**
     * The host to pin for $requestHost (host[:port]) and, when it is refused, why and where to go instead
     * (SetupHostGuard::resolve()); the page and begin() show only the problem.
     *
     * @return array{host: ?string, problem: ?string, suggestion: ?string}
     */
    private function host(string $requestHost): array
    {
        return SetupHostGuard::resolve($requestHost, $this->toolchain, app(SetupContext::class)->folderName());
    }

    /** @return array<string, mixed> */
    private function installed(SetupContext $context): array
    {
        try {
            return new InstallPreflight($context)->installedAnswers();
        } catch (JsonException) {
            return [];
        }
    }
}
