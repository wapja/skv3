<?php

declare(strict_types=1);

use App\Actions\Auth\IssueHandoff;
use App\Actions\Organisations\ArchiveOrganisation;
use App\Actions\Organisations\PurgeOrganisation;
use App\Actions\Organisations\RestoreOrganisation;
use App\Concerns\ConfirmsPasswords;
use App\Concerns\ResolvesCurrentUser;
use App\Models\Organisation;
use App\Tenancy\Hosts;
use App\Tenancy\HostScope;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Organisations index (§4.3, §6.2). A tenant host shows its own organisation; super admins on a central
 * host see every organisation, the archived view, "Open" (a handoff to the organisation's host) and
 * archive / restore / purge. Every action re-resolves its organisation inside this host's scope from a
 * plain id, re-authorises and calls the action, which authorises again.
 */
new #[Title('Organisations')] class extends Component
{
    use ConfirmsPasswords;
    use ResolvesCurrentUser;
    use WithPagination;

    #[Url]
    public string $view = 'active';

    #[Url]
    public string $search = '';

    #[Locked]
    public ?int $archivingId = null;

    #[Locked]
    public ?int $purgingId = null;

    public string $purgeConfirmation = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Organisation::class);
    }

    /** Back to the first page whenever the view or the search changes. */
    public function updated(string $property): void
    {
        if (in_array($property, ['view', 'search'], true)) {
            $this->resetPage();
        }
    }

    /**
     * "Open": sign the current user in on the organisation's own host through a handoff; super admins on a
     * central host only (ORG-18). On a tenant host it answers 404 for every actor, so nobody can mint a
     * sign-in link for themselves there.
     */
    public function open(int $organisationId, IssueHandoff $issueHandoff): void
    {
        abort_unless(HostScope::organisationId() === null, 404);

        $organisation = $this->findOrganisation($organisationId);
        $this->authorize('view', $organisation);

        // FU3-1: only the signed-in actor is ever handed off. An absolute URL on another host: no navigate.
        $this->redirect($issueHandoff->handle($this->currentUser(), Hosts::tenant($organisation->slug)));
    }

    public function confirmArchive(int $organisationId): void
    {
        $organisation = $this->findOrganisation($organisationId);
        $this->authorize('delete', $organisation);

        $this->archivingId = $organisation->id;
        Flux::modal('archive-organisation')->show();
    }

    public function archive(ArchiveOrganisation $archiveOrganisation): void
    {
        if ($this->archivingId === null) {
            return;
        }

        $organisation = $this->findOrganisation($this->archivingId);
        $this->authorize('delete', $organisation);

        $archiveOrganisation->handle($this->currentUser(), $organisation);

        $this->archivingId = null;
        Flux::modal('archive-organisation')->close();
        Flux::toast(variant: 'success', text: __('Organisation :name archived.', ['name' => $organisation->name]));
    }

    public function restore(int $organisationId, RestoreOrganisation $restoreOrganisation): void
    {
        $organisation = $this->findOrganisation($organisationId, archived: true);
        $this->authorize('restore', $organisation);

        $restoreOrganisation->handle($this->currentUser(), $organisation);

        Flux::toast(variant: 'success', text: __('Organisation :name restored.', ['name' => $organisation->name]));
    }

    /** RL-18: a purge needs a fresh password confirmation, asked before the modal opens. */
    public function confirmPurge(int $organisationId): void
    {
        $organisation = $this->findOrganisation($organisationId, archived: true);
        $this->authorize('forceDelete', $organisation);

        if ($this->passwordConfirmationRequired($this->archivedViewUrl())) {
            return;
        }

        $this->purgingId = $organisation->id;
        $this->purgeConfirmation = '';
        $this->resetErrorBag('purgeConfirmation');
        Flux::modal('purge-organisation')->show();
    }

    /** Checked again here: the confirmation may have expired while the modal was open, or the call is replayed. */
    public function purge(PurgeOrganisation $purgeOrganisation): void
    {
        if ($this->purgingId === null) {
            return;
        }

        $this->resetErrorBag('purgeConfirmation');

        $organisation = $this->findOrganisation($this->purgingId, archived: true);
        $this->authorize('forceDelete', $organisation);

        if ($this->passwordConfirmationRequired($this->archivedViewUrl())) {
            return;
        }

        if ($this->purgeConfirmation !== $organisation->slug) {
            $this->addError('purgeConfirmation', __('Type :slug to confirm.', ['slug' => $organisation->slug]));

            return;
        }

        $purgeOrganisation->handle($this->currentUser(), $organisation, $this->purgeConfirmation);

        $this->purgingId = null;
        $this->purgeConfirmation = '';
        Flux::modal('purge-organisation')->close();
        Flux::toast(variant: 'success', text: __('Organisation :name purged.', ['name' => $organisation->name]));
    }

    /** @return LengthAwarePaginator<int, Organisation> */
    #[Computed]
    public function organisations(): LengthAwarePaginator
    {
        // RK-I4: organisations are central models, so this host's scope is applied explicitly.
        $organisationId = HostScope::organisationId();
        $search = trim($this->search);
        $query = $this->showsArchived() ? Organisation::onlyTrashed() : Organisation::query();

        return $query
            ->when($organisationId !== null, fn (Builder $query): Builder => $query->whereKey($organisationId))
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                ->whereLike('name', '%'.$search.'%', caseSensitive: false)
                ->orWhereLike('slug', '%'.$search.'%', caseSensitive: false)))
            ->orderBy('name')
            ->paginate(25);
    }

    /** A super admin on the apex or the admin host: the archived view and "Open" are shown only there. */
    #[Computed]
    public function onCentralHost(): bool
    {
        return HostScope::organisationId() === null;
    }

    #[Computed]
    public function archivingOrganisation(): ?Organisation
    {
        return $this->archivingId === null ? null : Organisation::query()->find($this->archivingId);
    }

    #[Computed]
    public function purgingOrganisation(): ?Organisation
    {
        return $this->purgingId === null ? null : Organisation::onlyTrashed()->find($this->purgingId);
    }

    /** An organisation this host may act on: on a tenant host only its own, otherwise 404. */
    private function findOrganisation(int $organisationId, bool $archived = false): Organisation
    {
        $hostOrganisationId = HostScope::organisationId();

        abort_if($hostOrganisationId !== null && $hostOrganisationId !== $organisationId, 404);

        $query = $archived ? Organisation::onlyTrashed() : Organisation::query();

        return $query->findOrFail($organisationId);
    }

    private function showsArchived(): bool
    {
        return $this->view === 'archived' && HostScope::organisationId() === null;
    }

    /** Built server-side: where the password confirmation sends the super admin back to. */
    private function archivedViewUrl(): string
    {
        return route('organisations.index', ['view' => 'archived'], absolute: false);
    }
};
