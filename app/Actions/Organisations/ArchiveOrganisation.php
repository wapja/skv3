<?php

declare(strict_types=1);

namespace App\Actions\Organisations;

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Auth\Sessions;
use App\Tenancy\HostScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Archives (soft-deletes) an organisation in one transaction (§4.3, DEC-26 (10)): the observer cascades the
 * soft delete to the organisation's tenant rows and never archives a promoted super admin; the cascaded users
 * lose their sessions and remember tokens, so a later restore revives neither (RL-28); organisation.archived is
 * recorded. Super admins only (always checked).
 */
final readonly class ArchiveOrganisation
{
    public function __construct(private RecordActivity $recordActivity) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Organisation $organisation): void
    {
        Gate::forUser($actor)->authorize('delete', $organisation);

        DB::transaction(function () use ($actor, $organisation): void {
            $locked = HostScope::lockForWrite($organisation);

            // The users the cascade is about to archive, locked in id order (RL-27).
            $userIds = array_values(DB::table('users')
                ->where('organisation_id', $locked->id)
                ->where('is_super_admin', false)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all());

            $locked->delete();
            Sessions::endFor($userIds);

            $this->recordActivity->handle(ActivityEvent::OrganisationArchived, $locked, [
                'name' => $locked->name,
                'slug' => $locked->slug,
                'users' => count($userIds),
            ], causer: $actor);
        });
    }
}
