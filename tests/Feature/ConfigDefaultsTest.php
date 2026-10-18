<?php

declare(strict_types=1);

/** covers §4.1.2, §4.1.8, §4.3.8, §4.11.1 */

use App\Setup\Support\EnvFile;

/*
| The defaults the config files ship with. phpunit.xml sets APP_URL, TENANCY_* and APP_LOCALE for the suite, so these
| tests read a config file again with the variables of their case set or removed, and restore them afterwards.
*/

/**
 * The array the config file at $file (relative to the project) returns while each variable in $variables holds its value, or is unset when it is null.
 *
 * @param  array<string, string|null>  $variables
 * @return array<string, mixed>
 */
$configWith = function (string $file, array $variables): array {
    $saved = [];

    try {
        foreach ($variables as $name => $value) {
            $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            if ($value !== null) {
                $_ENV[$name] = $_SERVER[$name] = $value;
                putenv("{$name}={$value}");
            }
        }

        /** @var array<string, mixed> $config */
        $config = require base_path($file);

        return $config;
    } finally {
        foreach ($saved as $name => [$env, $server, $process]) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            if ($env !== null) {
                $_ENV[$name] = $env;
            }

            if ($server !== null) {
                $_SERVER[$name] = $server;
            }

            if ($process !== false) {
                putenv("{$name}={$process}");
            }
        }
    }
};

it('takes the apex from the APP_URL host and the admin subdomain "admin" unless they are set (§4.1.2)', function (array $variables, string $apex, string $admin) use ($configWith): void {
    $tenancy = $configWith('config/tenancy.php', $variables);

    expect([$tenancy['apex_domain'], $tenancy['admin_subdomain']])->toBe([$apex, $admin]);
})->with([
    'defaults' => [['APP_URL' => 'https://acme-platform.test:8443', 'TENANCY_APEX_DOMAIN' => null, 'TENANCY_ADMIN_SUBDOMAIN' => null], 'acme-platform.test', 'admin'],
    'set' => [['APP_URL' => 'https://acme-platform.test', 'TENANCY_APEX_DOMAIN' => 'example.org', 'TENANCY_ADMIN_SUBDOMAIN' => 'beheer'], 'example.org', 'beheer'],
    'an empty apex' => [['APP_URL' => 'https://acme-platform.test', 'TENANCY_APEX_DOMAIN' => '', 'TENANCY_ADMIN_SUBDOMAIN' => null], 'acme-platform.test', 'admin'],
    'no APP_URL' => [['APP_URL' => null, 'TENANCY_APEX_DOMAIN' => null, 'TENANCY_ADMIN_SUBDOMAIN' => null], 'localhost', 'admin'],
]);

it('reserves the seventeen slugs of the spec by default (§4.1.8, §4.3.8)', function () use ($configWith): void {
    expect($configWith('config/tenancy.php', [])['reserved_slugs'])->toBe([
        'admin', 'www', 'api', 'app', 'mail', 'smtp', 'ftp', 'static', 'assets', 'cdn', 'docs',
        'help', 'status', 'support', 'dashboard', 'login', 'test',
    ]);
});

it('speaks Dutch by default and falls back to English (§4.11.1)', function () use ($configWith): void {
    $variables = ['APP_LOCALE' => null, 'APP_FALLBACK_LOCALE' => null];
    $app = $configWith('config/app.php', $variables);
    $kit = $configWith('config/skv.php', $variables);
    $example = new EnvFile(base_path('.env.example'));

    expect([$app['locale'], $app['fallback_locale']])->toBe(['nl', 'en'])
        ->and($kit['locale'])->toBe(['default' => 'nl'])
        ->and([$example->get('APP_LOCALE'), $example->get('APP_FALLBACK_LOCALE')])->toBe(['nl', 'en']);
});
