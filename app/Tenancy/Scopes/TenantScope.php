<?php

declare(strict_types=1);

namespace App\Tenancy\Scopes;

use App\Tenancy\CentralContext;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** @implements Scope<Model> */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenancy = app(CurrentTenant::class);

        if ($tenancy->isBypassed()) {
            return;
        }

        $organisation = $tenancy->current();

        if ($organisation !== null) {
            $builder->where($model->qualifyColumn('organisation_id'), $organisation->getKey());

            return;
        }

        if (config('tenancy.strict') === true && ! app(CentralContext::class)->active()) {
            throw TenantNotResolvedException::forQuery($model::class);
        }
    }
}
