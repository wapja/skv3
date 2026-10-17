<?php

declare(strict_types=1);

use App\Actions\Auth\IssueHandoff;
use App\Actions\Organisations\UpdateOrganisation;
use App\Concerns\ConfirmsPasswords;
use App\Concerns\OrganisationValidationRules;
use App\Concerns\ResolvesCurrentUser;
use App\Models\Organisation;
use App\Tenancy\Auth\SecondFactor;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\Hosts;
use App\Tenancy\HostScope;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Organisation edit page (§4.3). Takes a plain id and resolves it per request inside the host's scope
 * (OR-M5). Organisation admins edit name and description; only super admins change the slug (OD-3 = B).
 * A rename made on the organisation's own host continues on the new host through a handoff (ORG-16).
 * Both also switch the second-factor rule (§6.6, CD-3). A change in either direction asks for a fresh password
 * confirmation first, because the switch decides how every member signs in; an organisation admin turns the rule
 * on only with a second factor of their own, which this page checks before asking and UpdateOrganisation again.
 */
new #[Title('Edit organisation')] class extends Component
{
    use ConfirmsPasswords;
    use OrganisationValidationRules;
    use ResolvesCurrentUser;

    #[Locked]
    public int $organisationId;

    public string $name = '';

    public string $slug = '';

    public ?string $description = null;

    public bool $requireSecondFactor = false;

    /** What the switch showed when the page was rendered: a save that did not touch it keeps what is stored now (plan review K0). */
    #[Locked]
    public bool $shownRequireSecondFactor = false;

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
        $this->requireSecondFactor = $model->require_second_factor;
        $this->shownRequireSecondFactor = $model->require_second_factor;
    }

    public function save(UpdateOrganisation $updateOrganisation, IssueHandoff $issueHandoff): void
    {
        $actor = $this->currentUser();
        $organisation = $this->organisation();
        $this->authorize('update', $organisation);

        if ($this->requireSecondFactor === $this->shownRequireSecondFactor) {
            // The switch was not touched: keep what is stored now, which another admin may have changed meanwhile.
            $this->requireSecondFactor = $organisation->require_second_factor;
        } elseif ($this->requireSecondFactor !== $organisation->require_second_factor) {
            // Before the password: a confirmation the action would then refuse is a wasted round trip.
            if ($this->requireSecondFactor && ! $actor->isSuperAdmin() && ! SecondFactor::satisfiedBy($actor)) {
                throw ValidationException::withMessages(['requireSecondFactor' => __('Set up a second factor for your own account first.')]);
            }

            if ($this->passwordConfirmationRequired(route('organisations.edit', $this->organisationId, absolute: false))) {
                return;
            }
        }

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

        $updated = $updateOrganisation->handle($actor, $organisation, $this->name, $this->slug, $this->description, $this->requireSecondFactor);
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
        $this->requireSecondFactor = $updated->require_second_factor;
        $this->shownRequireSecondFactor = $updated->require_second_factor;
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
