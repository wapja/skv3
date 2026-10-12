<?php

declare(strict_types=1);

use App\Actions\Invitations\CancelInvitation;
use App\Actions\Invitations\RemindInvitation;
use App\Authorization\Permission;
use App\Concerns\ResolvesCurrentUser;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\HostScope;
use App\Tenancy\UserNames;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Open invitations (§4.5): inviter, relative expiry, send again (users.invite.resend), copy the invite link
 * (viewLink, RK-B3) and cancel (users.delete). Invitations are read through the host's scope, so another
 * organisation's id is not found on a tenant host (TEN-3); central hosts list every organisation's
 * invitations with an organisation filter that tenant hosts ignore (RK-I4).
 */
new #[Title('Pending invitations')] class extends Component
{
    use ResolvesCurrentUser, WithPagination;

    /** Central hosts only; ignored on a tenant host (RK-I4). */
    #[Url]
    public string $organisation = '';

    #[Locked]
    public ?int $cancellingInvitationId = null;

    #[Locked]
    public string $cancellingEmail = '';

    #[Locked]
    public ?int $linkInvitationId = null;

    public bool $showCancelModal = false;

    public bool $showLinkModal = false;

    /**
     * The current page, read once per request; private properties are never dehydrated.
     *
     * @var LengthAwarePaginator<int, Invitation>|null
     */
    private ?LengthAwarePaginator $invitationsPage = null;

    /** The route's can: middleware does not cover Livewire updates or a page reused elsewhere (FU4-4). */
    public function mount(): void
    {
        Gate::authorize(Permission::UsersView);
    }

    public function updatedOrganisation(): void
    {
        $this->resetPage();
    }

    /** Sends the invitation again with a renewed expiry (W5: refused once accepted, by the action). */
    public function remind(int $invitationId, RemindInvitation $remindInvitation): void
    {
        $invitation = $this->findInvitation($invitationId);
        $this->authorize('remind', $invitation);

        $remindInvitation->handle($this->currentUser(), $invitation);

        Flux::toast(variant: 'success', text: __('The invitation was sent again.'));
    }

    public function confirmCancel(int $invitationId): void
    {
        $invitation = $this->findInvitation($invitationId);
        $this->authorize('cancel', $invitation);

        $this->cancellingInvitationId = $invitation->id;
        $this->cancellingEmail = $invitation->user->email;
        $this->showCancelModal = true;
    }

    /** Re-resolves and re-authorises: the locked id may come from a replayed snapshot of another session. */
    public function cancel(CancelInvitation $cancelInvitation): void
    {
        $invitation = $this->findInvitation($this->cancellingInvitationId ?? abort(404));
        $this->authorize('cancel', $invitation);

        $cancelInvitation->handle($this->currentUser(), $invitation);

        $this->closeCancelModal();
        Flux::toast(variant: 'success', text: __('The invitation was cancelled.'));
    }

    public function closeCancelModal(): void
    {
        $this->reset('cancellingInvitationId', 'cancellingEmail', 'showCancelModal');
    }

    /** RK-B3: the link is a bearer credential for the invitation's roles; only actors who could grant them see it. */
    public function showLink(int $invitationId): void
    {
        $invitation = $this->findInvitation($invitationId);
        $this->authorize('viewLink', $invitation);

        $this->linkInvitationId = $invitation->id;
        $this->showLinkModal = true;
    }

    public function closeLinkModal(): void
    {
        $this->reset('linkInvitationId', 'showLinkModal');
    }

    /**
     * Memoised in $invitationsPage so the paginate query runs once per request. PHP code calls this method,
     * never the magic computed property (Larastan level 8 has no Livewire computed support).
     *
     * @return LengthAwarePaginator<int, Invitation>
     */
    #[Computed]
    public function invitations(): LengthAwarePaginator
    {
        if ($this->invitationsPage instanceof LengthAwarePaginator) {
            return $this->invitationsPage;
        }

        $central = ! Tenant::check();
        $organisationId = $central ? filter_var($this->organisation, FILTER_VALIDATE_INT) : false;

        /** @var LengthAwarePaginator<int, Invitation> $invitations */
        $invitations = HostScope::read(fn (): LengthAwarePaginator => Invitation::query()
            ->whereNull('accepted_at')
            ->when($organisationId !== false, fn (Builder $query): Builder => $query->where('organisation_id', $organisationId))
            ->with($central ? ['user', 'organisation'] : ['user'])
            ->orderBy('expires_at')
            ->orderBy('id')
            ->paginate(25));

        return $this->invitationsPage = $invitations;
    }

    /** @return array<int, string> Inviters of this page's rows only (RL-9). */
    #[Computed]
    public function inviterNames(): array
    {
        $ids = [];

        foreach ($this->invitations()->items() as $invitation) {
            if ($invitation->invited_by !== null) {
                $ids[] = $invitation->invited_by;
            }
        }

        return UserNames::of(array_values(array_unique($ids)));
    }

    /** @return list<int> Rows whose invite link this actor may see (RK-B3). */
    #[Computed]
    public function linkableIds(): array
    {
        $ids = [];

        foreach ($this->invitations()->items() as $invitation) {
            if (Gate::allows('viewLink', $invitation)) {
                $ids[] = $invitation->id;
            }
        }

        return $ids;
    }

    /** The accept link for the modal, re-authorised on every render and never part of the table. */
    #[Computed]
    public function inviteLink(): ?string
    {
        $invitationId = $this->linkInvitationId;

        if ($invitationId === null) {
            return null;
        }

        $invitation = HostScope::read(fn (): ?Invitation => Invitation::query()
            ->with('user')
            ->whereNull('accepted_at')
            ->find($invitationId));

        return $invitation instanceof Invitation && Gate::allows('viewLink', $invitation) ? $invitation->acceptUrl() : null;
    }

    /** Tenant host: the invitation policy (users.create and roles.assign, OR-M7); central hosts: super admins. */
    #[Computed]
    public function canInvite(): bool
    {
        $organisation = Tenant::current();

        return $organisation === null
            ? $this->currentUser()->isSuperAdmin()
            : Gate::allows('create', [Invitation::class, $organisation]);
    }

    #[Computed]
    public function showsOrganisations(): bool
    {
        return ! Tenant::check();
    }

    /** @return array<int, string> */
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

    /** Through the host's scope: on a tenant host another organisation's id is not found (TEN-3). */
    private function findInvitation(int $invitationId): Invitation
    {
        return HostScope::read(fn (): Invitation => Invitation::query()->with('user')->findOrFail($invitationId));
    }
};
