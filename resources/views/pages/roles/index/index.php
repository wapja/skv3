<?php

declare(strict_types=1);

use App\Actions\Roles\CreateRole;
use App\Actions\Roles\DeleteRole;
use App\Actions\Roles\UpdateRole;
use App\Authorization\Permission;
use App\Authorization\Role;
use App\Concerns\ResolvesCurrentUser;
use App\Models\Organisation;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\HostScope;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role as RoleModel;

new #[Title('Roles')] class extends Component
{
    use ResolvesCurrentUser;
    use WithPagination;

    /** Central hosts only: whose roles are listed and where a new role goes; ignored on a tenant host (RK-I4). */
    #[Url]
    public string $organisation = '';

    #[Locked]
    public ?int $editingRoleId = null;

    /** Central hosts only; on a tenant host the form always works for the tenant (CV-5). */
    #[Locked]
    public ?int $formOrganisationId = null;

    #[Locked]
    public ?int $deletingRoleId = null;

    #[Locked]
    public string $deletingRoleName = '';

    public string $name = '';

    /** @var array<int, mixed> Checked permission values; converted and validated in save() (OR-M8). */
    public array $permissions = [];

    public bool $showRoleModal = false;

    public bool $showDeleteModal = false;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', RoleModel::class);
    }

    /**
     * Back to the first page when the organisation filter changes.
     */
    public function updatedOrganisation(): void
    {
        $this->resetPage();
    }

    /**
     * Open the editor for a new role: for the tenant on a tenant host, and on a central host only
     * for an explicitly picked, active organisation (ROLE-8).
     *
     * @throws ValidationException
     */
    public function create(): void
    {
        $this->authorize('create', RoleModel::class);
        $this->closeRoleModal();

        $this->formOrganisationId = (Tenant::current() ?? $this->pickedOrganisation())->id;
        $this->showRoleModal = true;
    }

    /**
     * Open the editor for a custom role of the host's organisation; system roles and other
     * organisations' roles are not found (W1, W6).
     */
    public function edit(int $roleId): void
    {
        $role = $this->scopedRole($roleId);
        $this->authorize('update', $role);
        $this->closeRoleModal();

        $offered = array_map(fn (Permission $permission): string => $permission->value, Permission::assignableToCustomRoles());
        $held = $role->permissions->pluck('name')->map(fn (mixed $name): string => (string) $name)->all();

        $this->editingRoleId = (int) $role->getKey();
        $this->formOrganisationId = (int) $role->getAttribute('team_id');
        $this->name = $role->name;
        // Held permissions the editor hides (users.impersonate while the module is off) stay on the role through UpdateRole (CV-4).
        $this->permissions = array_values(array_intersect($held, $offered));
        $this->showRoleModal = true;
    }

    /**
     * Create or update the role through its action, which authorises again and checks the
     * organisation, the name and the permissions (RL-19).
     *
     * @throws ValidationException
     */
    public function save(CreateRole $createRole, UpdateRole $updateRole): void
    {
        $organisation = $this->formOrganisation();
        $permissions = $this->postedPermissions();

        if ($this->editingRoleId === null) {
            $this->authorize('create', RoleModel::class);
            $createRole->handle($this->currentUser(), $organisation, $this->name, $permissions);
            $message = __('Role created.');
        } else {
            $this->authorize('update', $this->scopedRole($this->editingRoleId));
            $updateRole->handle($this->currentUser(), $organisation, $this->editingRoleId, $this->name, $permissions);
            $message = __('Role saved.');
        }

        $this->closeRoleModal();
        Flux::toast(variant: 'success', text: $message);
    }

    /**
     * Ask before deleting a custom role; the modal shows how many users hold it (X14).
     */
    public function confirmDelete(int $roleId): void
    {
        $role = $this->scopedRole($roleId);
        $this->authorize('delete', $role);

        $this->deletingRoleId = (int) $role->getKey();
        $this->deletingRoleName = $role->name;
        $this->showDeleteModal = true;
    }

    /**
     * Delete the role confirmed in the modal; its holders lose it (X14).
     */
    public function delete(DeleteRole $deleteRole): void
    {
        if ($this->deletingRoleId === null) {
            return;
        }

        $role = $this->scopedRole($this->deletingRoleId);
        $this->authorize('delete', $role);
        $organisation = Tenant::current() ?? Organisation::withTrashed()->findOrFail((int) $role->getAttribute('team_id'));

        $holders = $deleteRole->handle($this->currentUser(), $organisation, (int) $role->getKey());

        $this->closeDeleteModal();
        Flux::toast(variant: 'success', text: __('Role deleted. :count users lost it.', ['count' => $holders]));
    }

    /**
     * Close the editor and forget its state.
     */
    public function closeRoleModal(): void
    {
        $this->resetErrorBag();
        $this->showRoleModal = false;
        $this->editingRoleId = null;
        $this->formOrganisationId = null;
        $this->name = '';
        $this->permissions = [];
    }

    /**
     * Close the delete modal and forget its state.
     */
    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingRoleId = null;
        $this->deletingRoleName = '';
    }

    /**
     * Custom roles of the host's organisation; on a central host every active organisation's
     * roles, or the picked organisation's.
     *
     * @return LengthAwarePaginator<int, RoleModel>
     */
    #[Computed]
    public function roles(): LengthAwarePaginator
    {
        $organisationId = HostScope::organisationId() ?? (ctype_digit($this->organisation) ? (int) $this->organisation : null);

        $query = RoleModel::query()
            ->whereNotNull('team_id')
            ->whereIn('team_id', Organisation::query()->select('id'))
            ->with('permissions');

        if ($organisationId !== null) {
            $query->where('team_id', $organisationId);
        }

        return $query->orderBy('name')->orderBy('id')->paginate(25);
    }

    /**
     * The two system roles, shown read-only (ROLE-7, ROLE-20).
     *
     * @return Collection<int, RoleModel>
     */
    #[Computed]
    public function systemRoles(): Collection
    {
        return RoleModel::query()
            ->whereNull('team_id')
            ->whereIn('name', Role::values())
            ->with('permissions')
            ->orderBy('name')
            ->get();
    }

    /**
     * The permissions the editor offers, by permission group (ROLE-9, ROLE-17).
     *
     * @return array<string, list<Permission>>
     */
    #[Computed]
    public function permissionGroups(): array
    {
        $groups = [];

        foreach (Permission::assignableToCustomRoles() as $permission) {
            $groups[$permission->group()->value][] = $permission;
        }

        return $groups;
    }

    /**
     * Active organisations for the central filter and picker; empty on a tenant host (RK-I4).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function organisationOptions(): array
    {
        if (Tenant::check()) {
            return [];
        }

        $options = [];

        foreach (Organisation::query()->orderBy('name')->get(['id', 'name']) as $organisation) {
            $options[$organisation->id] = $organisation->name;
        }

        return $options;
    }

    /**
     * How many users hold the role in the delete modal (a plain count, no lock).
     */
    #[Computed]
    public function holderCount(): int
    {
        if ($this->deletingRoleId === null) {
            return 0;
        }

        return DB::table('model_has_roles')->where('role_id', $this->deletingRoleId)->count();
    }

    /**
     * A custom role of the host's organisation (any organisation's for a super admin on a central
     * host); never a system role.
     */
    private function scopedRole(int $roleId): RoleModel
    {
        $query = RoleModel::query()->whereNotNull('team_id')->with('permissions');
        $organisationId = HostScope::organisationId();

        if ($organisationId !== null) {
            $query->where('team_id', $organisationId);
        }

        return $query->findOrFail($roleId);
    }

    /**
     * The organisation the editor works for: always the tenant on a tenant host (CV-5).
     *
     * @throws ValidationException
     */
    private function formOrganisation(): Organisation
    {
        $tenant = Tenant::current();

        if ($tenant !== null) {
            return $tenant;
        }

        if ($this->formOrganisationId === null) {
            throw ValidationException::withMessages(['organisation' => __('Choose an organisation first.')]);
        }

        // withTrashed(): an organisation archived meanwhile gets the action's "archived" error (RK-I11).
        return Organisation::withTrashed()->findOrFail($this->formOrganisationId);
    }

    /**
     * The organisation picked in the central filter, active only.
     *
     * @throws ValidationException
     */
    private function pickedOrganisation(): Organisation
    {
        $organisation = ctype_digit($this->organisation)
            ? Organisation::query()->find((int) $this->organisation)
            : null;

        return $organisation ?? throw ValidationException::withMessages(['organisation' => __('Choose an organisation first.')]);
    }

    /**
     * The checked permissions as enum cases; an unknown or non-string value is a validation error,
     * never a ValueError (OR-M8, RK-M7).
     *
     * @return list<Permission>
     *
     * @throws ValidationException
     */
    private function postedPermissions(): array
    {
        $permissions = [];

        foreach ($this->permissions as $value) {
            $permission = is_string($value) ? Permission::tryFrom($value) : null;

            if ($permission === null) {
                throw ValidationException::withMessages([
                    'permissions' => __('One or more permissions cannot be given to a custom role.'),
                ]);
            }

            $permissions[] = $permission;
        }

        return $permissions;
    }
};
