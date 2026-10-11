<?php

declare(strict_types=1);

/** covers §4.3, §4.5, §6.2 */

use App\Actions\Organisations\CreateOrganisation;
use App\Authorization\Role;
use App\Enums\ActivityEvent;
use App\Enums\Locale;
use App\Enums\UserStatus;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as RoleModel;

$createAcme = fn (User $actor, string $adminEmail = 'first-admin@example.com'): Organisation => app(CreateOrganisation::class)
    ->handle($actor, 'Acme', 'acme', 'Rockets and anvils', $adminEmail, Locale::Dutch);

it('creates the organisation and invites its first admin in one transaction (ORG-14)', function () use ($createAcme): void {
    $superAdmin = asSuperAdmin();
    onAdminHost();

    $organisation = $createAcme($superAdmin, 'First-Admin@Example.com');

    $admin = DB::table('users')->where('email', 'first-admin@example.com')->first();
    expect($organisation->only(['name', 'slug', 'description']))->toBe(['name' => 'Acme', 'slug' => 'acme', 'description' => 'Rockets and anvils'])
        ->and($admin)->not->toBeNull()
        ->and((int) $admin?->organisation_id)->toBe($organisation->id)
        ->and($admin?->status)->toBe(UserStatus::PendingActivation->value)
        ->and($admin?->locale)->toBe(Locale::Dutch->value)
        ->and(Tenant::run($organisation, fn (): int => Invitation::query()->where('user_id', (int) $admin?->id)->count()))->toBe(1);
});

it('creates nothing when the invitation fails', function () use ($createAcme): void {
    User::factory()->create(['email' => 'taken@example.com']);
    $superAdmin = asSuperAdmin();
    onAdminHost();
    $users = DB::table('users')->count();

    // A try/catch instead of toThrow(Closure): Pest reads the closure's parameter type as the exception
    // class, and an interface such as Throwable is taken for an expected message instead.
    try {
        $createAcme($superAdmin, 'taken@example.com');
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['email']);
    }

    expect(Organisation::withTrashed()->where('slug', 'acme')->exists())->toBeFalse()
        ->and(DB::table('users')->count())->toBe($users)
        ->and(DB::table('invitations')->count())->toBe(0)
        ->and(DB::table('activity_log')->where('event', ActivityEvent::OrganisationCreated->value)->exists())->toBeFalse();
});

it('queues the first admin\'s mail so that it works from the worker (OR-I2)', function () use ($createAcme): void {
    config(['queue.default' => 'database']);
    $superAdmin = asSuperAdmin();
    onAdminHost();
    $bodies = [];
    Event::listen(MessageSent::class, function (MessageSent $event) use (&$bodies): void {
        $body = $event->message->getHtmlBody();
        $bodies[] = is_string($body) ? $body : '';
    });

    $organisation = $createAcme($superAdmin);

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) DB::table('jobs')->latest('id')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
    // Pushed only after the outermost commit, so the worker finds the new organisation.
    expect(DB::table('jobs')->count())->toBe(1)
        ->and($payload['tenant_id'])->toBe($organisation->id)
        ->and($bodies)->toBe([]);

    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and($bodies)->toHaveCount(1)
        ->and($bodies[0])->toContain('http://acme.localhost/invitations/accept/')
        ->and($bodies[0])->toContain('signature=');
});

it('records exactly organisation.created, user.roles_changed and invitation.sent (OR-M10)', function () use ($createAcme): void {
    $superAdmin = asSuperAdmin();
    onAdminHost();
    $before = (int) DB::table('activity_log')->max('id');

    $organisation = $createAcme($superAdmin);

    $entries = DB::table('activity_log')->where('id', '>', $before)->orderBy('id')->get(['event', 'organisation_id']);
    expect($entries->pluck('event')->all())->toBe([
        ActivityEvent::OrganisationCreated->value,
        ActivityEvent::UserRolesChanged->value,
        ActivityEvent::InvitationSent->value,
    ])->and($entries->pluck('organisation_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all())->toBe([$organisation->id]);
});

it('never creates a per-organisation admin role and gives the first admin the template (W7)', function () use ($createAcme): void {
    $superAdmin = asSuperAdmin();
    onAdminHost();

    $organisation = $createAcme($superAdmin);

    $adminId = (int) DB::table('users')->where('email', 'first-admin@example.com')->value('id');
    $templateId = (int) RoleModel::query()->whereNull('team_id')->where('name', Role::OrganisationAdmin->value)->value('id');
    $assignments = DB::table('model_has_roles')
        ->where('model_type', (new User)->getMorphClass())
        ->where('model_id', $adminId)
        ->get(['role_id', 'team_id'])
        ->map(fn (object $row): array => [(int) $row->role_id, (int) $row->team_id])
        ->all();

    expect(RoleModel::query()->where('team_id', $organisation->id)->exists())->toBeFalse()
        ->and($assignments)->toBe([[$templateId, $organisation->id]]);
});

it('gives the first admin an explicit organisation and no super admin flag (W8)', function () use ($createAcme): void {
    $superAdmin = asSuperAdmin();
    onAdminHost();

    $organisation = $createAcme($superAdmin);

    $admin = DB::table('users')->where('email', 'first-admin@example.com')->first(['organisation_id', 'is_super_admin']);
    expect((int) $admin?->organisation_id)->toBe($organisation->id)
        ->and((bool) $admin?->is_super_admin)->toBeFalse();
});

it('refuses everyone but super admins', function (string $actor) use ($createAcme): void {
    $own = Organisation::factory()->create();
    $user = $actor === 'organisation admin' ? asOrgAdmin($own) : asMember($own);

    expect(fn (): Organisation => $createAcme($user))->toThrow(AuthorizationException::class);
    expect(Organisation::withTrashed()->where('slug', 'acme')->exists())->toBeFalse();
})->with(['organisation admin', 'member']);

it('says to super admins when a name or slug is held by an archived organisation (C-14)', function () use ($createAcme): void {
    Organisation::factory()->create(['name' => 'Acme', 'slug' => 'acme'])->delete();
    $superAdmin = asSuperAdmin();

    try {
        $createAcme($superAdmin);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([
            'name' => [__('This name is used by an archived organisation.')],
            'slug' => [__('This address is used by an archived organisation.')],
        ]);
    }
});
