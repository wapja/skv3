<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CheckStatus;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\Doctor;
use App\Setup\Doctor\DoctorContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Checks the installation (§5.4, RL-11): one line per check with its status, its message and, when it does not
 * pass, the fix. `--fix` performs the safe repairs; `--json` prints the machine report. Exits 1 unless green.
 */
#[Description('Check the installation and show how to fix what is wrong (§5.4)')]
#[Signature('skv:doctor {--fix : Perform the safe repairs} {--json : Print the report as JSON}')]
final class DoctorCommand extends Command
{
    public function handle(Doctor $doctor, DoctorContext $context): int
    {
        $results = $doctor->run($context, fix: (bool) $this->option('fix'));
        $green = Doctor::isGreen($results);

        if ((bool) $this->option('json')) {
            $this->output->writeln(json_encode([
                'checks' => array_map(fn (string $key, CheckResult $result): array => [
                    'key' => $key,
                    'status' => $result->status->value,
                    'message' => $result->message,
                    'fix' => $result->fix,
                ], array_keys($results), $results),
                'green' => $green,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return $green ? self::SUCCESS : self::FAILURE;
        }

        $labels = [];

        foreach ($doctor->checks() as $check) {
            $labels[$check->key()] = $check->label();
        }

        foreach ($results as $key => $result) {
            $this->report($labels[$key] ?? $key, $result);
        }

        $failed = count(array_filter($results, fn (CheckResult $result): bool => $result->status === CheckStatus::Fail));

        if ($green) {
            $this->components->info(__('All checks pass.'));
        } else {
            $this->components->error(__(':count checks failed.', ['count' => $failed]));
        }

        return $green ? self::SUCCESS : self::FAILURE;
    }

    private function report(string $label, CheckResult $result): void
    {
        $status = match ($result->status) {
            CheckStatus::Pass => '<fg=green;options=bold>'.__('PASS').'</>',
            CheckStatus::Warn => '<fg=yellow;options=bold>'.__('WARN').'</>',
            CheckStatus::Fail => '<fg=red;options=bold>'.__('FAIL').'</>',
            CheckStatus::Skipped => '<fg=gray>'.__('SKIPPED').'</>',
        };

        $this->components->twoColumnDetail(OutputFormatter::escape($label), $status);
        $this->line('  <fg=gray>'.OutputFormatter::escape($result->message).'</>');

        if ($result->status !== CheckStatus::Pass && $result->fix !== null) {
            foreach (explode("\n", $result->fix) as $line) {
                $this->line('  → '.OutputFormatter::escape($line));
            }
        }
    }
}
