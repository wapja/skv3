<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToOrganisation;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use LogicException;

/**
 * An invitation to join an organisation (§4.5), the single source of truth for it (W13). The token
 * is stored as a sha256 hash for the lookup and as an encrypted copy for mails and "Copy invite
 * link"; the copy is cleared on accept, so an accepted link can never be rebuilt (RK-B3). Tenant
 * model: the lookup runs under the tenant scope, so a link opened on another host finds nothing (W14).
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $user_id
 * @property int|null $invited_by
 * @property string $token_hash
 * @property string|null $token
 * @property Carbon $expires_at
 * @property Carbon|null $reminder_sent_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read Organisation|null $organisation
 */
#[Fillable(['user_id', 'invited_by', 'token_hash', 'token', 'expires_at', 'reminder_sent_at', 'accepted_at'])]
#[Hidden(['token', 'token_hash'])]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use BelongsToOrganisation, HasFactory, SoftDeletes;

    /** The invitation for a plain token, within the current tenant only (W14). */
    public static function findByToken(string $token): ?self
    {
        return self::query()->where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * The pending user; soft-deleted users included, so a cancelled invitee still resolves.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * The one place that builds the accept link (§4.5): a relative signed path, so it survives
     * http↔https and host rewrites between queueing and clicking, on the organisation's host. The
     * signature lives signature_grace_days past the domain expiry, so a just-expired link reaches
     * the "expired" page and only links older than the grace period get a 403. Built at send time
     * in the worker (FU3-14); after a slug rename a reminder rebuilds it (X13).
     */
    public function acceptUrl(): string
    {
        $token = $this->token ?? throw new LogicException("Invitation [{$this->id}] keeps no token any more.");
        $organisation = $this->organisation ?? throw new LogicException("Invitation [{$this->id}] has no active organisation.");

        return tenant_url($organisation, URL::temporarySignedRoute(
            'invitations.accept',
            $this->expires_at->copy()->addDays((int) config('skv.invitations.signature_grace_days', 30)),
            ['token' => $token],
            absolute: false,
        ));
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'expires_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }
}
