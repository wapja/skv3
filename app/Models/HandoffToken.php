<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A single-use, host-bound sign-in link (§6.4). Central (no organisation); only the hash is stored. The link is bound
 * to the password it was issued under (CD-3): a password changed since, by any path, ends it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string $target_host
 * @property string $path
 * @property string|null $password_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'token_hash', 'target_host', 'path', 'password_hash', 'expires_at'])]
final class HandoffToken extends Model
{
    use MassPrunable;

    /**
     * The value that binds a link to $user's current password (CD-3): the HMAC that AuthenticateSession keeps in the
     * session, keyed with APP_KEY, so the password hash itself is never stored a second time.
     */
    public static function passwordBindingFor(User $user): string
    {
        return Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword());
    }

    /** Whether this link was issued under $user's current password; a link from before the binding (null) never was. */
    public function isBoundTo(User $user): bool
    {
        return is_string($this->password_hash) && hash_equals($this->password_hash, self::passwordBindingFor($user));
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()->where('expires_at', '<', now()->subDay());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
