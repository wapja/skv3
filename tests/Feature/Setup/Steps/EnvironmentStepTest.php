<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Steps\EnvironmentStep;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use Dotenv\Dotenv;
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
