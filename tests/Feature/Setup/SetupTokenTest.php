<?php

declare(strict_types=1);

/** covers §5.2, §5.3.4 */

use App\Setup\Marker;
use App\Setup\SetupToken;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('creates a 64-character token once, mode 0600, while setup has not finished (§5.2 point 5)', function (): void {
    $context = setupSandbox();
    $token = new SetupToken($context->basePath);

    expect($token->ensure())->toBeTrue()
        ->and($token->value())->toMatch('/^[0-9a-f]{64}$/')
        ->and(fileperms($token->path()) & 0777)->toBe(0600);

    $first = $token->value();

    expect($token->ensure())->toBeFalse()->and($token->value())->toBe($first);
});

it('writes no token once the marker exists (§5.3.4)', function (): void {
    $context = setupSandbox();
    Marker::write($context, []);

    expect(new SetupToken($context->basePath)->ensure())->toBeFalse()
        ->and(new SetupToken($context->basePath)->exists())->toBeFalse();
});

it('matches only the exact token (§5.3.4)', function (): void {
    $context = setupSandbox();
    $token = new SetupToken($context->basePath);
    $token->ensure();

    expect($token->matches((string) $token->value()))->toBeTrue()
        ->and($token->matches(strtoupper((string) $token->value())))->toBeFalse()
        ->and($token->matches(''))->toBeFalse()
        ->and($token->hash())->toBe(hash('sha256', (string) $token->value()));
});
