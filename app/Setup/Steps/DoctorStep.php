<?php

declare(strict_types=1);

namespace App\Setup\Steps;

use App\Enums\CheckStatus;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\Doctor;
use App\Setup\Doctor\DoctorContext;
use App\Setup\SetupContext;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use App\Setup\Support\Toolchain;

/**
 * §5.3.3 step 8: runs the doctor (§5.4) on the project being set up, without repairs. Green is done; otherwise the
 * user gets `php artisan skv:doctor` to see what failed. It always runs, so a re-run reports the current state. The
 * checks that only steps 9 and 10 can make true are reported as skipped (RL-8, A6).
 */
final readonly class DoctorStep implements SetupStep
{
    /** The checks steps 9 and 10 make true: Herd serving and securing the site, and the token step 9 deletes (RL-8). */
    public const array DEFERRED = ['herd-site', 'herd-secured', 'setup-token'];

    private Toolchain $toolchain;

    public function __construct(?Toolchain $toolchain = null)
    {
        $this->toolchain = $toolchain ?? app(Toolchain::class);
    }

    public function key(): string
    {
        return 'doctor';
    }

    public function label(): string
    {
        return __('Doctor');
    }

    public function isComplete(SetupContext $context): bool
    {
        return false;
    }

    public function isLong(): bool
    {
        return false;
    }

    public function run(SetupContext $context): StepResult
    {
        $results = app(Doctor::class)->run(DoctorContext::fromSetup($context, $this->toolchain), deferred: self::DEFERRED);

        if (Doctor::isGreen($results)) {
            return StepResult::done();
        }

        $failed = count(array_filter($results, fn (CheckResult $result): bool => $result->status === CheckStatus::Fail));

        return StepResult::manual('php artisan skv:doctor', __(':count checks failed', ['count' => $failed]));
    }
}
