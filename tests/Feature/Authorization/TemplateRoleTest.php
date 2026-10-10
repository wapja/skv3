<?php

declare(strict_types=1);

/** covers §4.6, §6.3 */

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

it('assigns one global template role per team', function (): void {
    [$first, $second] = Organisation::factory()->count(2)->create()->all();
    $template = withPermissionsTeam(null, function (): RoleModel {
        PermissionModel::query()->firstOrCreate(['name' => 'spike.view', 'guard_name' => 'web']);
        $role = RoleModel::query()->create(['name' => 'spike_template', 'guard_name' => 'web', 'team_id' => null]);
        $role->givePermissionTo('spike.view');

        return $role;
    });

    $alice = User::factory()->for($first)->create();
    $bob = User::factory()->for($second)->create();
    withPermissionsTeam($first->id, fn () => $alice->assignRole($template));
    withPermissionsTeam($second->id, fn () => $bob->assignRole($template));

    withPermissionsTeam($first->id, function () use ($alice, $bob): void {
        expect($alice->fresh()?->hasRole('spike_template'))->toBeTrue()
            ->and($alice->fresh()?->can('spike.view'))->toBeTrue()
            ->and($bob->fresh()?->hasRole('spike_template'))->toBeFalse();
    });

    expect(RoleModel::query()->where('name', 'spike_template')->count())->toBe(1)
        ->and(DB::table('model_has_roles')->where('role_id', $template->id)->pluck('team_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all())
        ->toBe([$first->id, $second->id]);
});

it('attaches a global role with a null team', function (): void {
    $user = User::factory()->superAdmin()->create();

    withPermissionsTeam(null, function () use ($user): void {
        $role = RoleModel::query()->create(['name' => 'spike_badge', 'guard_name' => 'web', 'team_id' => null]);
        $user->assignRole($role);
    });

    expect(DB::table('model_has_roles')->where('model_id', $user->id)->whereNull('team_id')->count())->toBe(1);
});

it('propagates template permission changes to every team', function (): void {
    $organisation = Organisation::factory()->create();
    $user = User::factory()->for($organisation)->create();
    $template = withPermissionsTeam(null, function (): RoleModel {
        PermissionModel::query()->firstOrCreate(['name' => 'spike.manage', 'guard_name' => 'web']);

        return RoleModel::query()->create(['name' => 'spike_template', 'guard_name' => 'web', 'team_id' => null]);
    });
    withPermissionsTeam($organisation->id, fn () => $user->assignRole($template));

    $template->givePermissionTo('spike.manage');

    expect(withPermissionsTeam($organisation->id, fn (): bool => (bool) $user->fresh()?->can('spike.manage')))->toBeTrue();
});
