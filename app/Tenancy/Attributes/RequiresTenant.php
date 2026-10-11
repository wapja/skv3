<?php

declare(strict_types=1);

namespace App\Tenancy\Attributes;

use App\Tenancy\Facades\Tenant;
use Livewire\Features\SupportAttributes\Attribute;

/**
 * Marks a Livewire action that works on a tenant host only (§6.2, DEC-26 (7)). On the apex or the admin host
 * it answers 404 before the method runs, also for a forged /livewire/update; Livewire calls this hook for
 * method attributes (SupportAttributes::call(), like its own #[Authorize]).
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class RequiresTenant extends Attribute
{
    /** @param  array<int|string, mixed>  $parameters */
    public function call(array $parameters): void
    {
        abort_unless(Tenant::check(), 404);
    }
}
