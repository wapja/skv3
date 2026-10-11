<?php

declare(strict_types=1);

use App\Actions\Invitations\AcceptInvitation;
use App\Actions\Invitations\RemindInvitation;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Invitation;
use App\Models\User;
use App\Tenancy\Auth\HostLoginRule;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The invitation accept page (§4.5, RL-15). The route is signed, but Livewire updates skip the
 * signature, so every request re-resolves the locked invitation id under the tenant scope and the
 * actions re-check its state.
 */
new #[Layout('layouts::auth')] #[Title('Accept invitation')] class extends Component
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    #[Locked]
    public int $invitationId;

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    private ?Invitation $resolvedInvitation = null;

    /**
     * Find the invitation on this host only: another organisation's host, the apex and the admin host answer 404 (W14).
     */
    public function mount(string $token): void
    {
        $invitation = Invitation::findByToken($token);

        if (! $invitation instanceof Invitation) {
            abort(404);
        }

        $this->invitationId = $invitation->id;
        $this->resolvedInvitation = $invitation;
        $this->useInviteeLocale();
    }

    /**
     * Keep the invitee's language on every Livewire update (X12).
     */
    public function hydrate(): void
    {
        $this->useInviteeLocale();
    }

    /**
     * The page state, in this order: accepted, expired, signed in, form (OR-I10).
     *
     * @return 'accepted'|'expired'|'signed-in'|'form'
     */
    #[Computed]
    public function state(): string
    {
        $invitation = $this->invitation();

        return match (true) {
            $invitation->isAccepted() => 'accepted',
            $invitation->isExpired() => 'expired',
            Auth::check() => 'signed-in',
            default => 'form',
        };
    }

    /**
     * Whether the signed-in visitor may send the invitation again (INV-11).
     */
    #[Computed]
    public function mayResend(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('remind', $this->invitation());
    }

    #[Computed]
    public function organisationName(): string
    {
        return $this->invitation()->organisation->name ?? '';
    }

    /**
     * Activate the account with the chosen name and password and sign the invitee in on this host (W14).
     */
    public function accept(AcceptInvitation $acceptInvitation, HostLoginRule $hostLoginRule): void
    {
        // A forged update from the "signed in as" state never replaces the visitor's session (RK-M3).
        abort_if(Auth::check(), 403);

        $this->validate([
            'name' => $this->nameRules(),
            'password' => $this->passwordRules(),
        ]);

        $user = $acceptInvitation->handle($this->invitation(), $this->name, $this->password);

        abort_unless($hostLoginRule->allows($user), 403);

        $this->reset('password', 'password_confirmation');

        Auth::guard('web')->login($user);
        session()->regenerate();
        session()->flash('status', __('Welcome, your account is ready.'));

        $this->redirectRoute('dashboard');
    }

    /**
     * Send the invitation again from the expired state (INV-11); RemindInvitation authorises.
     */
    public function resend(RemindInvitation $remindInvitation): void
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $remindInvitation->handle($actor, $this->invitation());

        Flux::toast(variant: 'success', text: __('Invitation reminder sent.'));
    }

    private function invitation(): Invitation
    {
        return $this->resolvedInvitation ??= Invitation::query()->findOrFail($this->invitationId);
    }

    private function useInviteeLocale(): void
    {
        app()->setLocale($this->invitation()->user->locale->value);
    }
};
