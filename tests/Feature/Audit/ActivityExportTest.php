<?php

declare(strict_types=1);

/** covers §4.8, §6.6 */

use App\Actions\Audit\RecordActivity;
use App\Authorization\SetSuperAdmin;
use App\Enums\ActivityEvent;
use App\Enums\Locale;
use App\Models\Activity;
use App\Models\Organisation;
use App\Models\User;
use App\Support\RateLimits\ThrottleKey;
use App\Tenancy\Facades\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role as RoleModel;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Records an entry whose properties.reason holds a marker; the export's properties column carries it, so tests
 * find rows by their marker. Subject-mode events are tagged with $organisation.
 */
$record = fn (ActivityEvent $event, ?Organisation $organisation, string $marker, ?User $causer = null, ?Model $subject = null): Activity => app(RecordActivity::class)->handle(
    $event,
    $subject,
    ['reason' => $marker],
    causer: $causer,
    organisation: $organisation,
    defaultCauser: false,
);

/**
 * The streamed body without its byte order mark, as rows of cells.
 *
 * @return list<list<string>>
 */
$csv = function (TestResponse $response): array {
    $content = $response->streamedContent();
    expect($content)->toStartWith("\u{FEFF}");

    $file = new SplTempFileObject;
    $file->fwrite(substr($content, 3));
    $file->rewind();
    $file->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY);
    $file->setCsvControl(',', '"', '');
    $rows = [];

    foreach ($file as $cells) {
        if (is_array($cells)) {
            $rows[] = array_map(fn (mixed $cell): string => (string) $cell, array_values($cells));
        }
    }

    return $rows;
};

/** The data rows (header dropped) whose properties column holds one of the markers, as marker => row. */
$byMarker = function (array $rows): array {
    $found = [];

    foreach (array_slice($rows, 1) as $row) {
        $properties = json_decode((string) end($row), true);
        $marker = is_array($properties) ? ($properties['reason'] ?? null) : null;

        if (is_string($marker)) {
            $found[$marker] = $row;
        }
    }

    return $found;
};

it('streams a CSV download with a byte order mark and a header row (§6.6)', function () use ($record, $csv): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create(['name' => 'Mia Member']);
    $entry = $record(ActivityEvent::UserUpdated, $organisation, 'the-entry', $member, $member);
    $entry->forceFill(['ip_address' => '203.0.113.5', 'user_agent' => 'Agent/1.0'])->save();
    asOrgAdmin($organisation);

    $response = $this->get(route('activity.export'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload();

    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class)
        ->and((string) $response->headers->get('Content-Disposition'))->toMatch('/filename=activity-log-\d{4}-\d{2}-\d{2}-\d{6}\.csv/');

    $rows = $csv($response);

    expect($rows[0])->toBe(['Date', 'Event key', 'Action', 'Done by', 'Subject', 'IP address', 'User agent', 'Properties'])
        ->and($rows[1])->toBe([
            $entry->created_at?->toIso8601String(),
            'user.updated',
            'User updated',
            'Mia Member',
            'User #'.$member->id.' · Mia Member',
            '203.0.113.5',
            'Agent/1.0',
            '{"reason":"the-entry"}',
        ]);
});

it('writes the header and labels in the viewer\'s language', function () use ($record, $csv): void {
    $organisation = Organisation::factory()->create();
    $record(ActivityEvent::OrganisationUpdated, $organisation, 'dutch-entry');
    asOrgAdmin($organisation)->forceFill(['locale' => Locale::Dutch])->save();

    $rows = $csv($this->get(route('activity.export'))->assertOk());

    expect($rows[0])->toBe(['Datum', 'Gebeurtenissleutel', 'Actie', 'Uitgevoerd door', 'Onderwerp', 'IP-adres', 'Browser', 'Eigenschappen'])
        ->and($rows[1][2])->toBe(__('Organisation updated', [], 'nl'));
});

it('streams the entries in id order, whatever their dates', function () use ($record, $csv, $byMarker): void {
    $organisation = Organisation::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00'));
    $record(ActivityEvent::OrganisationUpdated, $organisation, 'first-id');
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00'));
    $record(ActivityEvent::OrganisationUpdated, $organisation, 'second-id');
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00'));
    $record(ActivityEvent::OrganisationUpdated, $organisation, 'third-id');
    $this->travelBack();
    asOrgAdmin($organisation);

    expect(array_keys($byMarker($csv($this->get(route('activity.export'))->assertOk()))))
        ->toBe(['first-id', 'second-id', 'third-id']);
});

it('reads the log 500 entries per query while it streams, so a large log never sits in memory', function () use ($csv): void {
    $organisation = Organisation::factory()->create();
    Activity::factory()->count(501)->for($organisation)->create();
    asOrgAdmin($organisation);
    $response = $this->get(route('activity.export'))->assertOk();
    $chunkQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$chunkQueries): void {
        if (str_contains($query->sql, 'activity_log') && str_contains($query->sql, 'limit 500')) {
            $chunkQueries++;
        }
    });

    // 501 entries and the export's own audit entry, recorded before the first byte: two reads of at most 500.
    expect($csv($response))->toHaveCount(1 + 502)
        ->and($chunkQueries)->toBe(2);
});

it('honours each of the viewer\'s filters', function (array $query, array $expected) use ($record, $csv, $byMarker): void {
    $first = Organisation::factory()->create(['name' => 'First Org']);
    $second = Organisation::factory()->create(['name' => 'Second Org']);
    $member = User::factory()->for($first)->create();
    $actor = User::factory()->for($second)->create(['name' => 'Ada Actor']);
    $role = RoleModel::query()->create(['name' => 'Support', 'guard_name' => 'web', 'team_id' => $first->id]);

    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00'));
    $record(ActivityEvent::UserUpdated, $first, 'october-first', $member, $member);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00'));
    $record(ActivityEvent::RoleCreated, $first, 'october-fifth', null, $role);
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00'));
    $record(ActivityEvent::UserUpdated, $second, 'october-ninth', $actor, $actor);
    $this->travelBack();
    asSuperAdmin();
    onAdminHost();

    $query = array_map(fn (string $value): string => match ($value) {
        'actor' => (string) $actor->id,
        'second' => (string) $second->id,
        default => $value,
    }, $query);

    $markers = array_keys($byMarker($csv($this->get(route('activity.export', $query))->assertOk())));
    sort($markers);

    expect($markers)->toBe($expected);
})->with([
    'from and until' => [['from' => '2026-10-02', 'until' => '2026-10-05'], ['october-fifth']],
    'until, inclusive of the whole day' => [['until' => '2026-10-01'], ['october-first']],
    'action' => [['event' => 'role.created'], ['october-fifth']],
    'subject type' => [['subject' => User::class], ['october-first', 'october-ninth']],
    'done by' => [['causer' => 'actor'], ['october-ninth']],
    'organisation' => [['organisation' => 'second'], ['october-ninth']],
    'invalid values are ignored, as on the page' => [
        ['from' => '2026-13-45', 'event' => 'nope', 'subject' => 'Nope\\Model', 'causer' => 'abc', 'organisation' => 'x'],
        ['october-fifth', 'october-first', 'october-ninth'],
    ],
]);

it('answers 200 with every entry for a hostile query string', function (string $host) use ($record, $csv, $byMarker): void {
    $organisation = Organisation::factory()->create();
    $record(ActivityEvent::OrganisationUpdated, $organisation, 'some-entry');

    if ($host === 'tenant') {
        asOrgAdmin($organisation);
    } else {
        asSuperAdmin();
        onAdminHost();
    }

    $response = $this->get(route('activity.export').'?from[]=x&until=2026-13-45&event[]=y&subject=Nope%5CModel&causer=abc&organisation[]=1')
        ->assertOk();

    expect($byMarker($csv($response)))->toHaveKey('some-entry');

    // Year 0 round-trips through createFromFormat(), but PostgreSQL refuses it mid-stream (plan review K9).
    $yearZero = $this->get(route('activity.export', ['from' => '0000-01-01', 'until' => '0000-01-01']))->assertOk();

    expect($byMarker($csv($yearZero)))->toHaveKey('some-entry');
})->with(['tenant', 'admin']);

it('exports only the organisation\'s own entries on a tenant host, whatever the query says (AUD-3, RK-I4, RK-M4)', function () use ($record, $csv, $byMarker): void {
    $own = Organisation::factory()->create();
    $other = Organisation::factory()->create(['name' => 'Other Org']);
    $foreigner = User::factory()->for($other)->create(['name' => 'Bruno Foreign']);
    $record(ActivityEvent::OrganisationUpdated, $own, 'own-entry');
    $record(ActivityEvent::OrganisationUpdated, $other, 'foreign-entry', $foreigner);
    $record(ActivityEvent::OrganisationCreated, null, 'platform-entry');
    asOrgAdmin($own);

    foreach ([[], ['organisation' => $other->id], ['causer' => $foreigner->id]] as $query) {
        $response = $this->get(route('activity.export', $query))->assertOk();
        $content = $response->streamedContent();
        $markers = array_keys($byMarker($csv($response)));

        expect($markers)->toBe(['own-entry'])
            ->and($content)->not->toContain('Bruno Foreign')
            ->not->toContain('Other Org');
    }
});

it('exports every entry with its organisation to a super admin on a central host, archived organisations\' ones included (AUD-11, RL-7)', function () use ($record, $csv, $byMarker): void {
    $active = Organisation::factory()->create(['name' => 'Active Org']);
    $archived = Organisation::factory()->create(['name' => 'Archived Org']);
    $record(ActivityEvent::OrganisationUpdated, $active, 'active-entry');
    $record(ActivityEvent::OrganisationUpdated, $archived, 'archived-entry');
    $record(ActivityEvent::OrganisationCreated, null, 'platform-entry');
    $archived->delete(); // the cascade soft-deletes its activity rows (AUD-12)
    asSuperAdmin();
    onAdminHost();

    $rows = $csv($this->get(route('activity.export'))->assertOk());
    $found = $byMarker($rows);

    expect($rows[0])->toBe(['Date', 'Event key', 'Action', 'Done by', 'Subject', 'Organisation', 'IP address', 'User agent', 'Properties'])
        ->and(array_keys($found))->toEqualCanonicalizing(['active-entry', 'archived-entry', 'platform-entry'])
        ->and($found['active-entry'][5])->toBe('Active Org')
        ->and($found['archived-entry'][5])->toBe('Archived Org')
        ->and($found['platform-entry'][5])->toBe('Platform');
});

it('leaves platform staff\'s IP address and user agent empty for tenant viewers, not for central ones (RL-7)', function () use ($csv): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $promoted = User::factory()->for($organisation)->create();
    app(SetSuperAdmin::class)->handle($promoted, true);
    $superAdmin = User::factory()->superAdmin()->create();
    $located = function (Activity $activity, string $marker, string $ip): void {
        $activity->forceFill([
            'ip_address' => $ip,
            'user_agent' => 'Agent/'.$ip,
            'properties' => [...$activity->properties?->all() ?? [], 'reason' => $marker],
        ])->save();
    };
    $located(Tenant::run($organisation, fn (): Activity => app(RecordActivity::class)->handle(
        ActivityEvent::Login,
        $superAdmin,
        causer: $superAdmin,
        defaultCauser: false,
    )), 'staff-sign-in', '198.51.100.11');
    $located(app(RecordActivity::class)->handle(
        ActivityEvent::UserUpdated,
        $member,
        causer: $superAdmin,
        organisation: $organisation,
        defaultCauser: false,
    ), 'staff-edit', '198.51.100.22');
    $located(app(RecordActivity::class)->handle(
        ActivityEvent::UserUpdated,
        $promoted,
        causer: $promoted,
        organisation: $organisation,
        defaultCauser: false,
    ), 'promoted-self', '198.51.100.33');
    $located(app(RecordActivity::class)->handle(
        ActivityEvent::UserUpdated,
        $member,
        causer: $member,
        organisation: $organisation,
        defaultCauser: false,
    ), 'member-self', '203.0.113.44');
    asOrgAdmin($organisation);

    $connection = [];

    foreach (array_slice($csv($this->get(route('activity.export'))->assertOk()), 1) as $row) {
        $properties = json_decode($row[7], true);

        if (is_array($properties) && is_string($properties['reason'] ?? null)) {
            $connection[$properties['reason']] = [$row[5], $row[6]];
        }
    }

    expect($connection)->toBe([
        'staff-sign-in' => ['', ''],
        'staff-edit' => ['', ''],
        'promoted-self' => ['', ''],
        'member-self' => ['203.0.113.44', 'Agent/203.0.113.44'],
    ]);

    asSuperAdmin();
    onAdminHost();

    $central = $csv($this->get(route('activity.export'))->assertOk());
    $centralIps = array_column(array_slice($central, 1), 6);

    expect($centralIps)->toContain('198.51.100.11', '198.51.100.22', '198.51.100.33', '203.0.113.44');
});

it('prefixes a cell that starts like a formula with an apostrophe (CSV injection)', function (string $name) use ($record, $csv, $byMarker): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create(['name' => $name]);
    $record(ActivityEvent::UserUpdated, $organisation, 'formula-entry', $member, $member);
    asOrgAdmin($organisation);

    $row = $byMarker($csv($this->get(route('activity.export'))->assertOk()))['formula-entry'];

    expect($row[3])->toBe("'".$name)
        ->and($row[4])->toBe('User #'.$member->id.' · '.$name);
})->with([
    'equals' => ['=HYPERLINK("https://evil.example","Click")'],
    'plus' => ['+1+1'],
    'minus' => ['-2+3'],
    'at' => ['@SUM(A1:A2)'],
    'tab' => ["\t=1+1"],
    'carriage return' => ["\r=1+1"],
]);

it('records the export with the filters it applied, before the first byte', function () use ($record): void {
    $organisation = Organisation::factory()->create();
    $record(ActivityEvent::OrganisationUpdated, $organisation, 'some-entry');
    $admin = asOrgAdmin($organisation);

    // The body is never read: the entry exists as soon as the response does.
    $this->get(route('activity.export', ['event' => 'organisation.updated', 'from' => '2026-10-01', 'until' => '0000-01-01', 'organisation' => '99']))
        ->assertOk();

    $entry = recordedActivity(ActivityEvent::ActivityExported)->sole();

    expect($entry->log_name)->toBe('audit')
        ->and($entry->causer_id)->toBe($admin->id)
        ->and($entry->organisation_id)->toBe($organisation->id)
        ->and($entry->subject_type)->toBeNull()
        ->and($entry->getProperty('filters'))->toBe(['from' => '2026-10-01', 'event' => 'organisation.updated']);
});

it('records a central export without an organisation', function (): void {
    $superAdmin = asSuperAdmin();
    onAdminHost();

    $this->get(route('activity.export'))->assertOk();

    $entry = recordedActivity(ActivityEvent::ActivityExported)->sole();

    expect($entry->causer_id)->toBe($superAdmin->id)
        ->and($entry->organisation_id)->toBeNull()
        ->and($entry->getProperty('filters'))->toBe([]);
});

it('allows five exports a minute per user and refuses the sixth', function (): void {
    $organisation = Organisation::factory()->create();
    $admin = asOrgAdmin($organisation);

    foreach (range(1, 5) as $attempt) {
        $this->get(route('activity.export'))->assertOk();
    }

    $this->get(route('activity.export'))->assertTooManyRequests();

    expect(RateLimiter::attempts(ThrottleKey::of('activity-export', 'activity-export:'.$admin->id)))->toBe(5)
        ->and(recordedActivity(ActivityEvent::ActivityExported))->toHaveCount(5);

    // Another administrator of the same organisation has a bucket of their own.
    asOrgAdmin($organisation);

    $this->get(route('activity.export'))->assertOk();
});

it('refuses a user without the activity_log.view permission', function (): void {
    $organisation = Organisation::factory()->create();
    asMember($organisation);

    $this->get(route('activity.export'))->assertForbidden();

    expect(recordedActivity(ActivityEvent::ActivityExported))->toHaveCount(0);
});

it('links "Export CSV" to the export with the page\'s current filters', function () use ($record): void {
    $organisation = Organisation::factory()->create();
    $record(ActivityEvent::OrganisationUpdated, $organisation, 'some-entry');
    asOrgAdmin($organisation);

    $this->get(route('activity.index'))
        ->assertOk()
        ->assertSee('data-test="activity-export"', false)
        ->assertSee('href="'.e(route('activity.export', absolute: false)).'"', false);

    $this->get(route('activity.index', ['event' => 'organisation.updated', 'from' => '2026-10-01']))
        ->assertOk()
        ->assertSee('href="'.e(route('activity.export', ['from' => '2026-10-01', 'event' => 'organisation.updated'], absolute: false)).'"', false);
});
