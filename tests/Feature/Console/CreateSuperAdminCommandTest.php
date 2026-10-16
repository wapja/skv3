<?php

declare(strict_types=1);

/** covers §4.12 */

use App\Authorization\Role;
use App\Enums\ActivityEvent;
use App\Enums\UserStatus;
use App\Models\Organisation;
use App\Models\User;
use App\Setup\Support\Toolchain;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Facades\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

/**
 * Runs a command with $stdin on its input stream, as `printf … | php artisan …` would.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
$withStdin = function (string $command, array $parameters, string $stdin): array {
    $stream = fopen('php://memory', 'r+') ?: throw new LogicException('Could not open a memory stream.');
    fwrite($stream, $stdin);
    rewind($stream);
    $input = new ArrayInput(['command' => $command, ...$parameters]);
    $input->setStream($stream);
    $output = new BufferedOutput;

    return [app(Kernel::class)->handle($input, $output), $output->fetch()];
};

it('creates an active super admin without an organisation (§4.12, W8)', function () use ($withStdin): void {
    [$exit, $output] = $withStdin('skv:create-super-admin', ['email' => 'owner@example.com', '--name' => 'Owner', '--password-stdin' => true, '--no-interaction' => true], "correct-horse-battery\n");

    expect($exit)->toBe(0)
        ->and($output)->toContain('owner@example.com')->not->toContain('correct-horse-battery');

    $user = app(CurrentTenant::class)->bypass(fn (): User => User::query()->where('email', 'owner@example.com')->firstOrFail());

    expect($user->isSuperAdmin())->toBeTrue()
        ->and($user->organisation_id)->toBeNull()
        ->and(Hash::check('correct-horse-battery', $user->password))->toBeTrue()
        // A new user is inserted with the flag (BelongsToOrganisation's creating hook); SetSuperAdmin attaches the badge.
        ->and(withPermissionsTeam(null, fn (): bool => $user->hasRole(Role::SuperAdmin->value)))->toBeTrue();

    // The flag never changes for a new user, yet creating the platform's most privileged account is audited (§4.8).
    $granted = recordedActivity(ActivityEvent::SuperAdminGranted)->sole();
    expect([$granted->subject_id, $granted->causer_id])->toBe([$user->id, null]);
});

it('promotes an existing user through SetSuperAdmin (RL-9)', function (): void {
    $member = User::factory()->for(Organisation::factory()->create(['slug' => 'acme']))->create(['email' => 'member@example.com']);

    expect(Artisan::call('skv:create-super-admin', ['email' => 'member@example.com', '--no-interaction' => true]))->toBe(0);

    expect($member->fresh()?->isSuperAdmin())->toBeTrue()
        ->and(recordedActivity(ActivityEvent::SuperAdminGranted))->toHaveCount(1);
});

it('applies a given name and password to an existing user it promotes (RL-9)', function () use ($withStdin): void {
    $member = User::factory()->for(Organisation::factory()->create(['slug' => 'acme']))->create(['email' => 'member@example.com', 'name' => 'Old Name', 'password' => 'old-password-123']);
    $rememberToken = $member->remember_token;

    [$exit, $output] = $withStdin('skv:create-super-admin', ['email' => 'member@example.com', '--name' => 'New Name', '--password-stdin' => true, '--no-interaction' => true], "correct-horse-battery\n");

    expect($exit)->toBe(0)
        ->and($output)->not->toContain('correct-horse-battery');

    $member = $member->fresh();

    expect($member?->isSuperAdmin())->toBeTrue()
        ->and($member?->name)->toBe('New Name')
        ->and(Hash::check('correct-horse-battery', (string) $member?->password))->toBeTrue()
        ->and($member?->remember_token)->not->toBe($rememberToken)
        ->and(recordedActivity(ActivityEvent::PasswordChanged))->toHaveCount(1)
        ->and(recordedActivity(ActivityEvent::UserUpdated))->toHaveCount(1);
});

it('keeps an existing user\'s password when the new one is too weak (RL-9)', function () use ($withStdin): void {
    $member = User::factory()->for(Organisation::factory()->create(['slug' => 'acme']))->create(['email' => 'member@example.com', 'password' => 'old-password-123']);

    [$exit] = $withStdin('skv:create-super-admin', ['email' => 'member@example.com', '--password-stdin' => true, '--no-interaction' => true], "short\n");

    expect($exit)->toBe(1);

    expect($member->fresh()?->isSuperAdmin())->toBeFalse()
        ->and(Hash::check('old-password-123', (string) $member->fresh()?->password))->toBeTrue();
});

it('refuses a user who is not active (RL-9, g2-18)', function (UserStatus $status): void {
    $user = User::factory()->for(Organisation::factory()->create(['slug' => 'acme']))->create(['email' => 'inactive@example.com', 'status' => $status]);

    $exit = Artisan::call('skv:create-super-admin', ['email' => 'inactive@example.com', '--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain(__('Only an active user can become a super admin; :email is :status.', ['email' => 'inactive@example.com', 'status' => $status->label()]))
        ->and($user->fresh()?->isSuperAdmin())->toBeFalse();
})->with([
    'pending' => [UserStatus::PendingActivation],
    'disabled' => [UserStatus::Disabled],
]);

it('needs an email and a password without interaction', function (): void {
    expect(Artisan::call('skv:create-super-admin', ['--no-interaction' => true]))->toBe(1)
        ->and(Artisan::call('skv:create-super-admin', ['email' => 'new@example.com', '--no-interaction' => true]))->toBe(1)
        ->and(app(CurrentTenant::class)->bypass(fn (): bool => User::query()->where('email', 'new@example.com')->exists()))->toBeFalse();
});

it('no longer takes the password as an option, so it never reaches the process list (CD-8)', function (): void {
    expect(fn () => Artisan::call('skv:create-super-admin', ['email' => 'owner@example.com', '--password' => 'correct-horse-battery', '--no-interaction' => true]))
        ->toThrow(InvalidOptionException::class);
});

it('refuses an empty standard input for --password-stdin (CD-8)', function () use ($withStdin): void {
    [$exit, $output] = $withStdin('skv:create-super-admin', ['email' => 'owner@example.com', '--password-stdin' => true, '--no-interaction' => true], "\n");

    expect($exit)->toBe(1)
        ->and($output)->toContain(__('No password on standard input.'))
        ->and(Tenant::bypass(fn (): bool => User::query()->where('email', 'owner@example.com')->exists()))->toBeFalse();
});

it('reads only the first line, without its line ending (RL-10)', function () use ($withStdin): void {
    [$exit] = $withStdin('skv:create-super-admin', ['email' => 'owner@example.com', '--password-stdin' => true, '--no-interaction' => true], "correct-horse-battery\r\nsecond line\n");

    $user = Tenant::bypass(fn (): ?User => User::query()->where('email', 'owner@example.com')->first());

    expect($exit)->toBe(0)
        ->and(Hash::check('correct-horse-battery', (string) $user?->password))->toBeTrue();
});

it('reads standard input afresh on every run in the same process (RL-10)', function () use ($withStdin): void {
    [$firstExit] = $withStdin('skv:create-super-admin', ['email' => 'owner@example.com', '--password-stdin' => true, '--no-interaction' => true], "correct-horse-battery\n");
    [$secondExit, $output] = $withStdin('skv:create-super-admin', ['email' => 'second@example.com', '--password-stdin' => true, '--no-interaction' => true], "\n");

    expect([$firstExit, $secondExit])->toBe([0, 1])
        ->and($output)->toContain(__('No password on standard input.'))
        ->and(Tenant::bypass(fn (): bool => User::query()->where('email', 'second@example.com')->exists()))->toBeFalse();
});
