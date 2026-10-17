<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\User;
use App\Tenancy\HostScope;
use App\Tenancy\UserNames;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;

/**
 * The activity viewer's six filters as one query rule, shared by the page and the CSV export (§4.8, §6.6, CD-5).
 * Values arrive as raw strings from the URL; one that is not valid for this viewer is ignored, never an error
 * (AUD-8): a date that is not Y-m-d, an unknown event, a subject type or causer that occurs in none of this
 * viewer's entries (RK-M4), and an organisation on a tenant host (RK-I4). Every query method runs its queries
 * itself, so callers on a central host call them inside HostScope::read().
 */
final readonly class ActivityFilters
{
    /** @var list<string> The filter names, as the page's #[Url] properties and the export's query string spell them. */
    public const array NAMES = ['from', 'until', 'event', 'subject', 'causer', 'organisation'];

    public function __construct(
        public string $from = '',
        public string $until = '',
        public string $event = '',
        public string $subject = '',
        public string $causer = '',
        public string $organisation = '',
    ) {}

    /**
     * The filters from a query string; an array or any other non-string value counts as empty, never as a 500.
     *
     * @param  array<array-key, mixed>  $query
     */
    public static function fromQuery(array $query): self
    {
        $value = fn (string $name): string => is_string($query[$name] ?? null) ? $query[$name] : '';

        return new self(
            $value('from'),
            $value('until'),
            $value('event'),
            $value('subject'),
            $value('causer'),
            $value('organisation'),
        );
    }

    /**
     * True for a super admin on a central host: every organisation's entries and the central ones.
     * Anyone else on a central host gets an AuthorizationException.
     */
    public static function isCentral(): bool
    {
        return HostScope::organisationId() === null;
    }

    /**
     * The viewer's entries without filters. Call inside HostScope::read().
     *
     * @return Builder<Activity>
     */
    public static function baseQuery(): Builder
    {
        $query = Activity::query();

        if (self::isCentral()) {
            // Central viewers also see archived organisations' rows, soft-deleted by the cascade (RL-7).
            $query->withTrashed();
        }

        return $query;
    }

    /**
     * The subject types that occur in this viewer's entries, sorted.
     *
     * @return list<string>
     */
    public static function subjectTypes(): array
    {
        return HostScope::read(fn (): array => array_values(self::baseQuery()
            ->whereNotNull('subject_type')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type')
            ->map(fn (mixed $type): string => (string) $type)
            ->all()));
    }

    /**
     * The ids of the users who caused entries this viewer may read; a causer outside this list is ignored (RK-M4).
     *
     * @return list<int>
     */
    public static function causerIds(): array
    {
        return HostScope::read(fn (): array => array_values(self::baseQuery()
            ->where('causer_type', new User()->getMorphClass())
            ->whereNotNull('causer_id')
            ->distinct()
            ->pluck('causer_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all()));
    }

    /**
     * The entries with every valid filter applied, unordered. Call inside HostScope::read().
     *
     * @return Builder<Activity>
     */
    public function query(): Builder
    {
        $query = self::baseQuery();
        $applied = $this->applied();
        $from = $this->day($applied['from'] ?? '');
        $until = $this->day($applied['until'] ?? '');

        if ($from instanceof DateTimeImmutable) {
            $query->where('created_at', '>=', $from);
        }

        if ($until instanceof DateTimeImmutable) {
            $query->where('created_at', '<', $until->add(new DateInterval('P1D')));
        }

        if (isset($applied['event'])) {
            $query->where('event', $applied['event']);
        }

        if (isset($applied['subject'])) {
            $query->where('subject_type', $applied['subject']);
        }

        if (isset($applied['causer'])) {
            $query->where('causer_type', new User()->getMorphClass())->where('causer_id', (int) $applied['causer']);
        }

        if (isset($applied['organisation'])) {
            $query->where('organisation_id', (int) $applied['organisation']);
        }

        return $query;
    }

    /**
     * The filters that take effect, by name, in NAMES order; ignored values are left out. The export records
     * these in its audit entry.
     *
     * @return array<string, string>
     */
    public function applied(): array
    {
        $causerId = $this->digits($this->causer);
        $valid = [
            'from' => $this->day($this->from) instanceof DateTimeImmutable,
            'until' => $this->day($this->until) instanceof DateTimeImmutable,
            'event' => ActivityEvent::tryFrom($this->event) !== null,
            'subject' => $this->subject !== '' && in_array($this->subject, self::subjectTypes(), true),
            // As the page's causer list: a purged user's id names nobody, so it is ignored too.
            'causer' => $causerId !== null && array_key_exists($causerId, UserNames::of(self::causerIds())),
            'organisation' => $this->digits($this->organisation) !== null && self::isCentral(),
        ];
        $applied = [];

        foreach ($this->values() as $name => $value) {
            if ($valid[$name]) {
                $applied[$name] = $value;
            }
        }

        return $applied;
    }

    /**
     * The non-empty filters as a query string array, for the export link. The export validates them again.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter($this->values(), fn (string $value): bool => $value !== '');
    }

    /**
     * Every filter by name, in NAMES order.
     *
     * @return array{from: string, until: string, event: string, subject: string, causer: string, organisation: string}
     */
    private function values(): array
    {
        return [
            'from' => $this->from,
            'until' => $this->until,
            'event' => $this->event,
            'subject' => $this->subject,
            'causer' => $this->causer,
            'organisation' => $this->organisation,
        ];
    }

    private function digits(string $value): ?int
    {
        return ctype_digit($value) ? (int) $value : null;
    }

    /**
     * A Y-m-d filter value as the start of that day in the app timezone; anything else, and a year before 1 (which
     * PostgreSQL refuses: "date/time field value out of range"), is ignored (plan review K9).
     */
    private function day(string $value): ?DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone((string) config('app.timezone')));

        return $day !== false && $day->format('Y-m-d') === $value && (int) $day->format('Y') >= 1 ? $day : null;
    }
}
