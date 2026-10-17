<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Authorization\Permission;
use App\Authorization\Role;
use App\Authorization\SyncPermissions;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Tenancy\Facades\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * The organisation and roles of the invite pages, users.invite and invitations.bulk (§4.5, §6.2): a tenant host
 * invites into its own organisation, a central host lets a super admin pick one (INV-17). The page declares
 * `public ?int $organisationId` and `public array $roles`.
 *
 * @phpstan-require-extends Component
 */
trait ChoosesInvitationOrganisation
{
    use ResolvesCurrentUser;

    /** Roles belong to one organisation, so a new pick clears them. */
    public function updatedOrganisationId(): void
    {
        $this->roles = [];
    }

    /** The pending list needs users.view; an inviter without it goes back to the dashboard instead of a 403. */
    #[Computed]
    public function returnRoute(): string
    {
        return $this->currentUser()->can(Permission::UsersView) ? 'invitations.pending' : 'dashboard';
    }

    /** @return array<int, string> Active organisations, for the central picker only. */
    #[Computed]
    public function organisationOptions(): array
    {
        if (Tenant::check()) {
            return [];
        }

        return Organisation::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])
            ->all();
    }

    /** @return array<int, string> The organisation_admin template and the team roles of the host's or the picked organisation (RK-I4). */
    #[Computed]
    public function roleOptions(): array
    {
        $current = Tenant::current();
        $teamId = $current instanceof Organisation ? $current->id : $this->pickedOrganisationId();

        if ($teamId === null) {
            return [];
        }

        return RoleModel::query()
            ->where('guard_name', SyncPermissions::GUARD)
            ->where(function (Builder $query) use ($teamId): void {
                $query->where('team_id', $teamId)
                    ->orWhere(fn (Builder $template): Builder => $template->whereNull('team_id')->where('name', Role::OrganisationAdmin->value));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'team_id'])
            ->mapWithKeys(fn (RoleModel $role): array => [
                (int) $role->id => $role->team_id === null ? Role::OrganisationAdmin->label() : (string) $role->name,
            ])
            ->all();
    }

    /** Tenant host: users.create and roles.assign in this organisation (OR-M7); central hosts: super admins only. */
    protected function authorizeInvitePage(): void
    {
        $organisation = Tenant::current();

        if ($organisation instanceof Organisation) {
            $this->authorize('create', [Invitation::class, $organisation]);
        } else {
            abort_unless($this->currentUser()->isSuperAdmin(), 403);
        }
    }

    /**
     * The host's organisation, or the central pick, authorised for inviting. Archived picks are passed on on
     * purpose: InviteUser refuses them under its own lock (RK-I11). Super admins pass through Gate::before
     * ('create' is not always checked).
     *
     * @throws ValidationException
     */
    protected function organisationToInvite(): Organisation
    {
        $current = Tenant::current();

        if ($current instanceof Organisation) {
            $organisation = $current;
        } else {
            $organisationId = $this->pickedOrganisationId();
            $organisation = ($organisationId === null ? null : Organisation::withTrashed()->find($organisationId))
                ?? throw ValidationException::withMessages(['organisationId' => __('Choose an organisation first.')]);
        }

        $this->authorize('create', [Invitation::class, $organisation]);

        return $organisation;
    }

    /** Livewire unsets a typed int property that receives an empty string, so read it null-safely. */
    protected function pickedOrganisationId(): ?int
    {
        return $this->organisationId ?? null;
    }
}
