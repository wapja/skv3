<?php

declare(strict_types=1);

/** covers §6.2 */

use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\HostScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

it('reads only the tenant\'s rows on a tenant host', function (): void {
    $own = Organisation::factory()->create();
    $colleague = User::factory()->for($own)->create();
    $stranger = User::factory()->for(Organisation::factory())->create();
    $admin = asOrgAdmin($own);

    $ids = HostScope::read(fn (): array => User::query()->pluck('id')->all());

    expect($ids)->toContain($colleague->id, $admin->id)
        ->not->toContain($stranger->id);
});

it('reads every organisation for a super admin on a central host', function (string $host): void {
    $first = User::factory()->create();
    $second = User::factory()->create();
    asSuperAdmin();
    $host === 'apex' ? onApex() : onAdminHost();

    $ids = HostScope::read(fn (): array => User::query()->pluck('id')->all());

    expect($ids)->toContain($first->id, $second->id)
        ->and(Tenant::isBypassed())->toBeFalse();
})->with(['apex', 'admin']);

it('refuses a cross-organisation read for anyone else', function (string $actor): void {
    $organisation = Organisation::factory()->create();

    match ($actor) {
        'member' => asMember($organisation),
        'organisation admin' => asOrgAdmin($organisation),
        default => null,
    };

    onAdminHost();

    expect(fn (): int => HostScope::read(fn (): int => User::query()->count()))
        ->toThrow(AuthorizationException::class);
})->with(['guest', 'member', 'organisation admin']);

it('gives the tenant id on a tenant host and null to a super admin centrally (RK-I4)', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    asOrgAdmin($organisation);

    expect(HostScope::organisationId())->toBe($organisation->id);

    asSuperAdmin();
    onTenant($organisation);
    expect(HostScope::organisationId())->toBe($organisation->id);

    onAdminHost();
    expect(HostScope::organisationId())->toBeNull();

    actingAs($member);
    expect(fn (): ?int => HostScope::organisationId())->toThrow(AuthorizationException::class);
});

it('locks an organisation for writing and refuses an archived one or another tenant\'s (RK-I11)', function (): void {
    $organisation = Organisation::factory()->create(['name' => 'Acme']);
    $other = Organisation::factory()->create();
    $archived = Organisation::factory()->create();
    $archived->delete();
    asOrgAdmin($organisation);
    DB::table('organisations')->where('id', $organisation->id)->update(['name' => 'Acme renamed']);
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $locked = DB::transaction(fn (): Organisation => HostScope::lockForWrite($organisation));

    expect($locked->name)->toBe('Acme renamed');

    if (DB::getDriverName() === 'pgsql') {
        expect(collect($statements)->contains(fn (string $sql): bool => str_contains($sql, 'for update')))->toBeTrue();
    }

    expect(fn (): Organisation => DB::transaction(fn (): Organisation => HostScope::lockForWrite($other)))
        ->toThrow(AuthorizationException::class)
        ->and(fn (): Organisation => DB::transaction(fn (): Organisation => HostScope::lockForWrite($archived)))
        ->toThrow(AuthorizationException::class);

    asSuperAdmin();
    onAdminHost();

    expect(DB::transaction(fn (): Organisation => HostScope::lockForWrite($other))->id)->toBe($other->id)
        ->and(fn (): Organisation => DB::transaction(fn (): Organisation => HostScope::lockForWrite($archived)))
        ->toThrow(ValidationException::class, __('This organisation is archived.'));
});

it('locks the organisation of a user write without refusing an archived one, but refuses another tenant\'s (CD-5, RK-I11)', function (): void {
    $organisation = Organisation::factory()->create();
    $archived = Organisation::factory()->create();
    $archived->delete();

    // A central host: the user policies place the target, and the action decides on the archived state itself.
    onAdminHost();

    expect(DB::transaction(fn (): ?Organisation => HostScope::lockOrganisation($organisation->id))?->id)->toBe($organisation->id)
        ->and(DB::transaction(fn (): ?Organisation => HostScope::lockOrganisation($archived->id))?->trashed())->toBeTrue()
        ->and(DB::transaction(fn (): ?Organisation => HostScope::lockOrganisation(null)))->toBeNull();

    // Its own tenant host locks it; another tenant's host refuses it, as lockForWrite() does (defence in depth).
    onTenant($organisation);
    expect(DB::transaction(fn (): ?Organisation => HostScope::lockOrganisation($organisation->id))?->id)->toBe($organisation->id)
        ->and(DB::transaction(fn (): ?Organisation => HostScope::lockOrganisation(null)))->toBeNull();

    onTenant(Organisation::factory()->create());
    expect(fn (): ?Organisation => DB::transaction(fn (): ?Organisation => HostScope::lockOrganisation($organisation->id)))
        ->toThrow(AuthorizationException::class);
});
