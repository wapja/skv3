<?php

declare(strict_types=1);

namespace App\Actions\Organisations;

use App\Actions\Audit\RecordActivity;
use App\Concerns\OrganisationValidationRules;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\HostScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Edits an organisation (§4.3): name and description for its organisation admins and super admins; the slug
 * for super admins only (OD-3 = B), because a new slug moves the organisation to a new host. The row is locked
 * and re-checked, so an archived or another tenant's organisation is refused (RK-I11). The old slug is free at
 * once. Records organisation.updated with the changed fields only.
 */
final readonly class UpdateOrganisation
{
    use OrganisationValidationRules;

    public function __construct(private RecordActivity $recordActivity) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Organisation $organisation, string $name, string $slug, ?string $description): Organisation
    {
        Gate::forUser($actor)->authorize('update', $organisation);

        $name = trim($name);
        $description = $description === null || trim($description) === '' ? null : trim($description);

        return DB::transaction(function () use ($actor, $organisation, $name, $slug, $description): Organisation {
            $locked = HostScope::lockForWrite($organisation);

            if ($slug !== $locked->slug && ! $actor->isSuperAdmin()) {
                throw ValidationException::withMessages(['slug' => __('Only a platform administrator can change the address.')]);
            }

            Validator::make(
                ['name' => $name, 'slug' => $slug, 'description' => $description],
                [
                    'name' => $this->organisationNameRules($locked->id, $actor->isSuperAdmin()),
                    'slug' => $this->organisationSlugRules($locked->id, $actor->isSuperAdmin()),
                    'description' => $this->organisationDescriptionRules(),
                ],
            )->validate();

            $locked->fill(['name' => $name, 'slug' => $slug, 'description' => $description]);
            $changed = array_keys($locked->getDirty());

            if ($changed === []) {
                return $locked;
            }

            $old = [];
            $new = [];

            foreach ($changed as $field) {
                $old[$field] = $locked->getOriginal($field);
                $new[$field] = $locked->getAttribute($field);
            }

            $locked->save();

            $this->recordActivity->handle(
                ActivityEvent::OrganisationUpdated,
                $locked,
                changes: ['old' => $old, 'attributes' => $new],
                causer: $actor,
            );

            return $locked;
        });
    }
}
