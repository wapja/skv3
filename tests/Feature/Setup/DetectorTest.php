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

it('detects nothing on a machine without Herd', function (): void {
    $detection = new Detector(new Toolchain($this->home))->detect('localhost');

    expect($detection->herd)->toBeFalse()
        ->and($detection->herdPro)->toBeFalse()
        ->and($detection->herdMail)->toBeFalse()
        ->and($detection->host)->toBe('localhost');
});

it('reads Herd Pro from the services JSON and the mail service from herd.json, never from exit codes (§5.3.2, §13)', function (): void {
    $herd = $this->home.'/Library/Application Support/Herd/bin/herd';
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    File::put($this->home.'/Library/Application Support/Herd/config/herd.json', json_encode(['mails' => true, 'smtpPort' => 2525], JSON_THROW_ON_ERROR));

    Process::fake([
        "*'services:list' '--json'*" => Process::result(output: '[{"type":"postgresql","status":"active"}]', exitCode: 1),
        "*'tld'*" => Process::result(output: "test\n"),
    ]);

    $detection = new Detector(new Toolchain($this->home))->detect();

    expect($detection->herd)->toBeTrue()
        ->and($detection->herdPro)->toBeTrue()
        ->and($detection->herdMail)->toBeTrue()
        ->and($detection->herdSmtpPort)->toBe(2525)
        ->and($detection->host)->toBe(basename(base_path()).'.test');
});

it('treats unparsable Herd output as "no Herd Pro" (§13)', function (): void {
    $herd = $this->home.'/Library/Application Support/Herd/bin/herd';
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);

    Process::fake([
        "*'services:list' '--json'*" => Process::result(output: 'Herd Pro is required', exitCode: 0),
        "*'tld'*" => Process::result(output: "test\n"),
    ]);

    expect(new Detector(new Toolchain($this->home))->detect()->herdPro)->toBeFalse();
});

it('reports Postgres unreachable when nothing listens (§5.3.2)', function (): void {
    expect(new Detector(new Toolchain($this->home))->postgresReachable('127.0.0.1', 1))->toBeFalse();
});
