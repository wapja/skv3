<?php

declare(strict_types=1);

use App\Actions\Organisations\CreateOrganisation;
use App\Concerns\OrganisationValidationRules;
use App\Concerns\ResolvesCurrentUser;
use App\Enums\Locale;
use App\Models\Organisation;
use App\Rules\AvailableEmail;
use App\Tenancy\Hosts;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Organisation wizard (§4.3, ORG-14): name, slug (suggested, live availability, slug.apex preview),
 * description and the first admin's address and language. CreateOrganisation creates the organisation,
 * the pending first admin and the invitation in one transaction (RL-12). Central hosts only.
 */
new #[Title('New organisation')] class extends Component
{
    use OrganisationValidationRules;
    use ResolvesCurrentUser;

    public string $name = '';

    public string $slug = '';

    public bool $slugEdited = false;

    public ?string $description = null;

    public string $adminEmail = '';

    public string $adminLocale = '';

    public function mount(): void
    {
        $this->authorize('create', Organisation::class);

        $this->adminLocale = Locale::default()->value;
    }

    /** The slug follows the name until someone edits it. */
    public function updatedName(): void
    {
        if (! $this->slugEdited) {
            $this->slug = trim(Str::limit(Str::slug($this->name), 63, ''), '-');
        }
    }

    /** An emptied slug follows the name again. */
    public function updatedSlug(): void
    {
        $this->slug = Str::lower(trim($this->slug));
        $this->slugEdited = $this->slug !== '';
    }

    /** @return array{available: bool, message: string|null, host: string} */
    #[Computed]
    public function slugStatus(): array
    {
        if ($this->slug === '') {
            return ['available' => false, 'message' => null, 'host' => ''];
        }

        $validator = Validator::make(
            ['slug' => $this->slug],
            ['slug' => $this->organisationSlugRules(null, $this->currentUser()->isSuperAdmin())],
        );

        return [
            'available' => $validator->passes(),
            'message' => $validator->passes() ? null : $validator->errors()->first('slug'),
            'host' => Hosts::tenant($this->slug),
        ];
    }

    public function create(CreateOrganisation $createOrganisation): void
    {
        $this->authorize('create', Organisation::class);

        $actor = $this->currentUser();

        $this->validate([
            'name' => $this->organisationNameRules(null, $actor->isSuperAdmin()),
            'slug' => $this->organisationSlugRules(null, $actor->isSuperAdmin()),
            'description' => $this->organisationDescriptionRules(),
            // A new organisation has no users yet: any holder of the address is elsewhere (W9 generic message).
            'adminEmail' => ['required', 'string', 'email', 'max:255', new AvailableEmail(null)],
            'adminLocale' => ['required', 'string', Rule::enum(Locale::class)],
        ]);

        try {
            $organisation = $createOrganisation->handle(
                $actor,
                $this->name,
                $this->slug,
                $this->description,
                $this->adminEmail,
                Locale::from($this->adminLocale),
            );
        } catch (ValidationException $exception) {
            // The invitation step reports the address as "email"; this form calls it "adminEmail".
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => [$field === 'email' ? 'adminEmail' : $field => $messages])
                ->all());
        }

        session()->flash('status', __('Organisation :name created. Its first admin has been invited.', ['name' => $organisation->name]));
        $this->redirectRoute('organisations.index', navigate: true);
    }
};
