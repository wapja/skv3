<?php

declare(strict_types=1);

namespace App\Tenancy\Auth;

use App\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Laravel\Passkeys\Passkey;

/**
 * Passkey login loads its user without the tenant scope and including deleted users (§4.1, Appendix A P2);
 * HostLoginRule refuses deleted and inactive users.
 *
 * The table attribute keeps the vendor table (the class name would derive `unscoped_passkeys`).
 * Not final: Larastan rejects the `$this` relation generic of a final subclass as incompatible
 * with the vendor method's.
 */
#[Table(name: 'passkeys')]
class UnscopedPasskey extends Passkey
{
    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        return parent::user()->withoutGlobalScopes([TenantScope::class, SoftDeletingScope::class]);
    }
}
