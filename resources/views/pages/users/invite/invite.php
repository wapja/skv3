<?php

declare(strict_types=1);

use App\Actions\Invitations\InviteUser;
use App\Concerns\ChoosesInvitationOrganisation;
use App\Enums\Locale;
use App\Models\Invitation;
use App\Support\RateLimits\UserActionLimit;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Invite a user by email with a language and at least one role (§4.5). A tenant host invites into its own
 * organisation; on a central host a super admin picks the organisation first (INV-17). InviteUser
 * authorises, locks the organisation and validates the address and the roles; this page only collects
 * the input.
 */
new #[Title('Invite user')] class extends Component
{
    use ChoosesInvitationOrganisation;

    public string $email = '';

    public string $locale = '';

    /** @var list<int|string> Checkbox values arrive as strings. */
    public array $roles = [];

    /** The organisation picked on a central host; a tenant host always uses its own organisation (RK-I4). */
    public ?int $organisationId = null;

    public function mount(): void
    {
        $this->authorizeInvitePage();

        $this->locale = Locale::default()->value;
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
        // Components authorise before calling an action: organisationToInvite() authorises the organisation it returns.
        $organisation = $this->organisationToInvite();

        // CD-3: a per-user limit on sent invitations, here and not in InviteUser, so a bulk caller counts its own way.
        // The slot is reserved before the send and given back when InviteUser refuses (DEC-27 (2)).
        UserActionLimit::invite()->around($this->currentUser(), 'email', fn (): Invitation => $inviteUser->handle(
            $this->currentUser(),
            $organisation,
            $email,
            array_map(fn (int|string $roleId): int => (int) $roleId, $this->roles),
            Locale::from($this->locale),
        ));

        session()->flash('status', __('Invitation sent to :email.', ['email' => Str::lower($email)]));
        $this->redirectRoute($this->returnRoute(), navigate: true);
    }
};
