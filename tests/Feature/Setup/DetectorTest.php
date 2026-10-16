<?php

declare(strict_types=1);

/** covers §5.3.2 */

use App\Setup\Detector;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $this->home = storage_path('framework/testing/setup/home-'.Str::uuid());
    app()->instance(Toolchain::class, new Toolchain($this->home));
    File::ensureDirectoryExists($this->home.'/Library/Application Support/Herd/config');
    $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->home));
});

/** A Detector whose PostgreSQL probe answers "unreachable" without opening a connection (RL-3, g1-13). */
$detector = fn (Toolchain $toolchain): Detector => new Detector($toolchain, postgresProbe: fn (): bool => false);

it('detects nothing on a machine without Herd', function () use ($detector): void {
    $detection = $detector(new Toolchain($this->home))->detect('localhost');

    expect($detection->herd)->toBeFalse()
        ->and($detection->herdPro)->toBeFalse()
        ->and($detection->herdMail)->toBeFalse()
        ->and($detection->host)->toBe('localhost');
});

it('reads Herd Pro from the services JSON and the mail service from herd.json, never from exit codes (§5.3.2, §13)', function () use ($detector): void {
    $herd = $this->home.'/Library/Application Support/Herd/bin/herd';
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    File::put($this->home.'/Library/Application Support/Herd/config/herd.json', json_encode(['mails' => true, 'smtpPort' => 2525], JSON_THROW_ON_ERROR));

    Process::fake([
        "*'services:list' '--json'*" => Process::result(output: '[{"type":"postgresql","status":"active"}]', exitCode: 1),
        "*'tld'*" => Process::result(output: "test\n"),
    ]);

    $detection = $detector(new Toolchain($this->home))->detect();

    expect($detection->herd)->toBeTrue()
        ->and($detection->herdPro)->toBeTrue()
        ->and($detection->herdMail)->toBeTrue()
        ->and($detection->herdSmtpPort)->toBe(2525)
        ->and($detection->host)->toBe(basename(base_path()).'.test');
});

it('treats unparsable Herd output as "no Herd Pro" (§13)', function () use ($detector): void {
    $herd = $this->home.'/Library/Application Support/Herd/bin/herd';
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);

    Process::fake([
        "*'services:list' '--json'*" => Process::result(output: 'Herd Pro is required', exitCode: 0),
        "*'tld'*" => Process::result(output: "test\n"),
    ]);

    expect($detector(new Toolchain($this->home))->detect()->herdPro)->toBeFalse();
});

it('reports Postgres unreachable when nothing listens (§5.3.2)', function (): void {
    // The real PDO probe, on port 1: nothing listens there, so it never reaches the developer's server.
    expect(new Detector(new Toolchain($this->home))->postgresReachable('127.0.0.1', 1))->toBeFalse();
});

it('asks the given probe instead of opening a connection (RL-3, g1-13)', function (): void {
    $asked = [];
    $probed = new Detector(new Toolchain($this->home), postgresProbe: function (string $host, int $port) use (&$asked): bool {
        $asked[] = $host.':'.$port;

        return true;
    });

    expect($probed->detect('localhost')->postgres)->toBeTrue()
        ->and($probed->postgresReachable('10.0.0.1', 6543))->toBeTrue()
        ->and($asked)->toBe(['127.0.0.1:5432', '10.0.0.1:6543']);
});

it('pins the wizard\'s PostgreSQL probe, so no setup test reaches the machine\'s server (RL-3, g4-08)', function (): void {
    useSetupWizard();

    $unreachable = app(Detector::class)->detect('localhost')->postgres;
    withoutPostgresProbe(true);

    expect($unreachable)->toBeFalse()
        ->and(app(Detector::class)->detect('localhost')->postgres)->toBeTrue();
});

it('treats a Herd that hangs as no Herd Pro and falls back to the APP_URL host (A5, A18)', function () use ($detector): void {
    $herd = $this->home.'/Library/Application Support/Herd/bin/herd';
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    config(['app.url' => 'http://fallback.test']);

    // A handler that throws stands in for a timeout: Detector must catch every process failure.
    Process::fake(['*' => fn () => throw new RuntimeException('timed out')]);

    $detection = $detector(new Toolchain($this->home))->detect();

    expect($detection->herd)->toBeTrue()
        ->and($detection->herdPro)->toBeFalse()
        ->and($detection->host)->toBe('fallback.test');
});

it('uses a valid request host and ignores an invalid one (A4)', function () use ($detector): void {
    config(['app.url' => 'http://fallback.test']);

    expect($detector(new Toolchain($this->home))->detect('My-App.test:80')->host)->toBe('my-app.test')
        ->and($detector(new Toolchain($this->home))->detect('evil.com@x')->host)->toBe('fallback.test');
});

it('keeps the request\'s port without Herd, never the scheme\'s default port (CD-15, g1-17)', function (string $scheme, ?int $requestPort, ?int $port): void {
    $detection = new Detector(new Toolchain($this->home), postgresProbe: fn (): bool => false)->detect('localhost', $scheme, $requestPort);

    expect($detection->port)->toBe($port);
})->with([
    'a port' => ['http', 8000, 8000],
    'http on 80' => ['http', 80, null],
    'https on 443' => ['https', 443, null],
    'no request' => ['http', null, null],
]);

it('keeps no port under Herd, which serves every site on the default port (CD-15)', function (): void {
    $herd = $this->home.'/Library/Application Support/Herd/bin/herd';
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    Process::fake(["*'services:list' '--json'*" => Process::result('')]);

    expect(new Detector(new Toolchain($this->home), postgresProbe: fn (): bool => false)->detect('my-app.test', 'http', 8000)->port)->toBeNull();
});
