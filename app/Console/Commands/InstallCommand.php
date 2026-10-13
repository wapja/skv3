<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Detector;
use App\Setup\SetupContext;
use App\Setup\SetupRunner;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use App\Setup\Summary;
use App\Setup\Support\Bootstrap;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

use function Laravel\Prompts\form;
use function Laravel\Prompts\password;
use function Laravel\Prompts\task;
use function Laravel\Prompts\text;

/**
 * Sets the kit up from the terminal (§5.3.3, RL-6): asks the §5.3.2 questions (or takes --answers), validates them
 * before anything is written, runs the bootstrap as step 0 and then every setup step in order, and prints the
 * summary. A step that throws stops the run without writing the marker, so a re-run resumes at that step.
 */
#[Description('Set up the kit: environment, database, Herd, frontend, Claude and the summary (§5.3)')]
#[Signature('skv:install {--answers= : JSON answers (§5.3.2)} {--force : Re-run complete steps}')]
final class InstallCommand extends Command
{
    public function handle(Detector $detector, SetupRunner $runner): int
    {
        $basePath = app(SetupContext::class)->basePath;
        $folder = basename($basePath);
        $detection = $detector->detect();

        try {
            $answers = Answers::fromArray($this->rawAnswers($detection, $folder), $detection, $folder);
        } catch (JsonException) {
            $this->components->error(__('The --answers value is not valid JSON.'));

            return self::FAILURE;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->components->error($message);
                }
            }

            return self::FAILURE;
        }

        foreach (app(Bootstrap::class)->run() as $action) {
            $this->components->twoColumnDetail($action, __('done'));
        }

        $context = new SetupContext($basePath, $answers, $detection, (bool) $this->option('force'));
        $current = null;

        try {
            $runner->run($context, $this->around($current));
        } catch (Throwable $exception) {
            $this->components->error(__('Setup stopped at :step: :message', [
                'step' => $current instanceof SetupStep ? $current->label() : '',
                'message' => $this->messageOf($exception),
            ]));

            return self::FAILURE;
        }

        $this->printSummary(Summary::build($context));

        return self::SUCCESS;
    }

    /**
     * The raw answers: --answers, else the interactive form, else none (every default applies).
     *
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function rawAnswers(Detection $detection, string $folder): array
    {
        $json = $this->option('answers');

        if (is_string($json)) {
            $input = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($input)) {
                throw new JsonException('The answers are not a JSON object.');
            }

            /** @var array<string, mixed> $input */
            return $input;
        }

        return $this->input->isInteractive() ? $this->askQuestions(Answers::defaults($detection, $folder)) : [];
    }

    /**
     * The §5.3.2 questions in order, pre-filled with the defaults; the super-admin login only for start_with=super_admin.
     *
     * @return array<string, mixed>
     */
    private function askQuestions(Answers $defaults): array
    {
        /** @var array<string, mixed> $responses */
        $responses = form()
            ->text(__('App name'), default: $defaults->appName, required: true, name: 'app_name')
            ->select(__('Database'), ['pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite'], default: $defaults->database, name: 'database')
            ->select(__('Start with'), ['demo' => __('Demo data'), 'super_admin' => __('My own super admin')], default: $defaults->startWith, name: 'start_with')
            ->addIf(fn (array $responses): bool => $responses['start_with'] === 'super_admin', fn (): string => text(__('Super admin email'), required: true), 'super_admin_email')
            ->addIf(fn (array $responses): bool => $responses['start_with'] === 'super_admin', fn (): string => password(__('Super admin password'), required: true), 'super_admin_password')
            ->multiselect(__('Modules'), ['passkeys' => __('Passkeys'), 'backups' => __('Backups'), 'impersonation' => __('Impersonation')], default: $defaults->modules, name: 'modules')
            ->confirm(__('Use HTTPS'), default: $defaults->https, name: 'https')
            ->confirm(__('Install Playwright'), default: $defaults->playwright, name: 'playwright')
            ->confirm(__('Set up Claude Code'), default: $defaults->claude, name: 'claude')
            ->submit();

        return $responses;
    }

    /**
     * Wraps each step: a task() spinner when interactive, else one line with the step's status. $current tracks the
     * running step, so a step that throws can be named.
     *
     * @return Closure(SetupStep, Closure(): StepResult): StepResult
     */
    private function around(?SetupStep &$current): Closure
    {
        if ($this->input->isInteractive()) {
            /** @param  Closure(): StepResult  $execute */
            return function (SetupStep $step, Closure $execute) use (&$current): StepResult {
                $current = $step;

                return task($step->label(), fn (): StepResult => $execute());
            };
        }

        /** @param  Closure(): StepResult  $execute */
        return function (SetupStep $step, Closure $execute) use (&$current): StepResult {
            $current = $step;
            $result = $execute();
            $this->components->twoColumnDetail(OutputFormatter::escape($step->label()), $result->status);

            return $result;
        };
    }

    /** A query error names the driver's message only: its own message carries the bindings, such as a password hash. */
    private function messageOf(Throwable $exception): string
    {
        $message = $exception instanceof QueryException && $exception->getPrevious() instanceof Throwable
            ? $exception->getPrevious()->getMessage()
            : $exception->getMessage();

        return OutputFormatter::escape($message);
    }

    /** @param  array{urls: list<string>, logins: list<array{email: string, password: ?string, url: string}>, manual: list<array{step: string, command: string, reason: string}>, notes: list<string>}  $summary */
    private function printSummary(array $summary): void
    {
        $this->components->info(__('Summary'));
        $this->components->bulletList(array_map(OutputFormatter::escape(...), $summary['urls']));

        $this->table(
            [__('Email'), __('Password'), __('Address')],
            array_map(fn (array $login): array => [$login['email'], $login['password'] ?? '—', $login['url']], $summary['logins']),
        );

        if ($summary['manual'] !== []) {
            $this->components->warn(__('Run these commands yourself:'));

            foreach ($summary['manual'] as $manual) {
                $this->components->twoColumnDetail(OutputFormatter::escape($manual['command']), OutputFormatter::escape($manual['reason']));
            }
        }

        if ($summary['notes'] !== []) {
            $this->newLine();
            $this->components->bulletList(array_map(OutputFormatter::escape(...), $summary['notes']));
        }
    }
}
