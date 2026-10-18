<?php

declare(strict_types=1);

/** covers §4.4.1, §4.6, §4.8.1 */

use App\Actions\Users\UpdateUserRoles;
use App\Authorization\SetSuperAdmin;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as RoleModel;

it('records a name or email change with old and new values and never the password (USR-4)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create(['name' => 'Old Name', 'email' => 'old@example.com']);
    onTenant($organisation);

    $member->forceFill(['name' => 'New Name', 'email' => 'new@example.com', 'password' => 'a-new-password'])->save();

    $entry = recordedActivity(ActivityEvent::UserUpdated)->sole();
    expect([$entry->subject_id, $entry->organisation_id])->toBe([$member->id, $organisation->id])
        ->and($entry->attribute_changes?->toArray())->toBe([
            'old' => ['name' => 'Old Name', 'email' => 'old@example.com'],
            'attributes' => ['name' => 'New Name', 'email' => 'new@example.com'],
        ])
        ->and((string) DB::table('activity_log')->where('id', $entry->id)->value('attribute_changes'))->not->toContain('password');
});

it('records nothing when only the password, remember token or two-factor columns change', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    $member->forceFill([
        'password' => 'another-password',
        'remember_token' => Str::random(60),
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt('[]'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    expect(recordedActivity(ActivityEvent::UserUpdated))->toBeEmpty();
});

it('records nothing for a quiet save', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);

    $member->forceFill(['name' => 'Quietly Renamed'])->saveQuietly();

    expect(recordedActivity(ActivityEvent::UserUpdated))->toBeEmpty();
});

it('records a role change with the old and new role names (AUD-9)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $editor = RoleModel::query()->create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $admin = asOrgAdmin($organisation);

    app(UpdateUserRoles::class)->handle($admin, $member, [(int) $editor->id]);
    app(UpdateUserRoles::class)->handle($admin, $member, []);

    [$granted, $removed] = recordedActivity(ActivityEvent::UserRolesChanged)->all();
    expect([$granted->subject_id, $granted->causer_id, $granted->organisation_id])->toBe([$member->id, $admin->id, $organisation->id])
        ->and($granted->attribute_changes?->toArray())->toBe(['old' => ['roles' => []], 'attributes' => ['roles' => ['editor']]])
        ->and($removed->attribute_changes?->toArray())->toBe(['old' => ['roles' => ['editor']], 'attributes' => ['roles' => []]]);
});

it('records no role change when the roles stay the same', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $editor = RoleModel::query()->create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $organisation->id]);
    $admin = asOrgAdmin($organisation);

    app(UpdateUserRoles::class)->handle($admin, $member, [(int) $editor->id]);
    app(UpdateUserRoles::class)->handle($admin, $member, [(int) $editor->id]);

    expect(recordedActivity(ActivityEvent::UserRolesChanged))->toHaveCount(1);
});

it('records granting and revoking super admin, also from the console (AUD-9)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    $keeper = asSuperAdmin();

    app(SetSuperAdmin::class)->handle($member, true);

    // As in the console or a seeder: nobody is signed in.
    Auth::guard('web')->forgetUser();
    Artisan::registerCommand(new ClosureCommand('audit:revoke-super-admin', function () use ($member): void {
        app(SetSuperAdmin::class)->handle($member, false);
    }));
    expect(Artisan::call('audit:revoke-super-admin'))->toBe(0);

    $granted = recordedActivity(ActivityEvent::SuperAdminGranted)->sole();
    $revoked = recordedActivity(ActivityEvent::SuperAdminRevoked)->sole();
    expect([$granted->subject_id, $granted->causer_id, $granted->organisation_id])->toBe([$member->id, $keeper->id, $organisation->id])
        ->and([$revoked->subject_id, $revoked->causer_id, $revoked->organisation_id])->toBe([$member->id, null, $organisation->id]);
});
