<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Soft-deleting an organisation soft-deletes its tenant rows with the same timestamp; restore
 * brings back only those rows; force delete does not cascade (purge is explicit, §4.1, §4.3).
 * Bulk updates without global scopes: no model events, no tenant scope.
 */
final class SoftDeleteCascade
{
    public function trash(Organisation $organisation): void
    {
        $deletedAt = $organisation->deleted_at;

        if ($deletedAt === null) {
            return;
        }

        foreach ($this->models() as $model) {
            $model::query()->withoutGlobalScopes()
                ->where('organisation_id', $organisation->id)
                ->whereNull('deleted_at')
                // A promoted super admin keeps its organisation_id; archiving that organisation must
                // never archive a super admin (it would bypass the last-super-admin guard).
                ->when($model === User::class, fn (Builder $query): Builder => $query->where('is_super_admin', false))
                ->update(['deleted_at' => $deletedAt]);
        }
    }

    /** Call from "restoring": deleted_at still holds the cascade timestamp there. */
    public function restore(Organisation $organisation): void
    {
        $deletedAt = $organisation->deleted_at;

        if ($deletedAt === null) {
            return;
        }

        foreach ($this->models() as $model) {
            $model::query()->withoutGlobalScopes()
                ->where('organisation_id', $organisation->id)
                ->where('deleted_at', $deletedAt)
                ->update(['deleted_at' => null]);
        }
    }

    /** @return list<class-string<Model>> */
    private function models(): array
    {
        /** @var list<class-string<Model>> $models */
        $models = config('tenancy.tenant_models', []);

        return $models;
    }
}
