<?php

declare(strict_types=1);

/** covers §5.3.3 */

use App\Setup\Answers;
use App\Setup\Marker;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('records the answers without secrets and the summary in the marker (§5.3.3 step 9, RL-14)', function (): void {
    $context = setupSandbox();
    // The only answers that carry a secret: a super admin with a password, hashed on entry (RL-5).
    $context->answers = Answers::fromArray([
        'start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com', 'super_admin_password' => 'correct-horse-battery',
    ], $context->detection, $context->folderName());

    expect(Marker::exists($context))->toBeFalse();

    Marker::write($context, ['urls' => ['http://localhost']]);

    $marker = Marker::read($context);
    $raw = (string) file_get_contents($context->path(Marker::FILE));

    expect(Marker::exists($context))->toBeTrue()
        ->and($marker)->not->toBeNull()
        ->and($marker['start_with'] ?? null)->toBe('super_admin')
        ->and($marker['answers']['super_admin_email'] ?? null)->toBe('owner@example.com')
        ->and($marker['answers'] ?? [])->not->toHaveKey('super_admin_password')
        ->and($marker['summary'] ?? null)->toBe(['urls' => ['http://localhost']])
        ->and($raw)->not->toContain('correct-horse-battery')->not->toContain('$2y$')->not->toContain('password_hash');
});

it('keeps the first install time when it rewrites the marker (RL-14)', function (): void {
    $context = setupSandbox();
    $this->travelTo('2026-10-01 09:00:00');
    Marker::write($context, []);
    $this->travelTo('2026-10-12 17:30:00');

    Marker::write($context, ['notes' => ['re-run']]);

    expect(Marker::read($context)['installed_at'] ?? null)->toBe(Date::parse('2026-10-01 09:00:00')->toIso8601String())
        ->and(Marker::read($context)['summary'] ?? null)->toBe(['notes' => ['re-run']]);
});
