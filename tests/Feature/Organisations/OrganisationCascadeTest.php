<?php

declare(strict_types=1);

/** covers §4.1.9 */

use App\Authorization\SetSuperAdmin;
use App\Models\Activity;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function deletedAtOf(User $user): ?string
{
    $value = DB::table('users')->where('id', $user->id)->value('deleted_at');

    return $value === null ? null : (string) $value;
}

it('soft deletes the organisation\'s tenant rows with the same timestamp', function (): void {
    $organisation = Organisation::factory()->create();
    [$first, $second] = User::factory()->for($organisation)->count(2)->create()->all();
    $outsider = User::factory()->create();

    $organisation->delete();

    $organisationDeletedAt = (string) DB::table('organisations')->where('id', $organisation->id)->value('deleted_at');
    expect(deletedAtOf($first))->toBe($organisationDeletedAt)
        ->and(deletedAtOf($second))->toBe($organisationDeletedAt)
        ->and(deletedAtOf($outsider))->toBeNull();
});

it('restores only the rows the cascade deleted', function (): void {
    $organisation = Organisation::factory()->create();
    $deletedEarlier = User::factory()->for($organisation)->create();
    $deletedEarlier->delete();
    $this->travel(1)->minutes();
    $cascaded = User::factory()->for($organisation)->create();

    $organisation->delete();
    $organisation->restore();

    expect(deletedAtOf($cascaded))->toBeNull()
        ->and(deletedAtOf($deletedEarlier))->not->toBeNull();
});

it('does not cascade a force delete', function (): void {
    $empty = Organisation::factory()->create();
    $empty->forceDelete();
    expect(Organisation::withTrashed()->find($empty->id))->toBeNull();

    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();

    expect(fn () => DB::transaction(fn (): ?bool => $organisation->forceDelete()))->toThrow(QueryException::class);
    expect(deletedAtOf($member))->toBeNull();
});

it('never archives a super admin with the organisation', function (): void {
    $organisation = Organisation::factory()->create();
    $promoted = User::factory()->for($organisation)->create();
    app(SetSuperAdmin::class)->handle($promoted, true);

    $organisation->delete();

    expect(deletedAtOf($promoted))->toBeNull();
});

it('archives and restores an organisation\'s activity rows with it (AUD-12)', function (): void {
    $organisation = Organisation::factory()->create();
    $entry = Activity::factory()->for($organisation)->create();
    $central = Activity::factory()->create();

    $organisation->delete();

    $organisationDeletedAt = (string) DB::table('organisations')->where('id', $organisation->id)->value('deleted_at');
    expect((string) DB::table('activity_log')->where('id', $entry->id)->value('deleted_at'))->toBe($organisationDeletedAt)
        ->and(DB::table('activity_log')->where('id', $central->id)->value('deleted_at'))->toBeNull();

    $organisation->restore();

    expect(DB::table('activity_log')->where('id', $entry->id)->value('deleted_at'))->toBeNull();
});
