<?php

declare(strict_types=1);

/** covers §4.3.1 */

use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('creates an organisation with a valid slug from the factory', function (): void {
    $organisation = Organisation::factory()->create();

    expect($organisation->slug)->toMatch('/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])$/')
        ->and($organisation->require_second_factor)->toBeFalse();
});

it('requires a unique name and a unique slug', function (): void {
    $organisation = Organisation::factory()->create();

    expect(fn () => DB::transaction(fn (): Organisation => Organisation::factory()->create(['name' => $organisation->name])))
        ->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => DB::transaction(fn (): Organisation => Organisation::factory()->create(['slug' => $organisation->slug])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('soft deletes organisations', function (): void {
    $organisation = Organisation::factory()->create();

    $organisation->delete();

    expect($organisation->trashed())->toBeTrue()
        ->and(Organisation::query()->find($organisation->id))->toBeNull()
        ->and(Organisation::withTrashed()->find($organisation->id))->not->toBeNull();
});

it('has users', function (): void {
    $organisation = Organisation::factory()->create();
    $user = User::factory()->for($organisation)->create();

    expect(Tenant::run($organisation, fn (): array => $organisation->users()->pluck('id')->all()))->toBe([$user->id]);
});
