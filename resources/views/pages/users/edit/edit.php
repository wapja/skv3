<?php

declare(strict_types=1);

use App\Actions\Users\UpdateSuperAdmin;
use App\Actions\Users\UpdateUser;
use App\Actions\Users\UpdateUserRoles;
use App\Actions\Users\UpdateUserStatus;
use App\Authorization\Role;
use App\Authorization\SyncPermissions;
use App\Concerns\ConfirmsPasswords;
use App\Concerns\ProfileValidationRules;
use App\Concerns\ResolvesCurrentUser;
use App\Enums\UserStatus;
use App\Models\User;
use App\Tenancy\HostScope;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role as RoleModel;

new #[Title('Edit user')] class extends Component
{
    use ConfirmsPasswords;
    use ProfileValidationRules;
    use ResolvesCurrentUser;

    /** Session key prefix of a name, address and status parked while the actor confirms their password (RL-18). */
    private const string DRAFT_PREFIX = 'users.edit.draft.';

    #[Locked]
    public int $userId;

    public string $name = '';

    public string $email = '';

    public string $status = '';

    /**
     * Role ids as the checkboxes send them.
     *
     * @var list<string>
     */
    public array $roles = [];

    public bool $isSuperAdmin = false;

    #[Locked]
    public bool $draftRestored = false;

    /** The target read once per request (query and view check); private properties are never dehydrated. */
    private ?User $resolvedTarget = null;

    /**
     * Mount the component with the user id from the route; the user is read per request (R-7).
     */
    public function mount(int $user): void
    {
        $this->userId = $user;
        $target = $this->target();

        $this->fillAccount($target);
        $this->isSuperAdmin = $target->isSuperAdmin();
        $this->roles = $this->assignedRoleIds($target);
        $this->restoreDraft();
    }

    /**
     * Save name, address and status (USR-12). Your own and a pending user's address are read-only and never
     * sent (E1); another user's new address needs a fresh password confirmation first (RL-18).
     */
    public function saveAccount(UpdateUser $updateUser, UpdateUserStatus $updateUserStatus): void
    {
        $actor = $this->currentUser();
        $target = $this->target();
        $this->authorize('update', $target);

        $pending = $target->status === UserStatus::PendingActivation;
        $emailEditable = $actor->isNot($target) && ! $pending;
        $this->email = Str::lower(trim($this->email));

        $this->validate([
            'name' => $this->nameRules(),
            ...($emailEditable ? ['email' => $this->emailRules($target->id, $target->organisation_id)] : []),
            ...($pending ? [] : ['status' => ['required', 'string', Rule::in([UserStatus::Active->value, UserStatus::Disabled->value])]]),
        ]);

        $email = $emailEditable ? $this->email : $target->email;
        $status = $pending ? $target->status : UserStatus::from($this->status);
        $statusChanges = $status !== $target->status;

        if ($statusChanges) {
            $this->authorize('updateStatus', $target);
        }

        if ($email !== $target->email && $this->confirmationParked($target)) {
            return;
        }

        $updateUser->handle($actor, $target, $this->name, $email);

        if ($statusChanges) {
            $updateUserStatus->handle($actor, $target, $status);
        }

        $this->resolvedTarget = null;
        $this->fillAccount($this->target());
        $this->draftRestored = false;

        Flux::toast(variant: 'success', text: __('User saved.'));
    }

    /**
     * The only public method that writes roles (W1); UpdateUserRoles checks the team, the badge and escalation.
     */
    public function saveRoles(UpdateUserRoles $updateUserRoles): void
    {
        $actor = $this->currentUser();
        $target = $this->target();
        $this->authorize('updateRoles', $target);

        if ($actor->is($target)) {
            // No role controls on your own account (W12).
            throw new AuthorizationException;
        }

        if ($target->status === UserStatus::PendingActivation) {
            // The accept link never rotates: widening a pending user's roles would widen a link already shown (RK-B3).
            throw ValidationException::withMessages([
                'roles' => __('Cancel the invitation and invite again with the new roles.'),
            ]);
        }

        $roleIds = array_values(array_unique(array_map(intval(...), $this->roles)));

        $updateUserRoles->handle($actor, $target, $roleIds);

        $this->roles = $this->assignedRoleIds($target);

        Flux::toast(variant: 'success', text: __('Roles saved.'));
    }

    /**
     * Grant or remove super admin after a fresh password confirmation (RL-18, RK-M8). Never on a pending user,
     * whose accept link would then grant the flag (RK-B3).
     */
    public function saveSuperAdmin(UpdateSuperAdmin $updateSuperAdmin): void
    {
        $target = $this->target();
        $this->authorize('updateSuperAdmin', $target);

        if ($target->status === UserStatus::PendingActivation) {
            throw ValidationException::withMessages([
                'is_super_admin' => __('Cancel the invitation and invite again with the new roles.'),
            ]);
        }

        if ($this->passwordConfirmationRequired(route('users.edit', ['user' => $target->id], absolute: false))) {
            return;
        }

        $updateSuperAdmin->handle($this->currentUser(), $target, $this->isSuperAdmin);

        $this->resolvedTarget = null;
        $this->isSuperAdmin = $this->target()->isSuperAdmin();

        Flux::toast(variant: 'success', text: $this->isSuperAdmin ? __('Super admin granted.') : __('Super admin removed.'));
    }

    /**
     * The user this page shows, read again through the host scope on every request (USR-20) and memoised for
     * the rest of it; a save clears the memo before it reads the result back. PHP code calls this method,
     * never the magic computed property (Larastan level 8 has no Livewire computed support).
     */
    #[Computed]
    public function target(): User
    {
        if ($this->resolvedTarget instanceof User) {
            return $this->resolvedTarget;
        }

        $target = HostScope::read(fn (): User => User::query()->findOrFail($this->userId));
        $this->authorize('view', $target);

        return $this->resolvedTarget = $target;
    }

    /**
     * What the signed-in user may change on this page.
     *
     * @return array{update: bool, updateStatus: bool, updateRoles: bool, updateSuperAdmin: bool, ownAccount: bool, pending: bool}
     */
    #[Computed]
    public function abilities(): array
    {
        $actor = $this->currentUser();
        $target = $this->target();
        $ownAccount = $actor->is($target);
        $pending = $target->status === UserStatus::PendingActivation;

        return [
            'update' => $actor->can('update', $target),
            'updateStatus' => ! $pending && $actor->can('updateStatus', $target),
            // A pending user's roles and flag stay as invited: the accept link never rotates (RK-B3).
            'updateRoles' => ! $ownAccount && ! $pending && $actor->can('updateRoles', $target),
            'updateSuperAdmin' => ! $pending && $actor->can('updateSuperAdmin', $target),
            'ownAccount' => $ownAccount,
            'pending' => $pending,
        ];
    }

    /**
     * The organisation_admin template first, then the target team's own roles (DEC-21).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function roleOptions(): array
    {
        $teamId = $this->target()->organisation_id;

        if ($teamId === null) {
            return [];
        }

        return RoleModel::query()
            ->where('guard_name', SyncPermissions::GUARD)
            ->where(function (Builder $query) use ($teamId): void {
                $query->where('team_id', $teamId)->orWhere(function (Builder $template): void {
                    $template->whereNull('team_id')->where('name', Role::OrganisationAdmin->value);
                });
            })
            ->get()
            ->sortBy(fn (RoleModel $role): string => ($role->getAttribute('team_id') === null ? '0 ' : '1 ').$role->name)
            ->mapWithKeys(fn (RoleModel $role): array => [(int) $role->getKey() => Role::tryFrom($role->name)?->label() ?? $role->name])
            ->all();
    }

    /**
     * Parks the unsaved name, address and status, then sends the actor to confirm their password. True means stop.
     */
    private function confirmationParked(User $target): bool
    {
        $key = self::DRAFT_PREFIX.$target->id;
        session()->put($key, ['name' => $this->name, 'email' => $this->email, 'status' => $this->status]);

        if ($this->passwordConfirmationRequired(route('users.edit', ['user' => $target->id], absolute: false))) {
            return true;
        }

        session()->forget($key);

        return false;
    }

    /**
     * Brings back a name, address and status parked before a password confirmation; a status other than
     * Active or Disabled is dropped (W4).
     */
    private function restoreDraft(): void
    {
        $draft = session()->pull(self::DRAFT_PREFIX.$this->userId);

        if (! is_array($draft)) {
            return;
        }

        $name = $draft['name'] ?? null;
        $email = $draft['email'] ?? null;

        if (! is_string($name) || ! is_string($email)) {
            return;
        }

        $this->name = $name;
        $this->email = $email;

        $status = $draft['status'] ?? null;

        if (is_string($status) && in_array(UserStatus::tryFrom($status), [UserStatus::Active, UserStatus::Disabled], true)) {
            $this->status = $status;
        }

        $this->draftRestored = true;
    }

    private function fillAccount(User $target): void
    {
        $this->name = $target->name;
        $this->email = $target->email;
        $this->status = $target->status->value;
    }

    /**
     * The target's role ids in their own team, read without Spatie's team-filtered relation (X1).
     *
     * @return list<string>
     */
    private function assignedRoleIds(User $target): array
    {
        if ($target->organisation_id === null) {
            return [];
        }

        return array_values(DB::table('model_has_roles')
            ->where('model_type', $target->getMorphClass())
            ->where('model_id', $target->id)
            ->where('team_id', $target->organisation_id)
            ->orderBy('role_id')
            ->pluck('role_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all());
    }
};
