<?php

declare(strict_types=1);

namespace App\Setup\Steps;

use App\Setup\SetupContext;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use App\Setup\Support\EnvFile;
use Illuminate\Support\Str;

/**
 * §5.3.3 step 1: the app name, address and apex, the generated secrets (GENERATED), the faker locale, the module
 * flags and, when Herd Pro mail is active and the installer left mail alone, Herd's SMTP server. Never writes APP_KEY
 * or DB_*.
 */
final class EnvironmentStep implements SetupStep
{
    /** The secrets setup generates once when blank, with their lengths (§4.9): never overwritten, never printed. */
    public const array GENERATED = ['HEALTH_CHECK_KEY' => 40, 'BACKUP_ARCHIVE_PASSWORD' => 32];

    public function key(): string
    {
        return 'environment';
    }

    public function label(): string
    {
        return __('Environment file');
    }

    public function isComplete(SetupContext $context): bool
    {
        $env = new EnvFile($context->path('.env'));

        if (! $env->exists()) {
            return false;
        }

        foreach (array_keys(self::GENERATED) as $key) {
            if (($env->get($key) ?? '') === '') {
                return false;
            }
        }

        foreach ($this->variables($context, $env) as $key => $value) {
            $actual = $env->get($key);

            $matches = match ($key) {
                'APP_URL' => parse_url((string) $actual, PHP_URL_HOST) === $context->detection->host,
                // Dotenv resolves the ${APP_NAME} reference when it reads the file.
                default => $actual === str_replace('${APP_NAME}', $context->answers->appName, $this->stringValue($value)),
            };

            if (! $matches) {
                return false;
            }
        }

        return true;
    }

    public function isLong(): bool
    {
        return false;
    }

    public function run(SetupContext $context): StepResult
    {
        $env = new EnvFile($context->path('.env'));
        $variables = $this->variables($context, $env);

        foreach (self::GENERATED as $key => $length) {
            if (($env->get($key) ?? '') === '') {
                $variables[$key] = Str::random($length);
            }
        }

        $note = $this->installerSetMail($env) || array_key_exists('MAIL_MAILER', $variables)
            ? ''
            : __('Mail is written to the log (storage/logs/laravel.log).');

        $env->set($variables);

        return StepResult::done($note);
    }

    /**
     * The values this step writes, except the generated secrets (GENERATED).
     *
     * @return array<string, string|bool|int>
     */
    private function variables(SetupContext $context, EnvFile $env): array
    {
        $answers = $context->answers;
        $detection = $context->detection;
        $host = $detection->host;

        // A site the Herd step already secured keeps https on a re-run (the CLI always detects http).
        $current = (string) $env->get('APP_URL');
        $secured = parse_url($current, PHP_URL_SCHEME) === 'https' && parse_url($current, PHP_URL_HOST) === $host;

        $variables = [
            'APP_NAME' => $answers->appName,
            'APP_URL' => ($secured ? 'https' : $detection->scheme).'://'.$host,
            'TENANCY_APEX_DOMAIN' => $host,
            'APP_FAKER_LOCALE' => 'nl_NL',
            'MODULE_PASSKEYS' => $answers->moduleEnabled('passkeys'),
            'MODULE_BACKUPS' => $answers->moduleEnabled('backups'),
            'MODULE_IMPERSONATION' => $answers->moduleEnabled('impersonation'),
        ];

        if ($detection->herdPro && $detection->herdMail && $detection->herdSmtpPort !== null && ! $this->installerSetMail($env)) {
            $variables += [
                'MAIL_MAILER' => 'smtp',
                'MAIL_HOST' => '127.0.0.1',
                'MAIL_PORT' => $detection->herdSmtpPort,
                'MAIL_USERNAME' => '${APP_NAME}',
            ];
        }

        return $variables;
    }

    /** Anything but Laravel's default log mailer was chosen before setup (by the installer or the owner). */
    private function installerSetMail(EnvFile $env): bool
    {
        return ($env->get('MAIL_MAILER') ?? 'log') !== 'log';
    }

    /** The value as EnvFile writes it. */
    private function stringValue(string|bool|int $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
}
