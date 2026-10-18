<?php

declare(strict_types=1);

/** covers §4.1.4, §4.1.5, §6.2 */

use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\CentralContext;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use App\Tenancy\Facades\Tenant;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/** @return array{0: Organisation, 1: User, 2: User} */
function twoTenantsWithMembers(): array
{
    [$first, $second] = Organisation::factory()->count(2)->create()->all();

    return [$first, User::factory()->for($first)->create(), User::factory()->for($second)->create()];
}

it('scopes tenant models to the bound organisation', function (): void {
    [$first, $mine] = twoTenantsWithMembers();
    User::factory()->superAdmin()->create();

    expect(Tenant::run($first, fn (): array => User::query()->pluck('id')->all()))->toBe([$mine->id]);
});

it('shows every row inside a bypass', function (): void {
    [$first] = twoTenantsWithMembers();

    expect(Tenant::run($first, fn (): int => Tenant::bypass(fn (): int => User::query()->count())))->toBe(2);
});

it('does not scope without a tenant when strict mode is off', function (): void {
    config(['tenancy.strict' => false]);
    twoTenantsWithMembers();

    expect(User::query()->count())->toBe(2);
});

it('throws in strict mode when no tenant is bound', function (): void {
    config(['tenancy.strict' => true]);

    expect(fn () => User::query()->count())->toThrow(TenantNotResolvedException::class);
});

it('runs centrally in the console context even in strict mode', function (): void {
    config(['tenancy.strict' => true]);
    twoTenantsWithMembers();

    event(new CommandStarting('list', new ArrayInput([]), new NullOutput));

    expect(app(CentralContext::class)->active())->toBeTrue()
        ->and(User::query()->count())->toBe(2);
});

it('leaves the central context when the command finishes', function (): void {
    config(['tenancy.strict' => true]);

    event(new CommandStarting('list', new ArrayInput([]), new NullOutput));
    event(new CommandFinished('list', new ArrayInput([]), new NullOutput, 0));

    expect(fn () => User::query()->count())->toThrow(TenantNotResolvedException::class);
});

it('fills organisation_id from the bound tenant when creating', function (): void {
    $organisation = Organisation::factory()->create();

    $user = Tenant::run($organisation, fn (): User => User::factory()->create(['organisation_id' => null]));

    expect($user->organisation_id)->toBe($organisation->id);
});

it('refuses to create a tenant row without an organisation (W8)', function (): void {
    expect(fn () => User::factory()->create(['organisation_id' => null]))
        ->toThrow(TenantNotResolvedException::class);
});

it('lets super admins exist without an organisation', function (): void {
    expect(User::factory()->superAdmin()->create()->organisation_id)->toBeNull();
});

it('finds users of any organisation through the auth provider', function (): void {
    [$first, , $other] = twoTenantsWithMembers();

    $found = Tenant::run($first, fn (): mixed => Auth::createUserProvider('users')?->retrieveById($other->id));

    expect(config('auth.providers.users.driver'))->toBe('unscoped-eloquent')
        ->and($found)->toBeInstanceOf(User::class);
});

it('scopes a Tenant::run() nested inside a bypass', function (): void {
    [$first, $mine] = twoTenantsWithMembers();

    [$ids, $bypassedAfterRun] = Tenant::bypass(fn (): array => [
        Tenant::run($first, fn (): array => User::query()->pluck('id')->all()),
        Tenant::isBypassed(),
    ]);

    expect($ids)->toBe([$mine->id])
        ->and($bypassedAfterRun)->toBeTrue()
        ->and(Tenant::isBypassed())->toBeFalse();
});
