<?php

declare(strict_types=1);

/** covers §5.3.3, §6.8, §13 */

use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Marker;
use App\Setup\Steps\HerdSiteStep;
use App\Setup\Summary;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeout;
use Symfony\Component\Process\Process as SymfonyProcess;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $this->detection = new Detection(herd: true, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: false, host: 'my-app.test', scheme: 'http');
    // Stubs for herd and php in a sandbox home: the step never sees the real Herd.
    $this->home = storage_path('framework/testing/setup/home-'.Str::uuid());

    foreach (['herd', 'php'] as $binary) {
        $path = $this->home.'/Library/Application Support/Herd/bin/'.$binary;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "#!/bin/sh\n");
        chmod($path, 0755);
    }

    $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->home));
    app()->instance(Toolchain::class, new Toolchain($this->home));
});

it('is skipped without Herd', function (): void {
    expect(new HerdSiteStep()->run(setupSandbox())->status)->toBe('skipped');
});

it('links an unparked site, secures it and rewrites APP_URL to https after verifying (§5.3.3 step 10)', function (): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => true], $this->detection, $context->folderName());
    $name = $context->folderName();

    Process::fake([
        "*herd' 'paths'*" => Process::result(json_encode(['/somewhere/else/'], JSON_THROW_ON_ERROR)),
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'link'*" => Process::result('linked'),
        "*herd' 'secured'*" => Process::result("| Site | Expires |\n| {$name}.test | 2027-01-01 00:00:00 GMT |\n"),
        "*herd' 'secure'*" => Process::result('Secured', exitCode: 0),
        "*herd' 'isolated'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.4.13'),
    ]);

    $result = new HerdSiteStep()->run($context);

    // APP_URL uses the detected host; herd commands use the folder name (in real installs they agree).
    expect($result->message)->toStartWith(HerdSiteStep::SECURED)
        ->and(new EnvFile($context->path('.env'))->get('APP_URL'))->toBe('https://my-app.test');
    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), "link {$name}") && $process->path === $context->basePath);
});

it('treats a parked directory listed with a trailing slash as parked (§5.3.3 step 10)', function (): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => false, 'modules' => []], $this->detection, $context->folderName());

    Process::fake([
        "*herd' 'paths'*" => Process::result(json_encode([dirname($context->basePath).'/'], JSON_THROW_ON_ERROR)),
        "*herd' 'isolated'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.4.13'),
    ]);

    new HerdSiteStep()->run($context);

    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', (array) $process->command), ' link '));
});

it('does not trust herd secure\'s exit code (§13)', function (): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => true], $this->detection, $context->folderName());

    Process::fake([
        "*herd' 'paths'*" => Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)),
        // A similar name must not count as secured: rows are matched whole.
        "*herd' 'secured'*" => Process::result("| x{$context->folderName()}.test | 2027-01-01 |\n"),
        "*herd' 'secure'*" => Process::result('Error finding executable PHP', exitCode: 0),
        "*herd' 'isolated'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.4.13'),
    ]);

    $result = new HerdSiteStep()->run($context);

    expect($result->isManual())->toBeTrue()
        ->and($result->command)->toBe('herd secure '.$context->folderName())
        ->and(new EnvFile($context->path('.env'))->get('APP_URL'))->not->toStartWith('https://');
});

it('isolates PHP 8.4 only when the site would run an older PHP (§5.3.3 step 10)', function (string $global, ?string $isolatedTo, bool $isolates): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => false, 'modules' => []], $this->detection, $context->folderName());
    $name = $context->folderName();

    Process::fake([
        "*herd' 'paths'*" => Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)),
        "*herd' 'isolated'*" => Process::result($isolatedTo === null ? '' : "| Site | PHP Version |\n| {$name}.test | {$isolatedTo} |\n"),
        "*herd' 'isolate'*" => Process::result('isolated'),
        "*php' '-r'*" => Process::result($global),
    ]);

    new HerdSiteStep()->run($context);

    $isolates
        ? Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'isolate 8.4 --site='.$context->folderName()))
        : Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'isolate 8.4'));
})->with([
    'global 8.3, not isolated' => ['8.3.20', null, true],
    'global 8.4, not isolated' => ['8.4.13', null, false],
    'global 8.4, isolated to 8.3' => ['8.4.13', '8.3', true],
    'global 8.3, isolated to 8.4' => ['8.3.20', '8.4', false],
]);

it('rewrites the marker summary with the https address after securing (§5.3.4)', function (): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => true], $this->detection, $context->folderName());
    Marker::write($context, Summary::build($context));

    Process::fake([
        "*herd' 'paths'*" => Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)),
        "*herd' 'secured'*" => Process::result("| {$context->folderName()}.test | 2027-01-01 |\n"),
        "*herd' 'isolated'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.4.13'),
    ]);

    new HerdSiteStep()->run($context);

    expect(Marker::read($context)['summary']['urls'] ?? [])->toContain('https://my-app.test');
});

it('is complete once served and secured, and not when herd fails (§5.3.1)', function (string $secured, bool $fails, bool $complete): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => true], $this->detection, $context->folderName());

    Process::fake([
        "*herd' 'paths'*" => $fails
            ? fn () => throw new ProcessTimedOutException(new SymfonyTimeout(new SymfonyProcess(['herd']), SymfonyTimeout::TYPE_GENERAL), Process::result(''))
            : Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)),
        "*herd' 'secured'*" => Process::result(str_replace('{name}', $context->folderName(), $secured)),
    ]);

    expect(new HerdSiteStep()->isComplete($context))->toBe($complete);
})->with([
    'secured' => ["| {name}.test | 2027-01-01 |\n", false, true],
    'not secured' => ['', false, false],
    'herd times out' => ["| {name}.test | 2027-01-01 |\n", true, false],
]);
