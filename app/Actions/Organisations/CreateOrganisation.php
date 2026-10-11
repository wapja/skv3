<?php

declare(strict_types=1);

namespace App\Actions\Organisations;

use App\Actions\Audit\RecordActivity;
use App\Actions\Invitations\InviteUser;
use App\Authorization\Role;
use App\Concerns\OrganisationValidationRules;
use App\Enums\ActivityEvent;
use App\Enums\Locale;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The organisation wizard's write (§4.3, RL-12): the organisation, its first admin as a pending user with the
 * organisation_admin template role and the invitation are created in one transaction, so a failing invitation
 * leaves nothing behind (ORG-14). No per-organisation admin role is ever created (DEC-21, W7). The invitation
 * mail is queued after the outermost commit and builds its link in the worker (OR-I2). Writes exactly
 * organisation.created, user.roles_changed and invitation.sent (OR-M10).
 */
final readonly class CreateOrganisation
{
    use OrganisationValidationRules;

    public function __construct(
        private InviteUser $inviteUser,
        private RecordActivity $recordActivity,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, string $name, string $slug, ?string $description, string $adminEmail, Locale $adminLocale): Organisation
    {
        Gate::forUser($actor)->authorize('create', Organisation::class);

        $name = trim($name);
        $description = $description === null || trim($description) === '' ? null : trim($description);

        Validator::make(
            ['name' => $name, 'slug' => $slug, 'description' => $description],
            [
                'name' => $this->organisationNameRules(revealArchived: $actor->isSuperAdmin()),
                'slug' => $this->organisationSlugRules(revealArchived: $actor->isSuperAdmin()),
                'description' => $this->organisationDescriptionRules(),
            ],
        )->validate();

        return DB::transaction(function () use ($actor, $name, $slug, $description, $adminEmail, $adminLocale): Organisation {
            $organisation = Organisation::query()->create([
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
            ]);

            $this->recordActivity->handle(
                ActivityEvent::OrganisationCreated,
                $organisation,
                ['name' => $organisation->name, 'slug' => $organisation->slug],
                causer: $actor,
            );

            // Errors keep InviteUser's keys (email, roles); a failure rolls the organisation back.
            $this->inviteUser->handle($actor, $organisation, $adminEmail, [Role::templateId()], $adminLocale);

            return $organisation;
        });
    }
}
