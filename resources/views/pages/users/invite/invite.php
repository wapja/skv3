<?php

declare(strict_types=1);

use App\Actions\Invitations\InviteUser;
use App\Authorization\Permission;
use App\Authorization\Role;
use App\Authorization\SyncPermissions;
use App\Concerns\ResolvesCurrentUser;
use App\Enums\Locale;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Support\RateLimits\UserActionLimit;
use App\Tenancy\Facades\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * Invite a user by email with a language and at least one role (§4.5). A tenant host invites into its own
 * organisation; on a central host a super admin picks the organisation first (INV-17). InviteUser
 * authorises, locks the organisation and validates the address and the roles; this page only collects
 * the input.
 */
new #[Title('Invite user')] class extends Component
{
    use ResolvesCurrentUser;

    public string $email = '';

    public string $locale = '';

    /** @var list<int|string> Checkbox values arrive as strings. */
    public array $roles = [];

    /** The organisation picked on a central host; a tenant host always uses its own organisation (RK-I4). */
    public ?int $organisationId = null;

    /** Tenant host: users.create and roles.assign in this organisation (OR-M7); central hosts: super admins only. */
    public function mount(): void
    {
        $organisation = Tenant::current();

        if ($organisation instanceof Organisation) {
            $this->authorize('create', [Invitation::class, $organisation]);
        } else {
            abort_unless($this->currentUser()->isSuperAdmin(), 403);
        }

        $this->locale = Locale::default()->value;
    }

    /** Roles belong to one organisation, so a new pick clears them. */
    public function updatedOrganisationId(): void
    {
        $this->roles = [];
    }

    public function invite(InviteUser $inviteUser): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'locale' => ['required', 'string', Rule::enum(Locale::class)],
            'roles' => ['array'],
            'roles.*' => ['integer'],
        ]);

        $email = trim($this->email);
        $organisation = $this->organisationToInvite();

        // Components authorise before calling an action; super admins pass through Gate::before ('create' is not always checked).
        $this->authorize('create', [Invitation::class, $organisation]);

        // CD-3: a per-user limit on sent invitations, here and not in InviteUser, so a bulk caller counts its own way.
        // The slot is reserved before the send and given back when InviteUser refuses (DEC-27 (2)).
        $inviteLimit = UserActionLimit::invite();
        $inviteLimit->reserve($this->currentUser(), 'email');

        try {
            $inviteUser->handle(
                $this->currentUser(),
                $organisation,
                $email,
                array_map(fn (int|string $roleId): int => (int) $roleId, $this->roles),
                Locale::from($this->locale),
            );
        } catch (Throwable $exception) {
            $inviteLimit->release($this->currentUser());

            throw $exception;
        }

        session()->flash('status', __('Invitation sent to :email.', ['email' => Str::lower($email)]));
        $this->redirectRoute($this->returnRoute(), navigate: true);
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

    /**
     * The host's organisation, or the central pick. Archived picks are passed on on purpose: InviteUser
     * refuses them under its own lock (RK-I11).
     *
     * @throws ValidationException
     */
    private function organisationToInvite(): Organisation
    {
        $current = Tenant::current();

        if ($current instanceof Organisation) {
            return $current;
        }

        $organisationId = $this->pickedOrganisationId();
        $organisation = $organisationId === null ? null : Organisation::withTrashed()->find($organisationId);

        return $organisation ?? throw ValidationException::withMessages([
            'organisationId' => __('Choose an organisation first.'),
        ]);
    }

    /** Livewire unsets a typed int property that receives an empty string, so read it null-safely. */
    private function pickedOrganisationId(): ?int
    {
        return $this->organisationId ?? null;
    }
};
