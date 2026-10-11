<?php

declare(strict_types=1);

/** covers §4.3, §4.8 */

use App\Actions\Organisations\UpdateOrganisation;
use App\Authorization\Permission;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as RoleModel;

it('updates name and description of the own organisation for its admin', function (): void {
    $organisation = Organisation::factory()->create(['name' => 'Acme', 'slug' => 'acme', 'description' => null]);
    $admin = asOrgAdmin($organisation);

    $updated = app(UpdateOrganisation::class)->handle($admin, $organisation, 'Acme Rockets', 'acme', 'Anvils too');

    expect($updated->only(['name', 'slug', 'description']))->toBe(['name' => 'Acme Rockets', 'slug' => 'acme', 'description' => 'Anvils too'])
        ->and($organisation->fresh()?->name)->toBe('Acme Rockets');
});

it('refuses another organisation', function (): void {
    $own = Organisation::factory()->create();
    $other = Organisation::factory()->create(['name' => 'Beta']);
    $admin = asOrgAdmin($own);

    expect(fn () => app(UpdateOrganisation::class)->handle($admin, $other, 'Taken over', $other->slug, null))
        ->toThrow(AuthorizationException::class);
    expect($other->fresh()?->name)->toBe('Beta');
});

it('refuses a user of the organisation without organisations.update', function (array $permissions): void {
    $organisation = Organisation::factory()->create(['name' => 'Acme']);
    $actor = asMember($organisation);

    if ($permissions !== []) {
        $role = RoleModel::query()->create(['name' => 'viewer', 'guard_name' => 'web', 'team_id' => $organisation->id]);
        $role->givePermissionTo(array_map(fn (Permission $permission): string => $permission->value, $permissions));
        withPermissionsTeam($organisation->id, fn (): User => $actor->assignRole($role));
        $actor->unsetRelation('roles');
    }

    expect(fn () => app(UpdateOrganisation::class)->handle($actor, $organisation, 'Renamed', $organisation->slug, null))
        ->toThrow(AuthorizationException::class);
    expect($organisation->fresh()?->name)->toBe('Acme');
})->with([
    'a member without a role' => [[]],
    'a custom role that can view users and the organisation' => [[Permission::UsersView, Permission::OrganisationsView]],
]);

it('refuses Markdown link syntax and control characters in the name', function (string $name): void {
    $organisation = Organisation::factory()->create(['name' => 'Acme']);
    $admin = asOrgAdmin($organisation);

    try {
        app(UpdateOrganisation::class)->handle($admin, $organisation, $name, $organisation->slug, null);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([
            'name' => [__('The name may not contain square brackets, angle brackets or control characters.')],
        ]);
    }
    expect($organisation->fresh()?->name)->toBe('Acme');
})->with([
    'an inline link' => ['Acme [Reset your password](https://evil.example/x)'],
    'an image' => ['Acme ![logo](https://evil.example/x.png)'],
    'an autolink' => ['Acme <https://evil.example/x>'],
    'an inner line break' => ["Acme\n\n[1]: https://evil.example/x"],
    'a tab' => ["Acme\tRockets"],
]);

it('accepts ordinary punctuation in the name', function (string $name): void {
    $organisation = Organisation::factory()->create(['name' => 'Acme']);
    $admin = asOrgAdmin($organisation);

    app(UpdateOrganisation::class)->handle($admin, $organisation, $name, $organisation->slug, null);

    expect($organisation->fresh()?->name)->toBe($name);
})->with([
    ['Smith & Sons (NL)'],
    ['Café #1 — O\'Brien, B.V.'],
]);

it('refuses an archived organisation (RK-I11)', function (): void {
    $archived = Organisation::factory()->create(['name' => 'Gone']);
    $archived->delete();
    $superAdmin = asSuperAdmin();
    onAdminHost();

    // A try/catch instead of toThrow(Closure): Pest reads the closure's parameter type as the exception
    // class, and an interface such as Throwable is taken for an expected message instead.
    try {
        app(UpdateOrganisation::class)->handle($superAdmin, $archived, 'Back again', $archived->slug, null);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([
            'organisation' => [__('This organisation is archived.')],
        ]);
    }
    expect(Organisation::withTrashed()->find($archived->id)?->name)->toBe('Gone');
});

it('gives an organisation admin the generic message for an archived organisation\'s name (RK-M2)', function (): void {
    Organisation::factory()->create(['name' => 'Old Name'])->delete();
    $organisation = Organisation::factory()->create();
    $admin = asOrgAdmin($organisation);

    try {
        app(UpdateOrganisation::class)->handle($admin, $organisation, 'Old Name', $organisation->slug, null);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([
            'name' => [__('This name can\'t be used.')],
        ]);
    }
});

it('lets only super admins change the slug', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = asOrgAdmin($organisation);

    try {
        app(UpdateOrganisation::class)->handle($admin, $organisation, $organisation->name, 'acme-new', $organisation->description);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([
            'slug' => [__('Only a platform administrator can change the address.')],
        ]);
    }
    expect($organisation->fresh()?->slug)->toBe('acme');

    $superAdmin = asSuperAdmin();
    onAdminHost();

    app(UpdateOrganisation::class)->handle($superAdmin, $organisation, $organisation->name, 'acme-new', $organisation->description);

    expect($organisation->fresh()?->slug)->toBe('acme-new');
});

it('records organisation.updated with the changes', function (): void {
    $organisation = Organisation::factory()->create(['name' => 'Acme', 'slug' => 'acme', 'description' => null]);
    $admin = asOrgAdmin($organisation);

    app(UpdateOrganisation::class)->handle($admin, $organisation, 'Acme Rockets', 'acme', null);
    $entry = recordedActivity(ActivityEvent::OrganisationUpdated)->sole();

    expect($entry->organisation_id)->toBe($organisation->id)
        ->and((int) $entry->causer_id)->toBe($admin->id)
        ->and($entry->attribute_changes?->get('old'))->toBe(['name' => 'Acme'])
        ->and($entry->attribute_changes?->get('attributes'))->toBe(['name' => 'Acme Rockets']);

    // A save without changes records nothing.
    app(UpdateOrganisation::class)->handle($admin, $organisation, 'Acme Rockets', 'acme', null);

    expect(recordedActivity(ActivityEvent::OrganisationUpdated))->toHaveCount(1);
});
