<?php

declare(strict_types=1);

/** covers §5.3.3, §6.8, §13 */

use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Marker;
use App\Setup\SetupContext;
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

/** A `herd links` table (Herd 1.30.2) with one row: | Site | SSL | URL | Path | PHP Version | node |; unsecured sites have an empty SSL cell. */
$herdLinksRow = fn (string $name, string $path, string $ssl = 'X', string $node = 'v22.23.2'): string => "+------+-----+-----+------+-------------+---+\n"
    ."| Site | SSL | URL | Path | PHP Version |   |\n"
    ."+------+-----+-----+------+-------------+---+\n"
    ."| {$name} | {$ssl} | http://{$name}.test | {$path} | 8.4 | {$node} |\n"
    ."+------+-----+-----+------+-------------+---+\n";

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

it('links an unparked site, secures it and rewrites APP_URL to https after verifying (§5.3.3 step 10)', function () use ($herdLinksRow): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => true], $this->detection, $context->folderName());
    $name = $context->folderName();

    Process::fake([
        "*herd' 'paths'*" => Process::result(json_encode(['/somewhere/else/'], JSON_THROW_ON_ERROR)),
        // Unlisted until `herd link` ran, then listed with this project's path.
        "*herd' 'links'*" => Process::sequence()->push(Process::result(''))->whenEmpty(Process::result($herdLinksRow($name, $context->basePath))),
        "*herd' 'link'*" => Process::result('linked'),
        // Unsecured until `herd secure` ran, then listed.
        "*herd' 'secured'*" => Process::sequence()->push(Process::result(''))->whenEmpty(Process::result("| Site | Expires |\n| {$name}.test | 2027-01-01 00:00:00 GMT |\n")),
        "*herd' 'secure'*" => Process::result('Secured', exitCode: 0),
        "*herd' 'isolated'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.4.13'),
    ]);

    $result = new HerdSiteStep()->run($context);

    // APP_URL and the herd commands use the folder name, the name Herd secured (RL-16).
    expect($result->message)->toStartWith(HerdSiteStep::SECURED)
        ->and(new EnvFile($context->path('.env'))->get('APP_URL'))->toBe("https://{$name}.test");
    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), "link {$name}") && $process->path === $context->basePath);
    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), "secure {$name}") && $process->path === $context->basePath);
});

it('writes the name it secured into APP_URL, not the request host (A20, RL-16)', function () use ($herdLinksRow): void {
    $context = setupSandbox($this->detection);
    $name = $context->folderName();
    $detection = new Detection(herd: true, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: false, host: 'admin.'.$name.'.test', scheme: 'http');
    $context = new SetupContext($context->basePath, Answers::fromArray(['https' => true], $detection, $name), $detection);

    Process::fake([
        "*herd' 'paths'*" => Process::result(json_encode(['/somewhere/else/'], JSON_THROW_ON_ERROR)),
        "*herd' 'links'*" => Process::sequence()->push(Process::result(''))->whenEmpty(Process::result($herdLinksRow($name, $context->basePath))),
        "*herd' 'link'*" => Process::result('linked'),
        "*herd' 'secured'*" => Process::sequence()->push(Process::result(''))->whenEmpty(Process::result("| Site | Expires |\n| {$name}.test | 2027-01-01 00:00:00 GMT |\n")),
        "*herd' 'secure'*" => Process::result('Secured', exitCode: 0),
        "*herd' 'isolated'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.4.13'),
    ]);

    $result = new HerdSiteStep()->run($context);

    expect($result->message)->toStartWith(HerdSiteStep::SECURED)
        ->and(new EnvFile($context->path('.env'))->get('APP_URL'))->toBe('https://'.$context->folderName().'.test');
});

it('writes a mixed-case folder into APP_URL in lower case, like the detected host (A20, RL-16)', function () use ($herdLinksRow): void {
    $context = setupSandbox($this->detection, 'MyApp-');
    $name = $context->folderName();
    $host = Str::lower($name).'.test';
    $detection = new Detection(herd: true, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: false, host: $host, scheme: 'http');
    $context = new SetupContext($context->basePath, Answers::fromArray(['https' => true], $detection, $name), $detection);

    Process::fake([
        "*herd' 'paths'*" => Process::result(json_encode(['/somewhere/else/'], JSON_THROW_ON_ERROR)),
        "*herd' 'links'*" => Process::sequence()->push(Process::result(''))->whenEmpty(Process::result($herdLinksRow($name, $context->basePath))),
        "*herd' 'link'*" => Process::result('linked'),
        "*herd' 'secured'*" => Process::sequence()->push(Process::result(''))->whenEmpty(Process::result("| Site | Expires |\n| {$host} | 2027-01-01 00:00:00 GMT |\n")),
        "*herd' 'secure'*" => Process::result('Secured', exitCode: 0),
        "*herd' 'isolated'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.4.13'),
    ]);

    $result = new HerdSiteStep()->run($context);

    expect($name)->not->toBe(Str::lower($name))
        ->and($result->message)->toStartWith(HerdSiteStep::SECURED)
        ->and(new EnvFile($context->path('.env'))->get('APP_URL'))->toBe('https://'.$host);
});

it('does not run herd secure for a site that is already secured (§5.3.3 step 10)', function (): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => true], $this->detection, $context->folderName());
    $name = $context->folderName();

    Process::fake([
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'paths'*" => Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)),
        "*herd' 'secured'*" => Process::result("| {$name}.test | 2027-01-01 |\n"),
        "*herd' 'isolated'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.4.13'),
    ]);

    expect(new HerdSiteStep()->run($context)->message)->toStartWith(HerdSiteStep::SECURED);
    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', (array) $process->command), "secure {$name}"));
});

it('verifies herd link by reading herd links back, not by its exit code (§13)', function (int $exitCode, bool $listedAfterwards, bool $served) use ($herdLinksRow): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => false, 'modules' => []], $this->detection, $context->folderName());
    $name = $context->folderName();

    Process::fake([
        "*herd' 'paths'*" => Process::result(json_encode(['/somewhere/else/'], JSON_THROW_ON_ERROR)),
        "*herd' 'links'*" => Process::sequence()->push(Process::result(''))->whenEmpty(Process::result($listedAfterwards ? $herdLinksRow($name, $context->basePath) : '')),
        "*herd' 'link'*" => Process::result('', exitCode: $exitCode),
        "*herd' 'isolated'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.4.13'),
    ]);

    $result = new HerdSiteStep()->run($context);

    expect($result->isManual())->toBe(! $served)
        ->and($result->command)->toBe($served ? null : "herd link {$name}");
})->with([
    'exits 0 but links nothing' => [0, false, false],
    'exits 1 but did link' => [1, true, true],
]);

it('never takes over a name another Herd site already uses (§5.3.3 step 10)', function (bool $linkedElsewhere, bool $parkedElsewhere, bool $parkedHere, string $ssl) use ($herdLinksRow): void {
    $context = setupSandbox($this->detection);
    // skv:install runs in the project: an empty cell resolved with realpath() would be this directory.
    $cwd = (string) getcwd();
    chdir($context->basePath);
    $this->beforeApplicationDestroyed(fn (): bool => chdir($cwd));
    $context->answers = Answers::fromArray(['https' => true], $this->detection, $context->folderName());
    $name = $context->folderName();
    // Another project with the same folder name, inside a second parked directory.
    $otherParked = $this->home.'/Code';
    File::ensureDirectoryExists($otherParked.'/'.$name);

    Process::fake([
        "*herd' 'paths'*" => Process::result(json_encode(array_values(array_filter([
            $parkedElsewhere ? $otherParked.'/' : null,
            $parkedHere ? dirname($context->basePath).'/' : null,
        ])), JSON_THROW_ON_ERROR)),
        "*herd' 'links'*" => Process::result($linkedElsewhere ? $herdLinksRow($name, $otherParked.'/'.$name, $ssl, $ssl === '' ? '' : 'v22.23.2') : ''),
        "*herd' 'link'*" => Process::result('linked'),
        "*herd' 'secured'*" => Process::result("| {$name}.test | 2027-01-01 |\n"),
        "*herd' 'secure'*" => Process::result('Secured'),
        "*herd' 'isolated'*" => Process::result(''),
        "*herd' 'isolate'*" => Process::result('isolated'),
        "*php' '-r'*" => Process::result('8.3.20'),
    ]);

    $result = new HerdSiteStep()->run($context);

    expect($result->isManual())->toBeTrue()
        ->and($result->message)->toContain('another Herd site')
        ->and(new EnvFile($context->path('.env'))->get('APP_URL'))->not->toStartWith('https://')
        ->and(new HerdSiteStep()->isComplete($context))->toBeFalse();
    Process::assertDidntRun(fn ($process): bool => preg_match('/ (link|isolate|secure) /', implode(' ', (array) $process->command).' ') === 1);
})->with([
    'linked under its name to another path' => [true, false, false, 'X'],
    'linked to another path, unsecured and without a node version' => [true, false, false, ''],
    'linked to another path while this project is parked' => [true, false, true, 'X'],
    'another parked directory holds the name' => [false, true, false, 'X'],
]);

it('counts a link as this site only by its Path cell (§5.3.3 step 10)', function () use ($herdLinksRow): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => false, 'modules' => []], $this->detection, $context->folderName());
    $name = $context->folderName();
    $cwd = (string) getcwd();
    chdir($context->basePath);
    $this->beforeApplicationDestroyed(fn (): bool => chdir($cwd));

    Process::fake([
        "*herd' 'paths'*" => Process::result('[]'),
        // A row without a path never matches the project, whatever the other cells hold.
        "*herd' 'links'*" => Process::result($herdLinksRow($name, '', '', '')),
        "*herd' 'isolated'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.4.13'),
    ]);

    expect(new HerdSiteStep()->run($context)->message)->toContain('another Herd site');
    Process::assertDidntRun(fn ($process): bool => preg_match('/ (link|isolate|secure) /', implode(' ', (array) $process->command).' ') === 1);
});

it('treats a parked directory listed with a trailing slash as parked (§5.3.3 step 10)', function (): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => false, 'modules' => []], $this->detection, $context->folderName());

    Process::fake([
        "*herd' 'links'*" => Process::result(''),
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
        "*herd' 'links'*" => Process::result(''),
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
        "*herd' 'links'*" => Process::result(''),
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

it('verifies herd isolate by reading herd isolated back, not by its exit code (§13, FU-isolate)', function (bool $listedAfterwards): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => false, 'modules' => []], $this->detection, $context->folderName());
    $name = $context->folderName();
    $isolated = "| Site | PHP Version |\n| {$name}.test | 8.4 |\n";

    Process::fake([
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'paths'*" => Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)),
        // Not isolated before `herd isolate` ran; listed with 8.4 afterwards only when the isolation took.
        "*herd' 'isolated'*" => Process::sequence()->push(Process::result(''))->whenEmpty(Process::result($listedAfterwards ? $isolated : '')),
        "*herd' 'isolate'*" => Process::result('Isolated', exitCode: 0),
        "*php' '-r'*" => Process::result('8.3.20'),
    ]);

    $result = new HerdSiteStep()->run($context);

    expect($result->status)->toBe($listedAfterwards ? 'done' : 'manual')
        ->and($result->command)->toBe($listedAfterwards ? null : "herd isolate 8.4 --site={$name}");
    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), "isolate 8.4 --site={$name}"));
})->with(['isolated afterwards' => true, 'still not isolated' => false]);

it('rewrites the marker summary with the https address after securing (§5.3.4)', function (): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => true], $this->detection, $context->folderName());
    Marker::write($context, Summary::build($context));

    Process::fake([
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'paths'*" => Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)),
        "*herd' 'secured'*" => Process::result("| {$context->folderName()}.test | 2027-01-01 |\n"),
        "*herd' 'isolated'*" => Process::result(''),
        "*php' '-r'*" => Process::result('8.4.13'),
    ]);

    new HerdSiteStep()->run($context);

    expect(Marker::read($context)['summary']['urls'] ?? [])->toContain("https://{$context->folderName()}.test");
});

it('is complete once served and secured, and not when herd fails (§5.3.1)', function (string $secured, bool $fails, bool $complete): void {
    $context = setupSandbox($this->detection);
    $context->answers = Answers::fromArray(['https' => true], $this->detection, $context->folderName());

    Process::fake([
        "*herd' 'links'*" => Process::result(''),
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
