<?php

declare(strict_types=1);

use App\Actions\Users\DeleteUser;
use App\Actions\Users\RestoreUser;
use App\Authorization\Role;
use App\Authorization\SyncPermissions;
use App\Authorization\UserAbilities;
use App\Concerns\ResolvesCurrentUser;
use App\Enums\UserStatus;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\HostScope;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role as RoleModel;

new #[Title('Users')] class extends Component
{
    use ResolvesCurrentUser;
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $role = '';

    /** Central hosts only; ignored on a tenant host (RK-I4). */
    #[Url]
    public string $organisation = '';

    #[Url]
    public string $view = 'active';

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingUserId = null;

    #[Locked]
    public string $deletingUserName = '';

    /**
     * The current page, read once per request; private properties are never dehydrated.
     *
     * @var LengthAwarePaginator<int, User>|null
     */
    private ?LengthAwarePaginator $usersPage = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    /**
     * A changed filter starts again on the first page (X4); another organisation has other roles.
     */
    public function updated(string $property): void
    {
        if ($property === 'organisation') {
            $this->role = '';
        }

        if (in_array($property, ['search', 'status', 'role', 'organisation', 'view'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Show the delete confirmation modal.
     */
    public function confirmDelete(int $userId): void
    {
        $target = $this->findUser($userId);
        $this->authorize('delete', $target);

        $this->deletingUserId = $target->id;
        $this->deletingUserName = $target->name;
        $this->showDeleteModal = true;
    }

    /**
     * Delete the user from the modal; DeleteUser keeps the lock-out guards (W2, W12).
     */
    public function delete(DeleteUser $deleteUser): void
    {
        if ($this->deletingUserId === null) {
            return;
        }

        $target = $this->findUser($this->deletingUserId);
        $this->authorize('delete', $target);

        try {
            $deleteUser->handle($this->currentUser(), $target);
        } finally {
            $this->closeDeleteModal();
        }

        Flux::toast(variant: 'success', text: __(':name was deleted.', ['name' => $target->name]));
    }

    /**
     * Restore a deleted user from the archived view; RestoreUser refuses taken addresses and archived organisations.
     */
    public function restore(int $userId, RestoreUser $restoreUser): void
    {
        $target = $this->findUser($userId, trashed: true);
        $this->authorize('restore', $target);

        $restoreUser->handle($this->currentUser(), $target);

        Flux::toast(variant: 'success', text: __(':name was restored.', ['name' => $target->name]));
    }

    /**
     * Close the delete confirmation modal.
     */
    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingUserId = null;
        $this->deletingUserName = '';
    }

    /**
     * The page of users for the current filters, read through the host scope (RL-8).
     *
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        if ($this->usersPage instanceof LengthAwarePaginator) {
            return $this->usersPage;
        }

        $search = trim($this->search);
        $status = UserStatus::tryFrom($this->status);
        $roleId = $this->pickedRoleId();
        $organisationId = $this->pickedOrganisationId();
        $archived = $this->view === 'archived';

        /** @var LengthAwarePaginator<int, User> $users */
        $users = HostScope::read(function () use ($search, $status, $roleId, $organisationId, $archived): LengthAwarePaginator {
            $query = $this->visibleUsers();

            if ($archived) {
                // Cancelled invitations leave soft-deleted pending users behind; they are not users to restore (C-9).
                $query->onlyTrashed()->where('status', '!=', UserStatus::PendingActivation->value);
            }

            if ($organisationId !== null) {
                $query->where('organisation_id', $organisationId);
            }

            if ($search !== '') {
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->whereLike('name', "%{$search}%", caseSensitive: false)
                        ->orWhereLike('email', "%{$search}%", caseSensitive: false);
                });
            }

            if ($status instanceof UserStatus) {
                $query->where('status', $status->value);
            }

            if ($roleId !== null) {
                // The role must be held in the user's own team (the template role is global, its assignment is not).
                $query->whereExists(function (QueryBuilder $assignment) use ($roleId): void {
                    $assignment->selectRaw('1')
                        ->from('model_has_roles')
                        ->whereColumn('model_has_roles.model_id', 'users.id')
                        ->whereColumn('model_has_roles.team_id', 'users.organisation_id')
                        ->where('model_has_roles.model_type', (new User)->getMorphClass())
                        ->where('model_has_roles.role_id', $roleId);
                });
            }

            return $query->orderBy('name')->orderBy('id')->paginate(25);
        });

        return $this->usersPage = $users;
    }

    /**
     * Role labels per user, from one pivot query for the page, each read in the user's own team (R-6, X1).
     *
     * @return array<int, list<string>>
     */
    #[Computed]
    public function rolesByUser(): array
    {
        $teams = [];

        foreach ($this->pageUsers()->items() as $user) {
            if ($user->organisation_id !== null) {
                $teams[$user->id] = $user->organisation_id;
            }
        }

        if ($teams === []) {
            return [];
        }

        $rows = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->whereIn('model_has_roles.model_id', array_keys($teams))
            ->orderBy('roles.name')
            ->get(['model_has_roles.model_id', 'model_has_roles.team_id', 'roles.name']);

        $roles = [];

        foreach ($rows as $row) {
            $userId = (int) $row->model_id;

            if ($row->team_id === null || (int) $row->team_id !== ($teams[$userId] ?? null)) {
                continue;
            }

            $name = (string) $row->name;
            $roles[$userId][] = Role::tryFrom($name)?->label() ?? $name;
        }

        return $roles;
    }

    /**
     * The controls each row may show, from one batch evaluation of the policy rules (RL-26, RK-I6).
     *
     * @return array<int, array{view: bool, update: bool, updateStatus: bool, delete: bool, restore: bool, impersonate: bool}>
     */
    #[Computed]
    public function rowAbilities(): array
    {
        $users = new EloquentCollection($this->pageUsers()->items());
        $actor = $this->currentUser();

        /** @var array<int, array{view: bool, update: bool, updateStatus: bool, delete: bool, restore: bool, impersonate: bool}> $abilities */
        $abilities = HostScope::read(fn (): array => app(UserAbilities::class)->forPage($actor, $users));

        return $abilities;
    }

    /**
     * The organisation_admin template, then the roles of the shown team: the tenant on a tenant host, the
     * picked organisation centrally (RK-I4).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function roleOptions(): array
    {
        $teamId = $this->pickedOrganisationId();

        return RoleModel::query()
            ->where('guard_name', SyncPermissions::GUARD)
            ->where(function (Builder $query) use ($teamId): void {
                $query->where(function (Builder $template): void {
                    $template->whereNull('team_id')->where('name', Role::OrganisationAdmin->value);
                });

                if ($teamId !== null) {
                    $query->orWhere('team_id', $teamId);
                }
            })
            ->get()
            ->sortBy(fn (RoleModel $role): string => ($role->getAttribute('team_id') === null ? '0 ' : '1 ').$role->name)
            ->mapWithKeys(fn (RoleModel $role): array => [(int) $role->getKey() => Role::tryFrom($role->name)?->label() ?? $role->name])
            ->all();
    }

    /**
     * Every organisation for the filter on central hosts; nothing on a tenant host.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function organisationOptions(): array
    {
        if (HostScope::organisationId() !== null) {
            return [];
        }

        return Organisation::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Organisation $organisation): array => [$organisation->id => $organisation->name])
            ->all();
    }

    /**
     * Names of the page's organisations for the central organisation column, archived ones included.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function organisationNames(): array
    {
        if (HostScope::organisationId() !== null) {
            return [];
        }

        $ids = [];

        foreach ($this->pageUsers()->items() as $user) {
            if ($user->organisation_id !== null) {
                $ids[$user->organisation_id] = $user->organisation_id;
            }
        }

        return Organisation::withTrashed()
            ->whereKey(array_values($ids))
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Organisation $organisation): array => [$organisation->id => $organisation->name])
            ->all();
    }

    /**
     * Whether the page runs on a central host (organisation column and filter).
     */
    #[Computed]
    public function central(): bool
    {
        return ! Tenant::check();
    }

    /** Tenant host: the invitation policy (users.create and roles.assign, OR-M7); central hosts: super admins (INV-17). */
    #[Computed]
    public function canInvite(): bool
    {
        $organisation = Tenant::current();

        return $organisation === null
            ? $this->currentUser()->isSuperAdmin()
            : Gate::allows('create', [Invitation::class, $organisation]);
    }

    /**
     * Users the actor may see: everyone in the host scope, minus super admins for non-super actors (DEC-26 (12)).
     *
     * @return Builder<User>
     */
    private function visibleUsers(): Builder
    {
        $query = User::query();

        if (! $this->currentUser()->isSuperAdmin()) {
            $query->where('is_super_admin', false);
        }

        return $query;
    }

    /**
     * Re-resolves a user for an action through the host scope; another organisation's id answers 404 (CV-5).
     */
    private function findUser(int $userId, bool $trashed = false): User
    {
        return HostScope::read(function () use ($userId, $trashed): User {
            $query = $this->visibleUsers();

            if ($trashed) {
                $query->onlyTrashed();
            }

            return $query->findOrFail($userId);
        });
    }

    /**
     * The organisation filter: always the tenant on a tenant host; centrally only a listed organisation (RK-I4).
     */
    private function pickedOrganisationId(): ?int
    {
        $tenantId = HostScope::organisationId();

        if ($tenantId !== null) {
            return $tenantId;
        }

        $picked = ctype_digit($this->organisation) ? (int) $this->organisation : null;

        $options = $this->organisationOptions();

        return $picked !== null && array_key_exists($picked, $options) ? $picked : null;
    }

    /**
     * The role filter: only a role the page offers.
     */
    private function pickedRoleId(): ?int
    {
        $picked = ctype_digit($this->role) ? (int) $this->role : null;

        $options = $this->roleOptions();

        return $picked !== null && array_key_exists($picked, $options) ? $picked : null;
    }

    /**
     * The current page, memoised in $usersPage so the paginate query runs once per request. PHP code calls the
     * methods, never the magic computed properties (Larastan level 8 has no Livewire computed support).
     *
     * @return LengthAwarePaginator<int, User>
     */
    private function pageUsers(): LengthAwarePaginator
    {
        return $this->users();
    }
};
