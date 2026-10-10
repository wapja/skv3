<?php

declare(strict_types=1);

namespace App\Tenancy\Concerns;

use App\Models\Organisation;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use App\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant models (§4.1): scoped to the current organisation and filled with its id on create.
 * Every model using this trait is listed in config('tenancy.tenant_models').
 */
trait BelongsToOrganisation
{
    public static function bootBelongsToOrganisation(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $model): void {
            if ($model->getAttribute('organisation_id') === null) {
                $model->setAttribute('organisation_id', app(CurrentTenant::class)->current()?->getKey());
            }

            if ($model->getAttribute('organisation_id') === null && ! $model->mayExistWithoutOrganisation()) {
                throw TenantNotResolvedException::forCreating($model::class);
            }
        });
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** Central rows (organisation_id NULL) are refused unless the model allows them. */
    public function mayExistWithoutOrganisation(): bool
    {
        return false;
    }
}
