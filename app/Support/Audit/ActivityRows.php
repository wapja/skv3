<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\Activity;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\HostScope;
use App\Tenancy\UserNames;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * Audit entries as display rows, for the activity viewer and the CSV export (§4.8, §6.6): one rule for names,
 * labels and what a tenant viewer may see. Platform staff show up in a tenant's log by name only (RL-7, RK-D1):
 * an entry that names anyone other than this organisation's own users who are not super admins shows no IP
 * address or user agent, and such a user's own field changes stay hidden. Callers pass only entries they read
 * through HostScope::read(); names are looked up for the ids in those entries only (RL-9, RK-M4).
 *
 * @phpstan-type ActivityRow array{id: int, when: string, event: string, event_key: string, causer: string, subject: string, organisation: string|null, changes: list<array{attribute: string, old: string, new: string}>, details: string|null, ip_address: string|null, user_agent: string|null, properties: string}
 */
final class ActivityRows
{
    /**
     * The entries as rows, in the order given, with one lookup each for names, organisations and members.
     *
     * @param  list<Activity>  $activities
     * @return list<ActivityRow>
     */
    public static function of(array $activities): array
    {
        $userIds = self::userIdsOf($activities);
        $names = UserNames::of($userIds);
        $organisations = self::organisationNames($activities);
        $members = self::memberIdsAmong($userIds);
        $rows = [];

        foreach ($activities as $activity) {
            $rows[] = self::row($activity, $names, $organisations, $members);
        }

        return $rows;
    }

    /** A subject type's label: User, Organisation, Invitation, Role, else the class's base name. */
    public static function subjectTypeLabel(string $type): string
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
     * @param  array<int, string>  $names
     * @param  array<int, string>  $organisations
     * @param  list<int>|null  $members  see memberIdsAmong()
     * @return ActivityRow
     */
    private static function row(Activity $activity, array $names, array $organisations, ?array $members): array
    {
        $hidesConnection = $members !== null && array_diff(self::userIdsOf([$activity]), $members) !== [];
        $subjectId = self::isUserType($activity->subject_type) ? (int) $activity->subject_id : null;
        $hidesChanges = $members !== null && $subjectId !== null && ! in_array($subjectId, $members, true);
        $changes = [];

        foreach ($hidesChanges ? [] : $activity->changeSummary() as $change) {
            $changes[] = [
                'attribute' => $change['attribute'],
                'old' => self::displayValue($change['old']),
                'new' => self::displayValue($change['new']),
            ];
        }

        $key = (string) ($activity->event ?? $activity->description);

        return [
            'id' => (int) $activity->getKey(),
            'when' => $activity->created_at?->format('Y-m-d H:i') ?? '',
            // An unknown key (older rows) renders raw.
            'event' => $activity->kitEvent()?->label() ?? $key,
            'event_key' => $key,
            'causer' => self::causerLabel($activity, $names),
            'subject' => self::subjectLabel($activity, $names),
            // memberIdsAmong() gives null on a central host only, the one place with an organisation column.
            'organisation' => $members === null ? self::organisationLabel($activity->organisation_id, $organisations) : null,
            'changes' => $changes,
            'details' => self::details($activity),
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
    private static function userIdsOf(array $activities): array
    {
        $ids = [];

        foreach ($activities as $activity) {
            $ids[] = self::isUserType($activity->causer_type) ? $activity->causer_id : null;
            $ids[] = self::isUserType($activity->subject_type) ? $activity->subject_id : null;
            $ids[] = self::userId($activity->getProperty('impersonated_as'));
            $ids[] = self::userId($activity->getProperty('impersonator_id'));
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
    private static function memberIdsAmong(array $ids): ?array
    {
        if (ActivityFilters::isCentral()) {
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
    private static function organisationNames(array $activities): array
    {
        if (! ActivityFilters::isCentral()) {
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
    private static function causerLabel(Activity $activity, array $names): string
    {
        $causerId = self::isUserType($activity->causer_type) ? $activity->causer_id : null;
        $impersonatorId = self::userId($activity->getProperty('impersonator_id'));
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

        $impersonatedId = self::userId($activity->getProperty('impersonated_as'));

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
    private static function subjectLabel(Activity $activity, array $names): string
    {
        if ($activity->subject_type === null) {
            return '—';
        }

        $label = self::subjectTypeLabel($activity->subject_type).' #'.$activity->subject_id;
        $storedName = $activity->getProperty('name');
        $name = self::isUserType($activity->subject_type)
            ? ($names[(int) $activity->subject_id] ?? null)
            : (is_string($storedName) ? $storedName : null);

        return $name === null ? $label : $label.' · '.$name;
    }

    /**
     * Central rows without an organisation are platform entries; a missing one was purged.
     *
     * @param  array<int, string>  $organisations
     */
    private static function organisationLabel(?int $organisationId, array $organisations): string
    {
        if ($organisationId === null) {
            return __('Platform');
        }

        return $organisations[$organisationId] ?? __('Purged organisation');
    }

    /**
     * The reason column: the stated reason, or what a failed sign-in typed (RL-7).
     */
    private static function details(Activity $activity): ?string
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

    private static function displayValue(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? __('Yes') : __('No'),
            is_scalar($value) => (string) $value,
            is_array($value) && array_is_list($value) && array_filter($value, fn (mixed $item): bool => ! is_scalar($item)) === [] => implode(', ', array_map(fn (mixed $item): string => (string) $item, $value)),
            default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        };
    }

    private static function isUserType(?string $type): bool
    {
        return $type !== null && (Relation::getMorphedModel($type) ?? $type) === User::class;
    }

    private static function userId(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
