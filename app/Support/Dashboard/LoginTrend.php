<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Tenancy\HostScope;
use Carbon\CarbonInterface;

/** Sign-ins per day on this host's scope, for the dashboard chart (§6.5, RL-15). Grouped in PHP: portable SQL. */
final class LoginTrend
{
    /** @return list<array{date: string, logins: int}> */
    public static function lastDays(int $days = 14): array
    {
        // Immutable whatever Date::use() chose: addDays() below must not move the start itself (G5-08).
        $start = now()->toImmutable()->startOfDay()->subDays($days - 1);

        /** @var list<CarbonInterface> $moments */
        $moments = HostScope::read(fn (): array => Activity::query()
            ->where('event', ActivityEvent::Login->value)
            ->where('created_at', '>=', $start)
            ->pluck('created_at')
            ->all());

        $counts = [];

        foreach ($moments as $moment) {
            $day = $moment->toDateString();
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }

        $trend = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $day = $start->addDays($offset)->toDateString();
            $trend[] = ['date' => $day, 'logins' => $counts[$day] ?? 0];
        }

        return $trend;
    }
}
