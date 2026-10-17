<?php

declare(strict_types=1);

/** covers §4.2, §4.5 */

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Invitations\AcceptInvitation;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| The `hashed` cast stores a value that already is a hash as it is, so a bcrypt hash typed as a password became the
| account's hash and its plaintext the password, past the strength rule; a hash of another algorithm made the cast
| throw (a 500). Every path that sets a password refuses one (L7b-38's rule, on the web paths too).
*/

/**
 * The validation errors an action throws, or [] when it succeeds.
 *
 * @return array<string, array<int, string>>
 */
$errorsOf = function (Closure $action): array {
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
};

it('refuses a password hash as a new password on every web path', function (string $path, string $algorithm) use ($errorsOf): void {
    $hash = $algorithm === 'bcrypt' ? Hash::make('a') : password_hash('a', PASSWORD_ARGON2ID);
    $organisation = Organisation::factory()->create(['slug' => 'acme']);

    [$user, $errors] = match ($path) {
        'accepting an invitation' => (function () use ($organisation, $hash, $errorsOf): array {
            $invitation = inviteMember($organisation);
            onTenant($organisation);

            return [User::query()->findOrFail($invitation->user_id), $errorsOf(fn () => app(AcceptInvitation::class)->handle($invitation, 'Ann Invitee', $hash))];
        })(),
        'resetting a password' => (function () use ($organisation, $hash, $errorsOf): array {
            $member = User::factory()->for($organisation)->create();
            onTenant($organisation);

            return [$member, $errorsOf(fn () => app(ResetUserPassword::class)->reset($member, ['password' => $hash, 'password_confirmation' => $hash]))];
        })(),
        default => (function () use ($organisation, $hash): array {
            $member = asMember($organisation);
            $page = Livewire::test('pages::settings.security')
                ->set('current_password', 'password')
                ->set('password', $hash)
                ->set('password_confirmation', $hash)
                ->call('updatePassword');

            return [$member, $page->errors()->toArray()];
        })(),
    };

    expect($errors)->toBe(['password' => [__('Pass the password itself, not a hash.')]])
        ->and(DB::table('users')->where('id', $user->id)->value('password'))->not->toBe($hash);
})->with([
    'accepting an invitation',
    'resetting a password',
    'changing the password on the security page',
])->with(['bcrypt', 'argon2id']);
