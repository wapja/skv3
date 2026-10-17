<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\Organisation;
use App\Support\Audit\ActivityFilters;
use App\Support\Audit\ActivityRows;
use App\Tenancy\HostScope;
use App\Tenancy\UserNames;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Activity log')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    #[Url]
    public string $event = '';

    #[Url]
    public string $subject = '';

    #[Url]
    public string $causer = '';

    /** Central hosts only; ignored on a tenant host (RK-I4). */
    #[Url]
    public string $organisation = '';

    #[Locked]
    public ?int $detailId = null;

    public bool $detailOpen = false;

    /**
     * The current page, read once per request; private properties are never dehydrated.
     *
     * @var LengthAwarePaginator<int, Activity>|null
     */
    private ?LengthAwarePaginator $activitiesPage = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        Gate::authorize(Permission::ActivityLogView);
    }

    /**
     * Back to the first page whenever a filter changes (AUD-8).
     */
    public function updated(string $property): void
    {
        if (in_array($property, ActivityFilters::NAMES, true)) {
            $this->resetPage();
        }
    }

    /**
     * Open the detail flyout for an entry this viewer may read; any other id answers 404.
     */
    public function showDetail(int $activityId): void
    {
        $activity = HostScope::read(fn () => ActivityFilters::baseQuery()->findOrFail($activityId));

        $this->detailId = (int) $activity->getKey();
        $this->detailOpen = true;
    }

    /**
     * Close the detail flyout.
     */
    public function closeDetail(): void
    {
        $this->detailOpen = false;
        $this->detailId = null;
    }

    /**
     * The filtered entries, 50 per page, newest first (AUD-3).
     *
     * Memoised in $activitiesPage so the paginate query runs once per request. PHP code calls this method,
     * never the magic computed property (Larastan level 8 has no Livewire computed support).
     *
     * @return LengthAwarePaginator<int, Activity>
     */
    #[Computed]
    public function activities(): LengthAwarePaginator
    {
        if ($this->activitiesPage instanceof LengthAwarePaginator) {
            return $this->activitiesPage;
        }

        /** @var LengthAwarePaginator<int, Activity> $activities */
        $activities = HostScope::read(fn (): LengthAwarePaginator => $this->filters()->query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50));

        return $this->activitiesPage = $activities;
    }

    /**
     * The page's entries as display rows (RL-7: App\Support\Audit\ActivityRows).
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function rows(): array
    {
        return ActivityRows::of(array_values($this->activities()->items()));
    }

    /**
     * The entry in the detail flyout, read again through the host scope.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function detail(): ?array
    {
        if ($this->detailId === null) {
            return null;
        }

        $activity = HostScope::read(fn () => ActivityFilters::baseQuery()->find($this->detailId));

        if (! $activity instanceof Activity) {
            return null;
        }

        return ActivityRows::of([$activity])[0];
    }

    /**
     * Every action of the audit vocabulary with its label.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function eventOptions(): array
    {
        $options = [];

        foreach (ActivityEvent::cases() as $event) {
            $options[$event->value] = $event->label();
        }

        return $options;
    }

    /**
     * The subject types that occur in this viewer's entries.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function subjectOptions(): array
    {
        $options = [];

        foreach (ActivityFilters::subjectTypes() as $type) {
            $options[$type] = ActivityRows::subjectTypeLabel($type);
        }

        return $options;
    }

    /**
     * The users who caused entries this viewer may read; a ?causer= outside this list is ignored (RK-M4).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function causerOptions(): array
    {
        $names = UserNames::of(ActivityFilters::causerIds());
        asort($names);

        return $names;
    }

    /**
     * Every organisation, archived ones included, for the central filter; empty on a tenant host.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function organisationOptions(): array
    {
        if (! $this->isCentral()) {
            return [];
        }

        $options = [];

        foreach (Organisation::withTrashed()->orderBy('name')->get(['id', 'name']) as $organisation) {
            $options[$organisation->id] = $organisation->name;
        }

        return $options;
    }

    /**
     * True for a super admin on a central host: every organisation's entries and the central ones.
     */
    #[Computed]
    public function isCentral(): bool
    {
        return ActivityFilters::isCentral();
    }

    private function filters(): ActivityFilters
    {
        return new ActivityFilters($this->from, $this->until, $this->event, $this->subject, $this->causer, $this->organisation);
    }
};
