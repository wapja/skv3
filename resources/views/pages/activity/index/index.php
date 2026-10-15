<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\HostScope;
use App\Tenancy\UserNames;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role as RoleModel;

new #[Title('Activity log')] class extends Component
{
    use WithPagination;

    /** @var list<string> The filters that send the list back to its first page (AUD-8). */
    private const array FILTERS = ['from', 'until', 'event', 'subject', 'causer', 'organisation'];

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
        if (in_array($property, self::FILTERS, true)) {
            $this->resetPage();
        }
    }

    /**
     * Open the detail flyout for an entry this viewer may read; any other id answers 404.
     */
    public function showDetail(int $activityId): void
    {
        $activity = HostScope::read(fn () => $this->baseQuery()->findOrFail($activityId));

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
        $activities = HostScope::read(fn (): LengthAwarePaginator => $this->filteredQuery()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50));

        return $this->activitiesPage = $activities;
    }

    /**
     * The page's entries as display rows.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function rows(): array
    {
        $names = $this->causerNames();
        $items = $this->activities()->items();
        $organisations = $this->organisationNames($items);
        $members = $this->memberIdsAmong($this->userIdsOf($items));
        $rows = [];

        foreach ($items as $activity) {
            $rows[] = $this->row($activity, $names, $organisations, $members);
        }

        return $rows;
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

        $activity = HostScope::read(fn () => $this->baseQuery()->find($this->detailId));

        if (! $activity instanceof Activity) {
            return null;
        }

        $userIds = $this->userIdsOf([$activity]);

        return $this->row($activity, UserNames::of($userIds), $this->organisationNames([$activity]), $this->memberIdsAmong($userIds));
    }

    /**
     * Names of the causers, impersonated users, impersonators and user subjects on this page,
     * across organisations and including deleted users. Only ids from rows read through the
     * host scope are looked up (RL-9, RK-M4).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function causerNames(): array
    {
        return UserNames::of($this->userIdsOf($this->activities()->items()));
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
        $types = HostScope::read(fn () => $this->baseQuery()
            ->whereNotNull('subject_type')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type')
            ->all());

        $options = [];

        foreach ($types as $type) {
            $options[(string) $type] = $this->subjectTypeLabel((string) $type);
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
        $ids = HostScope::read(fn () => $this->baseQuery()
            ->where('causer_type', new User()->getMorphClass())
            ->whereNotNull('causer_id')
            ->distinct()
            ->pluck('causer_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());

        $names = UserNames::of(array_values($ids));
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
        return HostScope::organisationId() === null;
    }

    /**
     * The viewer's entries without filters. Call inside HostScope::read().
     *
     * @return Builder<Activity>
     */
    private function baseQuery(): Builder
    {
        $query = Activity::query();

        if ($this->isCentral()) {
            // Central viewers also see archived organisations' rows, soft-deleted by the cascade (RL-7).
            $query->withTrashed();
        }

        return $query;
    }

    /**
     * The entries with every valid filter applied; invalid filter values are ignored (AUD-8).
     * Call inside HostScope::read().
     *
     * @return Builder<Activity>
     */
    private function filteredQuery(): Builder
    {
        $query = $this->baseQuery();
        $from = $this->day($this->from);
        $until = $this->day($this->until);

        if ($from instanceof DateTimeImmutable) {
            $query->where('created_at', '>=', $from);
        }

        if ($until instanceof DateTimeImmutable) {
            $query->where('created_at', '<', $until->add(new DateInterval('P1D')));
        }

        if (ActivityEvent::tryFrom($this->event) !== null) {
            $query->where('event', $this->event);
        }

        if (array_key_exists($this->subject, $this->subjectOptions())) {
            $query->where('subject_type', $this->subject);
        }

        $causerId = $this->digits($this->causer);

        if ($causerId !== null && array_key_exists($causerId, $this->causerOptions())) {
            $query->where('causer_type', new User()->getMorphClass())->where('causer_id', $causerId);
        }

        $organisationId = $this->digits($this->organisation);

        if ($organisationId !== null && $this->isCentral()) {
            $query->where('organisation_id', $organisationId);
        }

        return $query;
    }

    /**
     * One entry as a display row. Platform staff show up in a tenant's log by name only (RL-7, RK-D1): an entry
     * that names anyone other than this organisation's own users who are not super admins shows no IP address
     * or user agent, and such a user's own field changes stay hidden.
     *
     * @param  array<int, string>  $names
     * @param  array<int, string>  $organisations
     * @param  list<int>|null  $members  see memberIdsAmong()
     * @return array{id: int, when: string, event: string, event_key: string, causer: string, subject: string, organisation: string|null, changes: list<array{attribute: string, old: string, new: string}>, details: string|null, ip_address: string|null, user_agent: string|null, properties: string}
     */
    private function row(Activity $activity, array $names, array $organisations, ?array $members): array
    {
        $hidesConnection = $members !== null && array_diff($this->userIdsOf([$activity]), $members) !== [];
        $subjectId = $this->isUserType($activity->subject_type) ? (int) $activity->subject_id : null;
        $hidesChanges = $members !== null && $subjectId !== null && ! in_array($subjectId, $members, true);
        $changes = [];

        foreach ($hidesChanges ? [] : $activity->changeSummary() as $change) {
            $changes[] = [
                'attribute' => $change['attribute'],
                'old' => $this->displayValue($change['old']),
                'new' => $this->displayValue($change['new']),
            ];
        }

        $key = (string) ($activity->event ?? $activity->description);

        return [
            'id' => (int) $activity->getKey(),
            'when' => $activity->created_at?->format('Y-m-d H:i') ?? '',
            // An unknown key (older rows) renders raw.
            'event' => $activity->kitEvent()?->label() ?? $key,
            'event_key' => $key,
            'causer' => $this->causerLabel($activity, $names),
            'subject' => $this->subjectLabel($activity, $names),
            'organisation' => $this->isCentral() ? $this->organisationLabel($activity->organisation_id, $organisations) : null,
            'changes' => $changes,
            'details' => $this->details($activity),
            'ip_address' => $hidesConnection ? null : $activity->ip_address,
            'user_agent' => $hidesConnection ? null : $activity->user_agent,
            'properties' => json_encode(
                $activity->properties?->toArray() ?? [],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
        ];
    }

    /**
     * User ids the rows refer to: causers, user subjects, impersonated users and impersonators.
     *
     * @param  array<int, Activity>  $activities
     * @return list<int>
     */
    private function userIdsOf(array $activities): array
    {
        $ids = [];

        foreach ($activities as $activity) {
            $ids[] = $this->isUserType($activity->causer_type) ? $activity->causer_id : null;
            $ids[] = $this->isUserType($activity->subject_type) ? $activity->subject_id : null;
            $ids[] = $this->userId($activity->getProperty('impersonated_as'));
            $ids[] = $this->userId($activity->getProperty('impersonator_id'));
        }

        return array_values(array_unique(array_filter($ids, fn (?int $id): bool => $id !== null)));
    }

    /**
     * On a tenant host, the ids among $ids of this organisation's own users who are not super admins, deleted
     * ones included, read through the host scope; null on a central host, where every entry shows in full.
     *
     * @param  list<int>  $ids
     * @return list<int>|null
     */
    private function memberIdsAmong(array $ids): ?array
    {
        if ($this->isCentral()) {
            return null;
        }

        if ($ids === []) {
            return [];
        }

        return HostScope::read(fn (): array => array_values(User::withTrashed()
            ->whereKey($ids)
            ->where('is_super_admin', false)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all()));
    }

    /**
     * Names of the organisations of the given rows, archived ones included; central only.
     *
     * @param  array<int, Activity>  $activities
     * @return array<int, string>
     */
    private function organisationNames(array $activities): array
    {
        if (! $this->isCentral()) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map(
            fn (Activity $activity): ?int => $activity->organisation_id,
            $activities,
        ))));
        $names = [];

        foreach (Organisation::withTrashed()->whereKey($ids)->get(['id', 'name']) as $organisation) {
            $names[$organisation->id] = $organisation->name;
        }

        return $names;
    }

    /**
     * The causer's name, "as :name" while impersonating; the impersonator of a stop that was logged without a
     * causer; "Command line" or ":name via the command line" for entries a command wrote (CD-7); "System" for the
     * rest without one.
     *
     * @param  array<int, string>  $names
     */
    private function causerLabel(Activity $activity, array $names): string
    {
        $causerId = $this->isUserType($activity->causer_type) ? $activity->causer_id : null;
        $impersonatorId = $this->userId($activity->getProperty('impersonator_id'));
        $console = $activity->getProperty('via') === 'console';

        $label = match (true) {
            $causerId !== null => $names[$causerId] ?? __('Deleted user'),
            $impersonatorId !== null => $names[$impersonatorId] ?? __('Deleted user'),
            $console => __('Command line'),
            default => __('System'),
        };

        if ($console && ($causerId !== null || $impersonatorId !== null)) {
            $label = __(':name via the command line', ['name' => $label]);
        }

        $impersonatedId = $this->userId($activity->getProperty('impersonated_as'));

        if ($impersonatedId === null) {
            return $label;
        }

        return $label.' '.__('as :name', ['name' => $names[$impersonatedId] ?? __('Deleted user')]);
    }

    /**
     * The subject's type and id, with the user's name or a stored name where there is one.
     *
     * @param  array<int, string>  $names
     */
    private function subjectLabel(Activity $activity, array $names): string
    {
        if ($activity->subject_type === null) {
            return '—';
        }

        $label = $this->subjectTypeLabel($activity->subject_type).' #'.$activity->subject_id;
        $storedName = $activity->getProperty('name');
        $name = $this->isUserType($activity->subject_type)
            ? ($names[(int) $activity->subject_id] ?? null)
            : (is_string($storedName) ? $storedName : null);

        return $name === null ? $label : $label.' · '.$name;
    }

    private function subjectTypeLabel(string $type): string
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        return match ($class) {
            User::class => __('User'),
            Organisation::class => __('Organisation'),
            Invitation::class => __('Invitation'),
            RoleModel::class => __('Role'),
            default => class_basename($class),
        };
    }

    /**
     * Central rows without an organisation are platform entries; a missing one was purged.
     *
     * @param  array<int, string>  $organisations
     */
    private function organisationLabel(?int $organisationId, array $organisations): string
    {
        if ($organisationId === null) {
            return __('Platform');
        }

        return $organisations[$organisationId] ?? __('Purged organisation');
    }

    /**
     * The reason column: the stated reason, or what a failed sign-in typed (RL-7).
     */
    private function details(Activity $activity): ?string
    {
        $reason = $activity->getProperty('reason');

        if (is_string($reason) && $reason !== '') {
            return $reason;
        }

        if ($activity->getProperty('invalid_address') === true) {
            return __('Not an email address');
        }

        $email = $activity->getProperty('email');

        return is_string($email) && $email !== '' ? $email : null;
    }

    private function displayValue(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? __('Yes') : __('No'),
            is_scalar($value) => (string) $value,
            is_array($value) && array_is_list($value) && array_filter($value, fn (mixed $item): bool => ! is_scalar($item)) === [] => implode(', ', array_map(fn (mixed $item): string => (string) $item, $value)),
            default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        };
    }

    private function isUserType(?string $type): bool
    {
        return $type !== null && (Relation::getMorphedModel($type) ?? $type) === User::class;
    }

    private function userId(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    private function digits(string $value): ?int
    {
        return ctype_digit($value) ? (int) $value : null;
    }

    /**
     * A Y-m-d filter value as the start of that day in the app timezone; anything else is ignored.
     */
    private function day(string $value): ?DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone((string) config('app.timezone')));

        return $day !== false && $day->format('Y-m-d') === $value ? $day : null;
    }
};
