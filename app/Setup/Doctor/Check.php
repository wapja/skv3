<?php

declare(strict_types=1);

namespace App\Setup\Doctor;

/**
 * One doctor check (§5.4, RL-11). A check decides from the detection and the marker answers whether it applies and
 * returns `skipped` otherwise. The M7 System page and `/health-check` reuse the checks.
 */
interface Check
{
    /** A stable id, used as the key of Doctor::run()'s results and in `--json`. */
    public function key(): string;

    /** The translated label shown in the report. */
    public function label(): string;

    public function run(DoctorContext $context): CheckResult;

    /** A safe repair (RL-11), or null when the check has none; returns the re-run result. */
    public function fix(DoctorContext $context): ?CheckResult;
}
