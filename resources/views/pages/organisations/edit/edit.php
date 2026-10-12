<?php

declare(strict_types=1);

use App\Actions\Auth\IssueHandoff;
use App\Actions\Organisations\UpdateOrganisation;
use App\Concerns\OrganisationValidationRules;
use App\Concerns\ResolvesCurrentUser;
use App\Models\Organisation;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\Hosts;
use App\Tenancy\HostScope;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Organisation edit page (§4.3). Takes a plain id and resolves it per request inside the host's scope
 * (OR-M5). Organisation admins edit name and description; only super admins change the slug (OD-3 = B).
 * A rename made on the organisation's own host continues on the new host through a handoff (ORG-16).
 * No second-factor setting until M7 (RL-17).
 */
new #[Title('Edit organisation')] class extends Component
{
    use OrganisationValidationRules;
    use ResolvesCurrentUser;

    #[Locked]
    public int $organisationId;

    public string $name = '';

    public string $slug = '';

    public ?string $description = null;

    /**
     * The organisation, read once per request; private properties are never dehydrated. An error re-render
     * reuses the read save() made instead of re-querying a row archived in between, which would answer 404
     * and hide the action's error (FU4-1).
     */
    private ?Organisation $resolvedOrganisation = null;

    public function mount(int $organisation): void
    {
        $this->organisationId = $organisation;
        $model = $this->organisation();
        $this->authorize('update', $model);

        $this->name = $model->name;
        $this->slug = $model->slug;
        $this->description = $model->description;
    }

    public function save(UpdateOrganisation $updateOrganisation, IssueHandoff $issueHandoff): void
    {
        $actor = $this->currentUser();
        $organisation = $this->organisation();
        $this->authorize('update', $organisation);

        $previousSlug = $organisation->slug;
        // OD-3: only super admins change the slug; for everyone else the stored one is kept, whatever is sent.
        $this->slug = $actor->isSuperAdmin() ? Str::lower(trim($this->slug)) : $previousSlug;

        $rules = [
            'name' => $this->organisationNameRules($organisation->id, $actor->isSuperAdmin()),
            'description' => $this->organisationDescriptionRules(),
        ];

        if ($actor->isSuperAdmin()) {
            $rules['slug'] = $this->organisationSlugRules($organisation->id, true);
        }

        $this->validate($rules);

        $updated = $updateOrganisation->handle($actor, $organisation, $this->name, $this->slug, $this->description);
        $this->resolvedOrganisation = $updated;

        if ($updated->slug !== $previousSlug && Tenant::check()) {
            // ORG-16: this host no longer exists. The update has committed; continue on the new host as the
            // signed-in actor only (FU3-1). Absolute URL on another host: no navigate.
            $this->redirect($issueHandoff->handle(
                $actor,
                Hosts::tenant($updated->slug),
                route('organisations.edit', $this->organisationId, absolute: false),
            ));

            return;
        }

        $this->slug = $updated->slug;
        Flux::toast(variant: 'success', text: __('Organisation saved.'));
    }

    /**
     * On a tenant host only that host's organisation; on a central host (super admins) any active one.
     * Memoised in $resolvedOrganisation: PHP code calls this method, never the magic computed property
     * (Larastan level 8 has no Livewire computed support).
     */
    #[Computed]
    public function organisation(): Organisation
    {
        if ($this->resolvedOrganisation instanceof Organisation) {
            return $this->resolvedOrganisation;
        }

        $hostOrganisationId = HostScope::organisationId();

        abort_if($hostOrganisationId !== null && $hostOrganisationId !== $this->organisationId, 404);

        return $this->resolvedOrganisation = Organisation::query()->findOrFail($this->organisationId);
    }

    #[Computed]
    public function canChangeSlug(): bool
    {
        return $this->currentUser()->isSuperAdmin();
    }
};
