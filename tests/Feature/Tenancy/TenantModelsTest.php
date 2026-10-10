<?php

declare(strict_types=1);

/** covers §6.2, §7.2 */

use App\Tenancy\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('registers every model with an organisation_id as a soft-deleting tenant model', function (): void {
    /** @var list<string> $registered */
    $registered = config('tenancy.tenant_models');

    $withOrganisationId = collect(File::allFiles(app_path('Models')))
        ->map(fn (SplFileInfo $file): string => 'App\\Models\\'.Str::of($file->getRelativePathname())->beforeLast('.php')->replace('/', '\\'))
        ->filter(fn (string $class): bool => is_subclass_of($class, Model::class) && ! new ReflectionClass($class)->isAbstract())
        ->filter(function (string $class): bool {
            /** @var Model $model */
            $model = app($class);

            return Schema::hasColumn($model->getTable(), 'organisation_id');
        })
        ->values();

    expect($withOrganisationId)->not->toBeEmpty();

    foreach ($withOrganisationId as $class) {
        expect($registered)->toContain($class)
            ->and(class_uses_recursive($class))->toContain(BelongsToOrganisation::class, SoftDeletes::class);
    }

    foreach ($registered as $class) {
        expect(class_uses_recursive($class))->toContain(BelongsToOrganisation::class, SoftDeletes::class);
    }
});
