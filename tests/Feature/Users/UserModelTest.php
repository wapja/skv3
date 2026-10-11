<?php

declare(strict_types=1);

/** covers §4.4, §6.3 */

use App\Enums\UserStatus;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('belongs to an organisation and is active by default in the factory', function (): void {
    $user = User::factory()->create();

    expect($user->organisation_id)->not->toBeNull()
        ->and($user->organisation?->id)->toBe($user->organisation_id)
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->isActive())->toBeTrue()
        ->and($user->isSuperAdmin())->toBeFalse();
});

it('has pending, disabled and super admin factory states', function (): void {
    expect(User::factory()->pending()->create()->status)->toBe(UserStatus::PendingActivation)
        ->and(User::factory()->disabled()->create()->isActive())->toBeFalse();

    $superAdmin = User::factory()->superAdmin()->create();

    expect($superAdmin->isSuperAdmin())->toBeTrue()
        ->and($superAdmin->organisation_id)->toBeNull();
});

it('never infers super admin from a missing organisation (W8)', function (): void {
    $user = User::factory()->make(['organisation_id' => null]);

    expect($user->isSuperAdmin())->toBeFalse();
});

it('defaults the status column to pending activation', function (): void {
    $id = DB::table('users')->insertGetId([
        'name' => 'Raw',
        'email' => 'raw@example.com',
        'password' => 'x',
        'organisation_id' => Organisation::factory()->create()->id,
    ]);

    expect(DB::table('users')->where('id', $id)->value('status'))->toBe('pending_activation')
        ->and(DB::table('users')->where('id', $id)->value('is_super_admin'))->toBeFalsy();
});

it('keeps emails unique among non-deleted users only', function (): void {
    $user = User::factory()->create(['email' => 'same@example.com']);

    expect(fn () => DB::transaction(fn (): User => User::factory()->create(['email' => 'same@example.com'])))
        ->toThrow(UniqueConstraintViolationException::class);

    $user->delete();

    expect(User::factory()->create(['email' => 'same@example.com'])->email)->toBe('same@example.com');
});

it('soft deletes users', function (): void {
    $user = User::factory()->create();

    $user->delete();

    expect($user->trashed())->toBeTrue();
});

it('labels every status', function (UserStatus $status): void {
    expect($status->label())->not->toBe('');
})->with(UserStatus::cases());

it('gives every user status a badge colour', function (UserStatus $status, string $colour): void {
    expect($status->color())->toBe($colour);
})->with([
    'pending activation' => [UserStatus::PendingActivation, 'amber'],
    'active' => [UserStatus::Active, 'lime'],
    'disabled' => [UserStatus::Disabled, 'zinc'],
]);
