<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use App\Tenancy\Scopes\TenantScope;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Session, remember-me and password-broker lookups find users on every host (§4.1);
 * which host a user may use is decided by the membership middleware and the login rule.
 */
final class UnscopedUserProvider extends EloquentUserProvider
{
    /**
     * @param  Model|null  $model
     * @return Builder<Model>
     */
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->withoutGlobalScope(TenantScope::class);
    }
}
