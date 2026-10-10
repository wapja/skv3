<?php

declare(strict_types=1);

namespace App\Tenancy\Events;

use App\Models\Organisation;

final readonly class TenantResolved
{
    public function __construct(public Organisation $organisation) {}
}
