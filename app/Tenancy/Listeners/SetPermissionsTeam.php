<?php

declare(strict_types=1);

namespace App\Tenancy\Listeners;

use App\Authorization\PermissionsTeam;
use App\Tenancy\Events\TenantForgotten;
use App\Tenancy\Events\TenantResolved;

final class SetPermissionsTeam
{
    public function handle(TenantResolved|TenantForgotten $event): void
    {
        PermissionsTeam::set($event instanceof TenantResolved ? $event->organisation->id : null);
    }
}
