<?php

declare(strict_types=1);

/** covers §4.8, §6.2 */

use App\Actions\Audit\RecordActivity;
use App\Authorization\SetSuperAdmin;
use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use App\Tenancy\Hosts;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role as RoleModel;

use function Pest\Laravel\actingAs;

it('records an event with its key, group and description', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    $activity = app(RecordActivity::class)->handle(ActivityEvent::UserUpdated, $member, properties: ['reason' => 'Typo in the name']);

    expect($activity->event)->toBe('user.updated')
        ->and($activity->description)->toBe('user.updated')
        ->and($activity->log_name)->toBe('users')
        ->and($activity->subject_type)->toBe($member->getMorphClass())
        ->and($activity->subject_id)->toBe($member->id)
        ->and($activity->getProperty('reason'))->toBe('Typo in the name')
        ->and($activity->attribute_changes)->toBeNull()
        ->and(recordedActivity(ActivityEvent::UserUpdated)->sole()->id)->toBe($activity->id);
});

it('stores the event as a plain string and reads it back as an ActivityEvent (RK-B1)', function (): void {
    $organisation = Organisation::factory()->create();
    onAdminHost();

    app(RecordActivity::class)->handle(ActivityEvent::OrganisationCreated, $organisation);
    // A vendor event name must be storable too: an enum cast on `event` would throw here.
    $vendorEvent = Activity::factory()->create(['event' => 'updated', 'organisation_id' => $organisation->id]);

    expect(DB::table('activity_log')->where('event', 'organisation.created')->count())->toBe(1)
        ->and(recordedActivity(ActivityEvent::OrganisationCreated)->sole()->kitEvent())->toBe(ActivityEvent::OrganisationCreated)
        ->and($vendorEvent->fresh()?->kitEvent())->toBeNull();
});

it('tags an entry with an explicit organisation first', function (): void {
    $home = Organisation::factory()->create();
    $explicit = Organisation::factory()->create();
    $member = User::factory()->for($home)->create();
    $role = RoleModel::query()->create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $explicit->id]);
    onAdminHost();

    $forRole = app(RecordActivity::class)->handle(ActivityEvent::RoleCreated, $role, organisation: $explicit);
    // A Spatie role carries team_id, not organisation_id: without the explicit tag it is central.
    $roleWithoutTag = app(RecordActivity::class)->handle(ActivityEvent::RoleCreated, $role);
    $overSubject = app(RecordActivity::class)->handle(ActivityEvent::UserUpdated, $member, organisation: $explicit);

    expect($forRole->organisation_id)->toBe($explicit->id)
        ->and($roleWithoutTag->organisation_id)->toBeNull()
        ->and($overSubject->organisation_id)->toBe($explicit->id);
});

it('tags an administrative entry with the subject\'s organisation before the bound tenant (X7)', function (): void {
    $home = Organisation::factory()->create();
    $bound = Organisation::factory()->create();
    $member = User::factory()->for($home)->create();
    onAdminHost();

    $central = app(RecordActivity::class)->handle(ActivityEvent::UserUpdated, $member);
    $organisationSubject = app(RecordActivity::class)->handle(ActivityEvent::OrganisationArchived, $home);
    onTenant($bound);
    $onOtherHost = app(RecordActivity::class)->handle(ActivityEvent::UserUpdated, $member);

    expect([$central->organisation_id, $organisationSubject->organisation_id, $onOtherHost->organisation_id])
        ->toBe([$home->id, $home->id, $home->id]);
});

it('tags an entry with the bound tenant when the subject has none', function (): void {
    $organisation = Organisation::factory()->create();
    $superAdmin = User::factory()->superAdmin()->create();
    onTenant($organisation);

    $centralSubject = app(RecordActivity::class)->handle(ActivityEvent::UserUpdated, $superAdmin);
    $noSubject = app(RecordActivity::class)->handle(ActivityEvent::OrganisationUpdated);

    expect($centralSubject->organisation_id)->toBe($organisation->id)
        ->and($noSubject->organisation_id)->toBe($organisation->id);
});

it('tags authentication, security and impersonation entries by host, ignoring the subject\'s organisation (RL-5, RK-I5)', function (ActivityEvent $event): void {
    $home = Organisation::factory()->create();
    $other = Organisation::factory()->create();
    $promoted = User::factory()->for($home)->create();
    app(SetSuperAdmin::class)->handle($promoted, true);

    onAdminHost();
    $central = app(RecordActivity::class)->handle($event, $promoted, organisation: $home);
    onTenant($other);
    $onOtherHost = app(RecordActivity::class)->handle($event, $promoted, organisation: $home);

    expect($central->organisation_id)->toBeNull()
        ->and($onOtherHost->organisation_id)->toBe($other->id);
})->with([ActivityEvent::Login, ActivityEvent::PasswordChanged, ActivityEvent::ImpersonationStarted]);

it('records central entries without an organisation and keeps them out of tenant reads (AUD-11)', function (): void {
    $organisation = Organisation::factory()->create();
    $superAdmin = User::factory()->superAdmin()->create();
    onAdminHost();
    $central = app(RecordActivity::class)->handle(ActivityEvent::Login, $superAdmin);
    onTenant($organisation);
    $tenant = app(RecordActivity::class)->handle(ActivityEvent::Login, $superAdmin);

    expect($central->organisation_id)->toBeNull()
        ->and(Activity::query()->pluck('id')->all())->toBe([$tenant->id]);
});

it('records an entry without a causer when asked to', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $other = User::factory()->for($organisation)->create();
    actingAs($member);
    onTenant($organisation);

    $byDefault = app(RecordActivity::class)->handle(ActivityEvent::UserUpdated, $other);
    $explicit = app(RecordActivity::class)->handle(ActivityEvent::UserUpdated, $other, causer: $other);
    $withoutCauser = app(RecordActivity::class)->handle(ActivityEvent::UserUpdated, $other, defaultCauser: false);

    expect([$byDefault->causer_type, $byDefault->causer_id])->toBe([$member->getMorphClass(), $member->id])
        ->and($explicit->causer_id)->toBe($other->id)
        ->and([$withoutCauser->causer_type, $withoutCauser->causer_id])->toBe([null, null]);
});

it('captures the IP address and user agent of a web request (AUD-5)', function (): void {
    $organisation = Organisation::factory()->create();
    Route::middleware('web')->get('/_audit-probe', function () use ($organisation): string {
        app(RecordActivity::class)->handle(ActivityEvent::OrganisationUpdated, $organisation);

        return 'ok';
    });

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withHeader('User-Agent', 'AuditProbe/1.0')
        ->get(Hosts::url(Hosts::apex(), '/_audit-probe'))
        ->assertOk();

    $entry = recordedActivity(ActivityEvent::OrganisationUpdated)->sole();
    expect([$entry->ip_address, $entry->user_agent])->toBe(['203.0.113.7', 'AuditProbe/1.0']);
});

it('captures no IP address in a queued job or a command', function (string $context): void {
    $organisation = Organisation::factory()->create();
    $organisationId = $organisation->id;

    if ($context === 'queued job') {
        // Runs through the sync queue (JobProcessing, central context); static: a queued closure is serialised.
        dispatch(static function () use ($organisationId): void {
            app(RecordActivity::class)->handle(ActivityEvent::OrganisationUpdated, Organisation::query()->findOrFail($organisationId));
        });
    } else {
        Artisan::registerCommand(new ClosureCommand('audit:probe', function () use ($organisationId): void {
            app(RecordActivity::class)->handle(ActivityEvent::OrganisationUpdated, Organisation::query()->findOrFail($organisationId));
        }));
        expect(Artisan::call('audit:probe'))->toBe(0);
    }

    $entry = recordedActivity(ActivityEvent::OrganisationUpdated)->sole();
    expect([$entry->ip_address, $entry->user_agent, $entry->organisation_id])->toBe([null, null, $organisationId]);
})->with(['queued job', 'command']);

it('throws when activity is read on a central host without a bypass (TEN-5)', function (): void {
    onApex();

    expect(fn () => Activity::query()->count())->toThrow(TenantNotResolvedException::class);
});

it('refuses to mass-assign organisation_id (C-13)', function (): void {
    $organisation = Organisation::factory()->create();

    expect(new Activity()->isFillable('organisation_id'))->toBeFalse()
        ->and(new Activity()->isFillable('description'))->toBeTrue()
        ->and(fn (): Activity => new Activity(['organisation_id' => $organisation->id]))->toThrow(MassAssignmentException::class);
});

it('gives every activity event a Dutch label and a group', function (ActivityEvent $event): void {
    // In English the strings are the translation keys themselves.
    app()->setLocale('en');

    expect(dutchTranslationKeys())->toContain($event->label())
        ->and(__($event->label(), [], 'nl'))->not->toBe($event->label())
        ->and($event->group())->toBeIn(['authentication', 'security', 'users', 'invitations', 'organisations', 'roles', 'impersonation', 'audit'])
        ->and($event->tagsByHost())->toBe(in_array($event->group(), ['authentication', 'security', 'impersonation', 'audit'], true));
})->with(ActivityEvent::cases());
