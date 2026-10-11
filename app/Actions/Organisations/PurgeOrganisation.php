<?php

declare(strict_types=1);

namespace App\Actions\Organisations;

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Auth\Sessions;
use App\Tenancy\Facades\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Purges an archived organisation for good (§4.3): super admins only, only after its slug is typed, in one
 * transaction. Promoted super admins are detached, not deleted (DEC-26 (9)); then invitations, the users'
 * sessions and reset tokens, the users including deleted ones (passkeys and handoff tokens cascade), the
 * team's role assignments and roles, and the organisation are deleted. Activity rows are kept (ORG-12).
 *
 * Every bulk delete is a query-builder statement with an explicit organisation or id filter: Eloquent's builder
 * forceDelete() runs without global scopes, so on a tenant model it would delete every organisation's rows.
 */
final readonly class PurgeOrganisation
{
    public function __construct(
        private RecordActivity $recordActivity,
        private PermissionRegistrar $permissionRegistrar,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Organisation $organisation, string $confirmation): void
    {
        Gate::forUser($actor)->authorize('forceDelete', $organisation);

        if (Tenant::check()) {
            throw new AuthorizationException('Archived organisations are purged on a central host.');
        }

        DB::transaction(function () use ($actor, $organisation, $confirmation): void {
            $locked = Organisation::withTrashed()->lockForUpdate()->findOrFail($organisation->id);

            if (! $locked->trashed()) {
                throw ValidationException::withMessages(['organisation' => __('This organisation is not archived.')]);
            }

            if (trim($confirmation) !== $locked->slug) {
                throw ValidationException::withMessages(['confirmation' => __('Type :slug to confirm.', ['slug' => $locked->slug])]);
            }

            // Every user row of the organisation, deleted ones included, locked in id order (RL-27).
            $rows = DB::table('users')
                ->where('organisation_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'email', 'is_super_admin']);

            $detached = [];
            $users = [];

            foreach ($rows as $row) {
                if ((bool) $row->is_super_admin) {
                    $detached[] = (int) $row->id;
                } else {
                    $users[(int) $row->id] = (string) $row->email;
                }
            }

            if ($detached !== []) {
                DB::table('users')->whereIn('id', $detached)->update(['organisation_id' => null]);
            }

            DB::table('invitations')->where('organisation_id', $locked->id)->delete();
            $this->deleteSignInState($locked, $users);
            DB::table('users')->where('organisation_id', $locked->id)->delete();
            $this->deleteTeam($locked, array_keys($users));
            $locked->forceDelete();

            $this->recordActivity->handle(
                ActivityEvent::OrganisationPurged,
                $locked,
                [
                    'name' => $locked->name,
                    'slug' => $locked->slug,
                    'users' => count($users),
                    'detached_super_admins' => $detached,
                ],
                causer: $actor,
                organisation: $locked,
            );

            DB::afterCommit(fn (): bool => $this->permissionRegistrar->forgetCachedPermissions());
        });
    }

    /**
     * Sessions have no foreign key (C-16), so Sessions::endFor() deletes them. Reset tokens are keyed by address:
     * a deleted user's address may belong to a live account elsewhere by now, and that account keeps its token
     * (CV-13).
     *
     * @param  array<int, string>  $users  id => email
     */
    private function deleteSignInState(Organisation $organisation, array $users): void
    {
        if ($users === []) {
            return;
        }

        Sessions::endFor(array_keys($users));

        $addresses = array_values(array_unique($users));
        $heldElsewhere = DB::table('users')
            ->whereNull('deleted_at')
            ->whereIn('email', $addresses)
            ->where(function (Builder $query) use ($organisation): void {
                $query->whereNull('organisation_id')->orWhere('organisation_id', '!=', $organisation->id);
            })
            ->pluck('email')
            ->map(fn (mixed $email): string => (string) $email)
            ->all();

        $purgeable = array_values(array_diff($addresses, $heldElsewhere));

        if ($purgeable !== []) {
            DB::table('password_reset_tokens')->whereIn('email', $purgeable)->delete();
        }
    }

    /**
     * Role assignments carry team_id but no foreign key to organisations (spec §4.3); role permissions and any
     * remaining assignments of the team roles cascade when the roles go.
     *
     * @param  list<int>  $userIds
     */
    private function deleteTeam(Organisation $organisation, array $userIds): void
    {
        $morphClass = (new User)->getMorphClass();

        foreach (['model_has_roles', 'model_has_permissions'] as $table) {
            DB::table($table)->where('team_id', $organisation->id)->delete();

            if ($userIds !== []) {
                DB::table($table)->where('model_type', $morphClass)->whereIn('model_id', $userIds)->delete();
            }
        }

        DB::table('roles')->where('team_id', $organisation->id)->delete();
    }
}
