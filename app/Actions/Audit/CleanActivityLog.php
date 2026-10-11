<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Models\Activity;
use App\Tenancy\Facades\Tenant;
use Spatie\Activitylog\Actions\CleanActivityLogAction;

/**
 * Retention for `activitylog:clean` (§4.8, R-3): removes old entries of every organisation for good,
 * archived (soft-deleted) ones included. The vendor action would only soft-delete them.
 * Registered as activitylog.actions.clean_log.
 */
final class CleanActivityLog extends CleanActivityLogAction
{
    protected function deleteOldActivities(string $cutOffDate, ?string $logName): int
    {
        return Tenant::bypass(function () use ($cutOffDate, $logName): int {
            $query = Activity::query()->withTrashed()->where('created_at', '<', $cutOffDate);

            if ($logName !== null) {
                $query->where('log_name', $logName);
            }

            return (int) $query->forceDelete();
        });
    }
}
