<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('writes variables atomically and keeps the rest of the file (§5.3.3 step 1)', function (): void {
    $context = setupSandbox();
    $env = new EnvFile($context->path('.env'));

    $env->set(['APP_NAME' => 'My App', 'MODULE_BACKUPS' => false, 'NEW_KEY' => 'with space']);

    expect($env->get('APP_NAME'))->toBe('My App')
        ->and($env->get('MODULE_BACKUPS'))->toBe('false')
        ->and($env->get('NEW_KEY'))->toBe('with space')
        ->and($env->get('SESSION_COOKIE'))->toBe('app_session')
        ->and(glob($context->path('.env*.tmp')) ?: [])->toBe([]);
});

it('leaves existing values alone unless asked to overwrite', function (): void {
    $env = new EnvFile(setupSandbox()->path('.env'));

    $env->set(['APP_NAME' => 'First']);
    $env->set(['APP_NAME' => 'Second'], overwrite: false);

    expect($env->get('APP_NAME'))->toBe('First');
});

it('keeps the mode of the environment file', function (int $mode): void {
    $context = setupSandbox();
    chmod($context->path('.env'), $mode);

    new EnvFile($context->path('.env'))->set(['APP_NAME' => 'X']);

    clearstatcache();
    expect(fileperms($context->path('.env')) & 0777)->toBe($mode);
})->with(['owner only' => [0600], 'group readable' => [0640]]);

it('refuses a value that would not read back and leaves the file untouched', function (string $value): void {
    $context = setupSandbox();
    $before = (string) file_get_contents($context->path('.env'));

    $message = null;

    try {
        new EnvFile($context->path('.env'))->set(['APP_NAME' => $value]);
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
    }

    expect($message)->toContain('APP_NAME')->not->toContain('Shop')
        ->and(file_get_contents($context->path('.env')))->toBe($before)
        ->and(new EnvFile($context->path('.env'))->get('INJECTED'))->toBeNull()
        ->and(glob($context->path('.env*.tmp')) ?: [])->toBe([]);
})->with([
    'both quote kinds' => ['Bob\'s "Shop"'],
    'a backslash inside single quotes' => ['C:\\Shop "x"'],
    'a newline that adds a key' => ["Shop\"' \nINJECTED=true\nY='"],
    'a carriage return' => ["Shop\rINJECTED=true"],
    'a NUL byte' => ["Shop\0"],
]);

it('refuses a key that is not an environment variable name', function (string $key): void {
    $context = setupSandbox();
    $before = (string) file_get_contents($context->path('.env'));

    expect(fn () => new EnvFile($context->path('.env'))->set([$key => 'x']))->toThrow(RuntimeException::class);
    expect(file_get_contents($context->path('.env')))->toBe($before);
})->with(['lower case' => ['app_name'], 'a space' => ['APP NAME'], 'a newline' => ["A\nINJECTED"], 'a leading digit' => ['1APP'], 'empty' => ['']]);

it('writes quotes, backslashes and variable references that read back', function (): void {
    $env = new EnvFile(setupSandbox()->path('.env'));

    $env->set(['APP_NAME' => 'Bob\'s Shop', 'NEW_QUOTED' => 'Say "hi"', 'NEW_PATH' => 'C:\\shop', 'MAIL_USERNAME' => '${APP_NAME}']);

    expect($env->get('APP_NAME'))->toBe('Bob\'s Shop')
        ->and($env->get('NEW_QUOTED'))->toBe('Say "hi"')
        ->and($env->get('NEW_PATH'))->toBe('C:\\shop')
        ->and($env->get('MAIL_USERNAME'))->toBe('Bob\'s Shop');
});

it('words its refusals in the active language (g2-15)', function (string $file, array $variables, string $dutch): void {
    $path = setupSandbox()->path($file);
    app()->setLocale('nl');

    expect(fn () => new EnvFile($path)->set($variables))->toThrow(RuntimeException::class, str_replace('{path}', $path, $dutch));
})->with([
    'a missing file' => ['missing.env', ['APP_NAME' => 'x'], 'Het omgevingsbestand [{path}] bestaat niet.'],
    'a bad name' => ['.env', ['app_name' => 'x'], 'De naam van een omgevingsvariabele mag alleen hoofdletters, cijfers en underscores bevatten.'],
    'a line break' => ['.env', ['APP_NAME' => "Shop\nX"], 'De waarde voor [APP_NAME] bevat een regeleinde of een NUL-byte.'],
]);
