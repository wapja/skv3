<?php

declare(strict_types=1);

use App\Setup\Answers;
use App\Setup\Http\EnsureSetupAccess;
use App\Setup\Marker;
use App\Setup\SetupContext;
use App\Setup\Support\SafeMessage;
use App\Setup\Wizard\WizardRunner;
use App\Setup\Wizard\WizardState;
use App\Tenancy\Hosts;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * §5.3.4: the browser wizard. Questions, then steps 1–8 one request at a time (the long ones polled), then step 9 and
 * the Herd stage, which runs without Livewire because the gate closes with the marker (RL-6).
 */
new #[Layout('layouts::setup')] class extends Component
{
    /** The Answers keys and the form properties that show their errors; anything about the request itself goes to `host`. */
    private const array ERROR_FIELDS = [
        'app_name' => 'appName', 'database' => 'database', 'start_with' => 'startWith', 'super_admin_email' => 'superAdminEmail',
        'super_admin_password' => 'superAdminPassword', 'modules' => 'modules', 'https' => 'https', 'playwright' => 'playwright',
        'claude' => 'claude', 'answers' => 'host', 'host' => 'host',
    ];

    public string $appName = '';

    public string $database = 'sqlite';

    public string $startWith = 'demo';

    public string $superAdminEmail = '';

    public string $superAdminPassword = '';

    /** @var list<string> */
    public array $modules = [];

    public bool $https = true;

    public bool $playwright = false;

    public bool $claude = false;

    /** questions | running | review | herd | stranded (step 9 is done, the Herd stage could not begin: g3-32) */
    #[Locked]
    public string $stage = 'questions';

    /**
     * Whether the user picked "Start with" (g4-04). Only then does the page send start_with, so begin() can refuse the
     * built-in demo default next to an own super admin (DEC-30 (29)); a refusal counts as the pick for the next try.
     */
    #[Locked]
    public bool $startWithChosen = false;

    /** The login page a stranded setup offers instead of "Try again", which would meet the closed gate (g3-32). */
    #[Locked]
    public ?string $loginUrl = null;

    #[Locked]
    public ?string $hostProblem = null;

    #[Locked]
    public ?string $failure = null;

    #[Locked]
    public ?string $herdSecret = null;

    public function mount(WizardRunner $runner): void
    {
        $this->authorise();

        if ($runner->state() instanceof WizardState) {
            $this->stage = 'running';

            return;
        }

        // The port too: without Herd, the suggestion is localhost on the port this request came in on (g3-34).
        $defaults = $runner->defaults(request()->getHttpHost());
        $this->hostProblem = $defaults['problem'];

        $this->fill($this->formOf($defaults['answers']));
    }

    public function updatedStartWith(): void
    {
        $this->startWithChosen = true;
    }

    public function start(WizardRunner $runner): void
    {
        $this->authorise();

        if ($this->stage !== 'questions') {
            return;
        }

        $input = [
            'app_name' => $this->appName,
            'database' => $this->database,
            'super_admin_email' => $this->startWith === 'super_admin' ? $this->superAdminEmail : null,
            'super_admin_password' => $this->startWith === 'super_admin' && $this->superAdminPassword !== '' ? $this->superAdminPassword : null,
            'modules' => $this->modules,
            'https' => $this->https,
            'playwright' => $this->playwright,
            'claude' => $this->claude,
        ];

        // The preselected "Demo data" is no choice: without start_with, begin() applies DEC-30 (29) (g4-04).
        if ($this->startWithChosen || $this->startWith !== 'demo') {
            $input['start_with'] = $this->startWith;
        }

        try {
            $runner->begin($input, request()->getHttpHost());
        } catch (ValidationException $exception) {
            $this->startWithChosen = $this->startWithChosen || array_key_exists('start_with', $exception->errors());

            throw ValidationException::withMessages($this->formErrors($exception->errors()));
        } catch (Throwable $exception) {
            // A lock or state file that cannot be written is a message on the form, not a 500 (g3-30).
            $this->addError('host', SafeMessage::of($exception));

            return;
        } finally {
            $this->superAdminPassword = '';
        }

        $this->stage = 'running';
    }

    public function advance(WizardRunner $runner): void
    {
        $this->authorise();

        if ($this->stage !== 'running') {
            return;
        }

        try {
            $outcome = $runner->advance();
        } catch (Throwable $exception) {
            $this->failure = SafeMessage::of($exception);

            return;
        }

        if ($outcome === null) {
            // A manual command stays on screen with its Re-check until the user finishes: step 9 closes the gate (§5.3.1).
            if (collect($runner->progress())->contains('status', 'manual')) {
                $this->stage = 'review';

                return;
            }

            $this->finishSetup($runner);

            return;
        }

        $this->failure = $outcome['state'] === 'failed' ? $outcome['message'] : null;
    }

    /** "Finish setup" on the review: step 9, then the Herd stage. */
    public function complete(WizardRunner $runner): void
    {
        $this->authorise();

        if ($this->stage === 'review') {
            $this->finishSetup($runner);
        }
    }

    public function retry(WizardRunner $runner): void
    {
        $this->authorise();
        $this->failure = null;
        $this->stage === 'review' ? $this->complete($runner) : $this->advance($runner);
    }

    public function recheck(WizardRunner $runner, string $key): void
    {
        $this->authorise();

        if (! in_array($this->stage, ['running', 'review'], true)) {
            return;
        }

        try {
            $runner->recheck($key);
        } catch (Throwable $exception) {
            $this->failure = SafeMessage::of($exception);

            return;
        }

        $this->failure = null;
        $this->stage = 'running';
    }

    /** @return list<array{key: string, label: string, status: ?string, message: string, command: ?string, long: bool}> */
    #[Computed]
    public function steps(): array
    {
        return app(WizardRunner::class)->progress();
    }

    private function finishSetup(WizardRunner $runner): void
    {
        try {
            $this->herdSecret = $runner->finish();
            $this->stage = 'herd';
        } catch (Throwable $exception) {
            $this->failure = SafeMessage::of($exception);

            // Step 9 wrote the marker and closed the gate: "Try again" would answer 404, so the login page is offered.
            if (Marker::exists(app(SetupContext::class))) {
                $this->stage = 'stranded';
                $this->loginUrl = Hosts::url(Hosts::apex(), '/login');
            }
        }
    }

    /** The gate again, also on every update (the persistent middleware covers routing; this covers the action). */
    private function authorise(): void
    {
        abort_unless(EnsureSetupAccess::authorised(request()), 404);
    }

    /** @return array<string, mixed> */
    private function formOf(Answers $answers): array
    {
        return [
            'appName' => $answers->appName,
            'database' => $answers->database,
            'startWith' => $answers->startWith,
            'superAdminEmail' => (string) $answers->superAdminEmail,
            'modules' => $answers->modules,
            'https' => $answers->https,
            'playwright' => $answers->playwright,
            'claude' => $answers->claude,
        ];
    }

    /**
     * The Answers errors under the form's property names, so each field shows its own; `modules.0` maps through its
     * prefix, and an unknown key goes to `host`.
     *
     * @param  array<string, array<int, string>>  $errors
     * @return array<string, list<string>>
     */
    private function formErrors(array $errors): array
    {
        $mapped = [];

        foreach ($errors as $key => $messages) {
            $field = self::ERROR_FIELDS[Str::before($key, '.')] ?? 'host';
            $mapped[$field] = [...$mapped[$field] ?? [], ...array_values($messages)];
        }

        return $mapped;
    }
};
