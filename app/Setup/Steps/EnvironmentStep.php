<?php

declare(strict_types=1);

namespace App\Setup\Steps;

use App\Setup\SetupContext;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use App\Support\Health\HealthCheckKey;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * §5.3.3 step 1: the app name, address and apex, the generated secrets (GENERATED), the faker locale, the module
 * flags and, when Herd Pro mail is active and the installer left mail alone, Herd's SMTP server. Never writes APP_KEY
 * or DB_*. It also creates public/storage (G6-09, CD-12): this step always runs, while the frontend step is skipped
 * when a build exists. Only a public/storage that resolves to storage/app/public counts (D63): a wrong or dangling
 * link is replaced, a directory or file in its place is never removed.
 */
final readonly class EnvironmentStep implements SetupStep
{
    /** The secrets setup generates while they are missing (missingSecrets()), with their lengths (§4.9): a usable value is never overwritten, never printed. */
    public const array GENERATED = ['HEALTH_CHECK_KEY' => 40, 'BACKUP_ARCHIVE_PASSWORD' => 32];

    public function __construct(private ?Toolchain $toolchain = null) {}

    /**
     * A fresh random value, of its GENERATED length, for every generated secret that is missing in $env (RL-10): blank,
     * or for HEALTH_CHECK_KEY shorter than HealthCheckKey::MIN_LENGTH, because /health-check refuses such a key, so no
     * monitor can be using it (CD-4). Setup, the production bootstrap and the backup-password repair fill the keys the
     * same way. It never writes, and never replaces a usable value: that is what a monitor or the archives use already.
     *
     * @return array<string, string>
     */
    public static function missingSecrets(EnvFile $env): array
    {
        $values = $env->all();
        $secrets = [];

        foreach (self::GENERATED as $key => $length) {
            $value = $values[$key] ?? '';

            if ($value === '' || ($key === 'HEALTH_CHECK_KEY' && ! HealthCheckKey::isUsable($value))) {
                $secrets[$key] = Str::random($length);
            }
        }

        return $secrets;
    }

    /**
     * Whether $link resolves to $target, read past PHP's stat cache (D63): a link to anything else (the project root
     * would serve .env under /storage), a dangling link, or a directory or a file in its place is not the storage link.
     * A link that reaches the target through another link, or a relative one, is. The doctor's `storage-link` check
     * reads public/storage the same way.
     */
    public static function isStorageLink(string $link, string $target): bool
    {
        clearstatcache(true, $link);
        $resolved = realpath($link);

        return $resolved !== false && $resolved === realpath($target);
    }

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

        if (self::missingSecrets($env) !== [] || ! $this->storageIsLinked($context)) {
            return false;
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
        $variables = $this->variables($context, $env) + self::missingSecrets($env);

        $note = $this->installerSetMail($env) || array_key_exists('MAIL_MAILER', $variables)
            ? ''
            : __('Mail is written to the log (storage/logs/laravel.log).');

        $env->set($variables);

        if (! $this->linkStorage($context)) {
            $link = $context->path('public/storage');

            return StepResult::manual(
                is_link($link) ? 'php artisan storage:link --force' : 'php artisan storage:link',
                trim($this->storageProblem($link).'. '.$note),
            );
        }

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
        $currentHost = parse_url($current, PHP_URL_HOST);
        $secured = parse_url($current, PHP_URL_SCHEME) === 'https' && $currentHost === $host;
        // Without Herd the app may run on a port (php artisan serve): the port the wizard was opened on, else the one
        // of an APP_URL on this host (g1-17).
        $port = $detection->port ?? (! $detection->herd && $currentHost === $host ? parse_url($current, PHP_URL_PORT) : null);

        $variables = [
            'APP_NAME' => $answers->appName,
            'APP_URL' => ($secured ? 'https' : $detection->scheme).'://'.$host.(is_int($port) ? ':'.$port : ''),
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

    /** public/storage resolves to storage/app/public (D63; the doctor's `storage-link` check). */
    private function storageIsLinked(SetupContext $context): bool
    {
        return self::isStorageLink($context->path('public/storage'), $context->path('storage/app/public'));
    }

    /** Why public/storage is still not the storage link, for the manual result (D63). */
    private function storageProblem(string $link): string
    {
        if (is_link($link)) {
            return __('public/storage is not a link to storage/app/public');
        }

        if (file_exists($link)) {
            return __('public/storage is not a link to storage/app/public').'. '.__('Move public/storage aside, then run php artisan storage:link');
        }

        return __('public/storage is missing');
    }

    /**
     * `php artisan storage:link` as a child process in the project (G6-09, CD-12), so it links the project and honours
     * its filesystems.links: the CLI php next to the running one, because the wizard runs this step under php-fpm, and
     * the toolchain's environment (DEC-23). A wrong or dangling link is replaced with --force, which storage:link
     * applies to a link only: a directory or a file in its place is never removed (D63). The link is read back, never
     * the exit code; false when it is still not the storage link.
     */
    private function linkStorage(SetupContext $context): bool
    {
        if ($this->storageIsLinked($context)) {
            return true;
        }

        $toolchain = $this->toolchain ?? app(Toolchain::class);
        $php = $toolchain->phpCli();

        if ($php === null) {
            return false;
        }

        $force = is_link($context->path('public/storage')) ? ['--force'] : [];

        try {
            Process::path($context->basePath)->env($toolchain->environment())->timeout(60)
                ->run([$php, 'artisan', 'storage:link', '--no-interaction', ...$force]);
        } catch (ProcessTimedOutException) {
            return false;
        }

        return $this->storageIsLinked($context);
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
