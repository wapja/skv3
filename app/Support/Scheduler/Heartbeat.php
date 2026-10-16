<?php

declare(strict_types=1);

namespace App\Support\Scheduler;

use App\Support\Health\OpsCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * The scheduler's heartbeat (RL-8): the schedule stores the time every minute on the ops store (OpsCache), and the
 * System page shows it, so a missing cron entry is visible.
 */
final readonly class Heartbeat
{
    public const string CACHE_KEY = 'scheduler:heartbeat';

    public function beat(): void
    {
        OpsCache::store()->forever(self::CACHE_KEY, Date::now()->toIso8601String());
    }

    public function last(): ?CarbonImmutable
    {
        $beat = OpsCache::store()->get(self::CACHE_KEY);

        return is_string($beat) ? CarbonImmutable::parse($beat) : null;
    }
}
