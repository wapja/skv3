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
     * there (RL-3). Without Herd only a local name: localhost, *.localhost or *.test. Never an IP address (g3-34): the
     * tenant hosts and passkeys need a name, and *.localhost resolves to this machine in browsers (DEC-29).
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

        $local = $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');

        return $local ? $host : null;
    }

    /**
     * Why the folder can be no Herd address, or null (g3-10): under Herd the folder is the site name and the address
     * is {folder}.{tld}, so a folder that is not one DNS label (my_app, my.app) gives an address nobody can open.
     */
    public static function folderProblem(string $folder): ?string
    {
        if (preg_match('/^'.self::LABEL.'$/', Str::lower($folder)) === 1) {
            return null;
        }

        return __('The folder name :folder is not a valid web address under Herd. Rename the folder to :name, then start the setup again.', [
            'folder' => $folder,
            'name' => Str::slug($folder),
        ]);
    }
}
