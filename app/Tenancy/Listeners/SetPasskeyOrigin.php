<?php

declare(strict_types=1);

namespace App\Tenancy\Listeners;

use App\Tenancy\Hosts;

/**
 * Passkey ceremonies are checked against the current host's origin (§4.2, Appendix A P1), with APP_URL's scheme and
 * port like every link the kit builds (CD-4, G1-06); the relying-party id stays the apex. Reads the request's host,
 * not the tenant, so the value stays the browser's host inside Tenant::run().
 */
final class SetPasskeyOrigin
{
    public function handle(): void
    {
        config(['passkeys.allowed_origins' => [Hosts::origin(request()->getHost())]]);
    }
}
