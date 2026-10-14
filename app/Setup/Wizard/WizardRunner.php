<?php

declare(strict_types=1);

namespace App\Setup\Wizard;

use App\Setup\Answers;
use App\Setup\Detector;
use App\Setup\InstallPreflight;
use App\Setup\SetupContext;
use App\Setup\SetupHost;
use App\Setup\SetupRunner;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use App\Setup\Steps\MarkerStep;
use App\Setup\Support\HerdTld;
use App\Setup\Support\SafeMessage;
use App\Setup\Support\StateFile;
use App\Setup\Support\Toolchain;
use App\Tenancy\Facades\Tenant;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Drives the setup steps for the browser wizard (§5.3.4, RL-4): one step per request, the long ones detached through
 * the launcher, every result kept in the wizard state, every call inside Tenant::bypass() (strict mode: the doctor
 * and the seeders read tenant models). Steps 9–11 follow in finish() and the Herd stage (RL-6).
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

    /** @return array{answers: Answers, host: ?string, suggestion: string} the form's defaults, the pinned host (null: refused) and the URL to suggest */
    public function defaults(string $requestHost): array
    {
        [$host, $suggestion] = $this->host($requestHost);
        $context = app(SetupContext::class);

        return [
            'answers' => Answers::fromArray([], $this->detector->detect($host ?? 'localhost'), $context->folderName(), $this->installed($context)),
            'host' => $host,
            'suggestion' => $suggestion,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function begin(array $input, string $requestHost): WizardState
    {
        // A second tab or browser never resets a running setup: its answers and results stay (§5.3.4).
        if ($this->state() instanceof WizardState) {
            throw ValidationException::withMessages(['host' => __('Setup is already running.')]);
        }

        [$host, $suggestion] = $this->host($requestHost);

        if ($host === null) {
            throw ValidationException::withMessages(['host' => __('Open the setup on :url.', ['url' => $suggestion])]);
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

        return Tenant::bypass(function (): ?array {
            $state = $this->state() ?? throw new RuntimeException(__('There is no setup in progress.'));
            $basePath = app(SetupContext::class)->basePath;
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
        });
    }

    /** Forgets a step's result, so the next advance() runs it again (the page's "Re-check"). */
    public function recheck(string $key): void
    {
        $state = $this->state();

        if (! $state instanceof WizardState || ! in_array($key, self::PAGE_STEPS, true)) {
            return;
        }

        unset($state->results[$key]);
        $basePath = app(SetupContext::class)->basePath;

        foreach (['json', 'log', 'exit', 'started'] as $extension) {
            @unlink($this->stepFile($basePath, $key, $extension));
        }

        $state->save($basePath);
    }

    /** Step 9 in this request, then step 10 detached (RL-6); returns the secret the page polls with. */
    public function finish(): string
    {
        return Tenant::bypass(function (): string {
            $state = $this->state() ?? throw new RuntimeException(__('There is no setup in progress.'));

            if ($this->nextStep($state) instanceof SetupStep) {
                throw new RuntimeException(__('Finish the earlier steps first.'));
            }

            $basePath = app(SetupContext::class)->basePath;
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
        });
    }

    /**
     * Ends the Herd stage without a secured site (OD-2, RL-6): the Herd step could not start, or the user continued
     * without HTTPS. The HTTPS consequence applies, and the marker is rewritten with the manual `herd secure`.
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

    /** @return array{0: ?string, 1: string} the pinned host or null, and the address to suggest */
    private function host(string $requestHost): array
    {
        $folder = app(SetupContext::class)->folderName();
        $tld = HerdTld::read($this->toolchain);

        return [SetupHost::forWizard($requestHost, $tld, $folder), 'http://'.($tld === null ? 'localhost' : Str::lower($folder).'.'.$tld)];
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
