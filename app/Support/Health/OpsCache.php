<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The ops layer's cache store (RL-11, DEC-32 CD-5): the health report, the /health-check throttle, the scheduler
 * heartbeat and the System page's limiter. Not the default database store, so /health-check still answers while the
 * database is down. The only place that reads config('skv.ops_cache_store').
 */
final readonly class OpsCache
{
    public static function store(): Repository
    {
        return Cache::store((string) config('skv.ops_cache_store', 'file'));
    }
}
