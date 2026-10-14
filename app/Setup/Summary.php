<?php

declare(strict_types=1);

namespace App\Setup;

use App\Authorization\Role;
use App\Setup\Steps\HerdSiteStep;
use App\Setup\Support\EnvFile;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Str;

/**
 * What setup tells the owner at the end (§5.3.3 step 11): the addresses, the logins, every command left to run by
 * hand, the notes the steps returned and the next steps. The only password it ever holds is the shared demo password
 * (§4.12).
 */
final class Summary
{
    /** @return array{urls: list<string>, logins: list<array{email: string, password: ?string, url: string}>, manual: list<array{step: string, command: string, reason: string}>, notes: list<string>, next: list<string>} */
    public static function build(SetupContext $context): array
    {
        $env = new EnvFile($context->path('.env'));
        $host = Str::lower($context->detection->host);
        $appUrl = $env->get('APP_URL') ?: $context->detection->scheme.'://'.$host;
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: $context->detection->scheme;
        $adminUrl = $scheme.'://'.Str::lower($env->get('TENANCY_ADMIN_SUBDOMAIN') ?: 'admin').'.'.$host;
        $demo = $context->answers->startWith === 'demo';

        $urls = [$appUrl, $adminUrl];
        $logins = [];

        if ($demo) {
            foreach (DemoSeeder::SLUGS as $slug) {
                $urls[] = "{$scheme}://{$slug}.{$host}";
            }

            foreach (DemoSeeder::accounts($host) as $account) {
                $logins[] = [
                    'email' => $account['email'],
                    'password' => DemoSeeder::PASSWORD,
                    'url' => $account['role'] === Role::SuperAdmin->value ? $adminUrl : $scheme.'://'.$account['host'],
                ];
            }
        } else {
            $logins[] = ['email' => (string) $context->answers->superAdminEmail, 'password' => null, 'url' => $adminUrl];
        }

        $manual = [];
        $notes = [];

        foreach ($context->results as $step => $result) {
            if ($result->isManual()) {
                $manual[] = ['step' => $step, 'command' => (string) $result->command, 'reason' => $result->message];
            } elseif ($result->status === 'done' && $result->message !== '') {
                // The Herd step's machine prefix (RL-7) is for skv:install, not for the owner.
                $notes[] = Str::chopStart($result->message, HerdSiteStep::SECURED.': ');
            }
        }

        return [
            'urls' => array_values(array_unique($urls)),
            'logins' => $logins,
            'manual' => $manual,
            'notes' => [...$notes, ...$context->notes],
            'next' => self::nextSteps($context),
        ];
    }

    /** @return list<string> what the owner does after setup: work with composer dev, sign in, check with the doctor */
    private static function nextSteps(SetupContext $context): array
    {
        return [
            __('Run composer dev while you work: it starts Vite, the queue worker, the logs and the scheduler.'),
            $context->answers->startWith === 'demo'
                ? __('Sign in with one of the demo accounts above; the login page also lists them under Quick login.')
                : __('Sign in as :email on the admin host and create your first organisation under Organisations.', ['email' => (string) $context->answers->superAdminEmail]),
            __('Run php artisan skv:doctor whenever you want to check the installation.'),
        ];
    }
}
