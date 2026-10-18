<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\SetupContext;
use App\Setup\Steps\EnvironmentStep;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use Dotenv\Dotenv;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('writes the app name, address, apex, health key, faker locale and module flags (§5.3.3 step 1)', function (): void {
    $detection = new Detection(herd: true, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: false, host: 'my-app.test', scheme: 'http');
    $context = setupSandbox($detection);
    $context->answers = Answers::fromArray(['app_name' => 'My App', 'modules' => ['impersonation'], 'https' => false], $detection, 'my-app');

    $result = new EnvironmentStep()->run($context);
    $env = new EnvFile($context->path('.env'));

    expect($result->status)->toBe('done')
        ->and($env->get('APP_NAME'))->toBe('My App')
        ->and($env->get('APP_URL'))->toBe('http://my-app.test')
        ->and($env->get('TENANCY_APEX_DOMAIN'))->toBe('my-app.test')
        ->and(strlen((string) $env->get('HEALTH_CHECK_KEY')))->toBe(40)
        ->and(strlen((string) $env->get('BACKUP_ARCHIVE_PASSWORD')))->toBe(32)
        ->and($env->get('APP_FAKER_LOCALE'))->toBe('nl_NL')
        ->and($env->get('MODULE_IMPERSONATION'))->toBe('true')
        ->and($env->get('MODULE_PASSKEYS'))->toBe('false')
        ->and($env->get('MODULE_BACKUPS'))->toBe('false')
        ->and($env->get('MAIL_MAILER'))->toBe('log')
        ->and($result->message)->toContain(__('Mail is written to the log'));
});

it('keeps the health key and APP_KEY it finds (§5.3.3 step 1)', function (): void {
    $context = setupSandbox();
    $env = new EnvFile($context->path('.env'));
    $env->set(['HEALTH_CHECK_KEY' => str_repeat('k', 40), 'APP_KEY' => 'base64:existing']);

    new EnvironmentStep()->run($context);

    expect($env->get('HEALTH_CHECK_KEY'))->toBe(str_repeat('k', 40))->and($env->get('APP_KEY'))->toBe('base64:existing');
});

it('replaces a health key too short for /health-check, and is incomplete until then (§5.3.3 step 1, CD-4)', function (): void {
    $context = setupSandbox();
    $env = new EnvFile($context->path('.env'));
    $env->set(['HEALTH_CHECK_KEY' => str_repeat('k', 10)]);

    $before = new EnvironmentStep()->isComplete($context);
    new EnvironmentStep()->run($context);
    $written = (string) $env->get('HEALTH_CHECK_KEY');

    expect($before)->toBeFalse()
        ->and(strlen($written))->toBe(40)
        ->and($written)->not->toBe(str_repeat('k', 10))
        ->and(new EnvironmentStep()->isComplete($context))->toBeTrue();
});

it('keeps an existing generated secret, is incomplete while it is blank and generates it again (§4.9, g2-26)', function (string $key, int $length): void {
    $context = setupSandbox();
    $env = new EnvFile($context->path('.env'));
    $env->set([$key => str_repeat('p', $length)]);

    new EnvironmentStep()->run($context);

    expect($env->get($key))->toBe(str_repeat('p', $length))
        ->and(new EnvironmentStep()->isComplete($context))->toBeTrue();

    $env->set([$key => '']);

    expect(new EnvironmentStep()->isComplete($context))->toBeFalse();

    new EnvironmentStep()->run($context);

    expect(strlen((string) $env->get($key)))->toBe($length)
        ->and($env->get($key))->not->toBe(str_repeat('p', $length))
        ->and(new EnvironmentStep()->isComplete($context))->toBeTrue();
})->with(fn (): array => collect(EnvironmentStep::GENERATED)->map(fn (int $length, string $key): array => [$key, $length])->all());

it('generates a value only for the blank secrets, at their length, and writes nothing itself (RL-10, L-56)', function (): void {
    $env = new EnvFile(setupSandbox()->path('.env'));
    $env->set(['HEALTH_CHECK_KEY' => str_repeat('k', 40), 'BACKUP_ARCHIVE_PASSWORD' => '']);

    $first = EnvironmentStep::missingSecrets($env);
    $second = EnvironmentStep::missingSecrets($env);

    expect(array_keys($first))->toBe(['BACKUP_ARCHIVE_PASSWORD'])
        ->and(strlen($first['BACKUP_ARCHIVE_PASSWORD'] ?? ''))->toBe(32)
        ->and($second['BACKUP_ARCHIVE_PASSWORD'] ?? null)->not->toBe($first['BACKUP_ARCHIVE_PASSWORD'] ?? null)
        ->and($env->get('BACKUP_ARCHIVE_PASSWORD'))->toBe('')
        ->and($env->get('HEALTH_CHECK_KEY'))->toBe(str_repeat('k', 40));
});

it('uses Herd Pro mail unless the installer already set mail (§5.3.3 step 1)', function (bool $installerSetMail, string $expected): void {
    $detection = new Detection(herd: true, herdPro: true, herdMail: true, herdSmtpPort: 2526, postgres: false, fluxPro: false, claude: false, host: 'my-app.test', scheme: 'http');
    $context = setupSandbox($detection);
    $env = new EnvFile($context->path('.env'));

    if ($installerSetMail) {
        $env->set(['MAIL_MAILER' => 'smtp', 'MAIL_PORT' => '2525', 'MAIL_USERNAME' => 'my-app']);
    }

    new EnvironmentStep()->run($context);

    expect($env->get('MAIL_MAILER'))->toBe('smtp')->and($env->get('MAIL_PORT'))->toBe($expected);
})->with([
    'fresh' => [false, '2526'],
    'installer set it' => [true, '2525'],
]);

it('points the Herd Pro mail username at the app name (§5.3.3 step 1)', function (): void {
    $detection = new Detection(herd: true, herdPro: true, herdMail: true, herdSmtpPort: 2526, postgres: false, fluxPro: false, claude: false, host: 'my-app.test', scheme: 'http');
    $context = setupSandbox($detection);
    $context->answers = Answers::fromArray(['app_name' => 'My App'], $detection, 'my-app');

    new EnvironmentStep()->run($context);
    $contents = (string) file_get_contents($context->path('.env'));

    expect($contents)->toContain('MAIL_USERNAME="${APP_NAME}"')
        ->and(Dotenv::parse($contents)['MAIL_USERNAME'] ?? null)->toBe('My App')
        ->and(Dotenv::parse($contents)['MAIL_HOST'] ?? null)->toBe('127.0.0.1');
});

it('keeps the log mailer when Herd mail is configured without Herd Pro (§5.3.3 step 1)', function (): void {
    $detection = new Detection(herd: true, herdPro: false, herdMail: true, herdSmtpPort: 2526, postgres: false, fluxPro: false, claude: false, host: 'my-app.test', scheme: 'http');
    $context = setupSandbox($detection);

    $result = new EnvironmentStep()->run($context);

    expect(new EnvFile($context->path('.env'))->get('MAIL_MAILER'))->toBe('log')
        ->and($result->message)->toContain(__('Mail is written to the log'));
});

it('keeps an https APP_URL for the same host on a re-run (§5.3.3 step 1)', function (): void {
    $detection = new Detection(herd: true, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: false, host: 'my-app.test', scheme: 'http');
    $context = setupSandbox($detection);
    $env = new EnvFile($context->path('.env'));
    $env->set(['APP_URL' => 'https://my-app.test']);

    new EnvironmentStep()->run($context);

    expect($env->get('APP_URL'))->toBe('https://my-app.test');
});

it('does not keep https from an address on another host (§5.3.3 step 1)', function (): void {
    $detection = new Detection(herd: true, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: false, host: 'my-app.test', scheme: 'http');
    $context = setupSandbox($detection);
    $env = new EnvFile($context->path('.env'));
    $env->set(['APP_URL' => 'https://my-app.tester']);

    new EnvironmentStep()->run($context);

    expect($env->get('APP_URL'))->toBe('http://my-app.test');
});

it('is complete once the file holds the answers (§5.3.1)', function (): void {
    $context = setupSandbox();
    $step = new EnvironmentStep;

    expect($step->isComplete($context))->toBeFalse();
    $step->run($context);
    expect($step->isComplete($context))->toBeTrue();

    new EnvFile($context->path('.env'))->set(['MODULE_IMPERSONATION' => false]);
    expect($step->isComplete($context))->toBeFalse();
});

it('keeps the port of an APP_URL on the same host without Herd (g1-17)', function (): void {
    $context = setupSandbox();
    new EnvFile($context->path('.env'))->set(['APP_URL' => 'http://localhost:8000']);

    new EnvironmentStep()->run($context);

    expect(new EnvFile($context->path('.env'))->get('APP_URL'))->toBe('http://localhost:8000');
});

it('links public/storage through php artisan storage:link in the project, and is incomplete without it (G6-09, CD-12)', function (): void {
    // Arrange: a fresh checkout has no public/storage; the faked command makes the link storage:link would make.
    $context = setupSandbox();
    unlink($context->path('public/storage'));
    Process::fake(["*'artisan' 'storage:link' '--no-interaction'*" => function (PendingProcess $process) use ($context): ProcessResult {
        symlink($context->path('storage/app/public'), $context->path('public/storage'));

        return Process::result('The [public/storage] link has been connected to [storage/app/public].');
    }]);

    $result = new EnvironmentStep()->run($context);
    $linked = is_link($context->path('public/storage'));
    $complete = new EnvironmentStep()->isComplete($context);
    File::delete($context->path('public/storage'));

    expect($result->status)->toBe('done')
        ->and($linked)->toBeTrue()
        ->and($complete)->toBeTrue()
        ->and(new EnvironmentStep()->isComplete($context))->toBeFalse();
    Process::assertRan(fn (PendingProcess $process): bool => array_slice((array) $process->command, 1) === ['artisan', 'storage:link', '--no-interaction']
        && $process->path === $context->basePath);
});

it('leaves php artisan storage:link to run when the link does not appear, after writing .env (G6-09)', function (): void {
    $context = setupSandbox();
    unlink($context->path('public/storage'));
    Process::fake(["*'artisan' 'storage:link' '--no-interaction'*" => Process::result('', exitCode: 1)]);

    $result = new EnvironmentStep()->run($context);

    expect($result->status)->toBe('manual')
        ->and($result->command)->toBe('php artisan storage:link')
        ->and($result->message)->toContain(__('public/storage is missing'))
        ->and($result->message)->toContain(__('Mail is written to the log (storage/logs/laravel.log).'))
        ->and(strlen((string) new EnvFile($context->path('.env'))->get('HEALTH_CHECK_KEY')))->toBe(40);
});

/** Puts $kind in place of public/storage: a link to the project root, a dangling link or a directory (D63). */
$replaceStorageLink = function (SetupContext $context, string $kind): void {
    unlink($context->path('public/storage'));

    match ($kind) {
        'a link to the project root' => symlink($context->basePath, $context->path('public/storage')),
        'a dangling link' => symlink($context->path('storage/app/gone'), $context->path('public/storage')),
        default => File::ensureDirectoryExists($context->path('public/storage')), // 'a directory'
    };
};

it('is incomplete while public/storage is not a link to storage/app/public (D63)', function (string $kind) use ($replaceStorageLink): void {
    $context = setupSandbox();
    new EnvironmentStep()->run($context);
    $complete = new EnvironmentStep()->isComplete($context);

    $replaceStorageLink($context, $kind);

    expect($complete)->toBeTrue()
        ->and(new EnvironmentStep()->isComplete($context))->toBeFalse();
})->with(['a link to the project root', 'a dangling link', 'a directory']);

it('replaces a wrong or dangling link with storage:link --force (D63)', function (string $kind) use ($replaceStorageLink): void {
    $context = setupSandbox();
    $replaceStorageLink($context, $kind);
    Process::fake(["*'artisan' 'storage:link' '--no-interaction' '--force'*" => function () use ($context): ProcessResult {
        unlink($context->path('public/storage'));
        symlink($context->path('storage/app/public'), $context->path('public/storage'));

        return Process::result('The [public/storage] link has been connected to [storage/app/public].');
    }]);

    $result = new EnvironmentStep()->run($context);

    expect($result->status)->toBe('done')
        ->and(realpath($context->path('public/storage')))->toBe(realpath($context->path('storage/app/public')))
        ->and(new EnvironmentStep()->isComplete($context))->toBeTrue();
    Process::assertRan(fn (PendingProcess $process): bool => array_slice((array) $process->command, 1) === ['artisan', 'storage:link', '--no-interaction', '--force']);
})->with(['a link to the project root', 'a dangling link']);

it('never forces storage:link over a directory, and names what is in the way (D63)', function () use ($replaceStorageLink): void {
    $context = setupSandbox();
    $replaceStorageLink($context, 'a directory');
    File::put($context->path('public/storage/kept.txt'), 'a file someone put there');
    // storage:link refuses an existing directory: "The [public/storage] link already exists."
    Process::fake(["*'artisan' 'storage:link' '--no-interaction'*" => Process::result('The [public/storage] link already exists.')]);

    $result = new EnvironmentStep()->run($context);

    expect($result->status)->toBe('manual')
        ->and($result->command)->toBe('php artisan storage:link')
        ->and($result->message)->toContain(__('public/storage is not a link to storage/app/public'))
        ->and($result->message)->toContain(__('Move public/storage aside, then run php artisan storage:link'))
        ->and(is_file($context->path('public/storage/kept.txt')))->toBeTrue();
    Process::assertRan(fn (PendingProcess $process): bool => array_slice((array) $process->command, 1) === ['artisan', 'storage:link', '--no-interaction']);
    Process::assertDidntRun(fn (PendingProcess $process): bool => in_array('--force', (array) $process->command, true));
});

it('leaves storage:link --force to run when a wrong link stays (D63)', function () use ($replaceStorageLink): void {
    $context = setupSandbox();
    $replaceStorageLink($context, 'a link to the project root');
    Process::fake(["*'artisan' 'storage:link'*" => Process::result('', exitCode: 1)]);

    $result = new EnvironmentStep()->run($context);

    expect($result->status)->toBe('manual')
        ->and($result->command)->toBe('php artisan storage:link --force')
        ->and($result->message)->toContain(__('public/storage is not a link to storage/app/public'))
        ->and($result->message)->not->toContain(__('Move public/storage aside, then run php artisan storage:link'));
});

it('starts no storage:link when public/storage already exists (G6-09)', function (): void {
    $context = setupSandbox();

    expect(new EnvironmentStep()->run($context)->status)->toBe('done');
    Process::assertDidntRun(fn (PendingProcess $process): bool => in_array('storage:link', (array) $process->command, true));
});
