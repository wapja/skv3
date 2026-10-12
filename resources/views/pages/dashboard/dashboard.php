<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Authorization\Role;
use App\Concerns\ResolvesCurrentUser;
use App\Models\Activity;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Support\Dashboard\OnboardingChecklist;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\HostScope;
use App\Tenancy\UserNames;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    use ResolvesCurrentUser;

    /**
     * The organisation this host belongs to, or null on central hosts ("Platform").
     */
    #[Computed]
    public function organisationName(): ?string
    {
        return Tenant::current()?->name;
    }

    /**
     * The user's roles in this host's team, translated for system roles (§4.10).
     *
     * @return list<string>
     */
    #[Computed]
    public function roleLabels(): array
    {
        $names = $this->currentUser()->roles()->pluck('name')->all();

        return array_values(array_map(
            fn (mixed $name): string => Role::tryFrom((string) $name)?->label() ?? (string) $name,
            $names,
        ));
    }

    /**
     * Stat cards the user may see, counted for this host (RL-11).
     *
     * @return list<array{key: string, label: string, value: int, url: string}>
     */
    #[Computed]
    public function stats(): array
    {
        $user = $this->currentUser();
        $stats = [];

        if ($user->can(Permission::UsersView)) {
            $stats[] = ['key' => 'users', 'label' => __('Users'),
                'value' => HostScope::read(fn (): int => User::query()->count()), 'url' => route('users.index')];
            $stats[] = ['key' => 'pending-invitations', 'label' => __('Pending invitations'),
                'value' => HostScope::read(fn (): int => Invitation::query()->whereNull('accepted_at')->count()),
                'url' => route('invitations.pending')];
        }

        if ($user->can(Permission::OrganisationsView) && ! Tenant::check()) {
            $stats[] = ['key' => 'organisations', 'label' => __('Organisations'),
                'value' => Organisation::query()->count(), 'url' => route('organisations.index')];
        }

        return $stats;
    }

    /**
     * The last ten entries of this host's activity, without IP addresses, user agents or field changes (RL-11).
     *
     * @return list<array{event: string, causer: string|null, when: string}>|null
     */
    #[Computed]
    public function recentActivity(): ?array
    {
        if (! $this->currentUser()->can(Permission::ActivityLogView)) {
            return null;
        }

        /** @var list<Activity> $entries */
        $entries = HostScope::read(fn (): array => Activity::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->all());

        $userType = new User()->getMorphClass();
        $causerIds = array_values(array_unique(array_filter(array_map(
            fn (Activity $activity): ?int => $activity->causer_type === $userType && $activity->causer_id !== null
                ? (int) $activity->causer_id : null,
            $entries,
        ))));
        $names = UserNames::of($causerIds);

        return array_map(fn (Activity $activity): array => [
            'event' => $activity->kitEvent()?->label() ?? (string) $activity->event,
            'causer' => $activity->causer_type === $userType ? $names[(int) $activity->causer_id] ?? null : null,
            'when' => $activity->created_at?->diffForHumans() ?? '',
        ], $entries);
    }

    /**
     * The onboarding checklist, or an empty list when it is complete or not for this user (§6.5).
     *
     * @return list<array{key: string, label: string, done: bool, url: string|null}>
     */
    #[Computed]
    public function checklist(): array
    {
        $items = OnboardingChecklist::for($this->currentUser());

        return OnboardingChecklist::isComplete($items) ? [] : $items;
    }
};
