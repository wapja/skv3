<?php

declare(strict_types=1);

namespace App\Setup;

use Illuminate\Support\Str;

/** The host setup may write into APP_URL and TENANCY_APEX_DOMAIN (RL-3, A4, A19). */
final class SetupHost
{
    private const string LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /** Lower-cased, without the port; null when it is not a plain DNS name or an IPv4 address. */
    public static function normalise(string $host): ?string
    {
        $host = Str::lower(trim($host));
        $host = preg_replace('/:\d{1,5}$/', '', $host) ?? '';

        if ($host === '' || strlen($host) > 253) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }

        return preg_match('/^'.self::LABEL.'(?:\.'.self::LABEL.')*$/', $host) === 1 ? $host : null;
    }

    /**
     * The apex the wizard sets up. Under Herd only {folder}.{tld} or admin.{folder}.{tld}, pinned to {folder}.{tld}:
     * once the marker exists a tenant subdomain without an organisation answers 404, so the Herd stage could not finish
     * there (RL-3). Without Herd only a local name: localhost, 127.0.0.1, *.localhost or *.test.
     */
    public static function forWizard(string $requestHost, ?string $herdTld, string $folder): ?string
    {
        $host = self::normalise($requestHost);

        if ($host === null) {
            return null;
        }

        if ($herdTld !== null) {
            $apex = Str::lower($folder).'.'.$herdTld;

            return $host === $apex || $host === 'admin.'.$apex ? $apex : null;
        }

        $local = in_array($host, ['localhost', '127.0.0.1'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');

        return $local ? $host : null;
    }
}
