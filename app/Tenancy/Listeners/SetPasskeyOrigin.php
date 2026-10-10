<?php

declare(strict_types=1);

namespace App\Tenancy\Listeners;

/**
 * Passkey ceremonies are checked against the origin of the current request (§4.2, Appendix A P1);
 * the relying-party id stays the apex. Reads the request, not the tenant, so the value stays the
 * browser origin inside Tenant::run().
 */
final class SetPasskeyOrigin
{
    public function handle(): void
    {
        config(['passkeys.allowed_origins' => [request()->getSchemeAndHttpHost()]]);
    }
}
