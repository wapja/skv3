<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Authorization\PermissionsTeam;
use App\Tenancy\Facades\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

final class RecordTenantJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::put('job.ran', true);
        Cache::put('job.tenant', Tenant::current()?->id);
        Cache::put('job.team', PermissionsTeam::current());
    }
}
