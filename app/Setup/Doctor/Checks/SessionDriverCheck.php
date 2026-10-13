<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;

/** §5.4: sessions live in the database, so sign-outs and "sign out other devices" reach every session. */
final readonly class SessionDriverCheck implements Check
{
    public function key(): string
    {
        return 'session-driver';
    }

    public function label(): string
    {
        return __('Session driver');
    }

    public function run(DoctorContext $context): CheckResult
    {
        $driver = (string) config('session.driver');

        if ($driver === 'database') {
            return CheckResult::pass(__('Sessions are stored in the database'));
        }

        return CheckResult::fail(__('The session driver is :driver', ['driver' => $driver]), __('Set SESSION_DRIVER=database in your .env'));
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
