<?php

declare(strict_types=1);

/** covers §5.3.4 */

use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\StepResult;
use App\Setup\Support\StateFile;
use App\Setup\Support\Toolchain;
use App\Setup\Wizard\HerdState;
use App\Setup\Wizard\WizardState;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('stores the answers, results and notes in a 0600 file without the password (RL-5)', function (): void {
    $context = setupSandbox();
    $answers = Answers::fromArray(['start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com', 'super_admin_password' => 'correct-horse-battery'], Detection::none(), $context->folderName());

    new WizardState('my-app.test', $answers, ['environment' => StepResult::done('ok')], ['a note'])->save($context->basePath);
    $loaded = WizardState::load($context->basePath);

    expect($loaded?->host)->toBe('my-app.test')
        ->and($loaded?->answers)->toEqual($answers)
        ->and($loaded?->results['environment'] ?? null)->toEqual(StepResult::done('ok'))
        ->and($loaded?->notes)->toBe(['a note'])
        ->and(fileperms($context->path(WizardState::PATH)) & 0777)->toBe(0600)
        ->and((string) file_get_contents($context->path(WizardState::PATH)))->not->toContain('correct-horse-battery');
});

it('restores a context with its results and notes, and takes them back (RL-5)', function (): void {
    $context = setupSandbox();
    $state = new WizardState('my-app.test', Answers::defaults(Detection::none(), $context->folderName()), ['git' => StepResult::skipped('x')]);

    $restored = $state->context($context->basePath, Detection::none('my-app.test'));
    $restored->notes[] = 'later';
    $restored->results['claude'] = StepResult::done();
    $state->absorb($restored);

    expect(array_keys($state->results))->toBe(['git', 'claude'])->and($state->notes)->toBe(['later']);
});

it('keeps only the secret\'s hash in the Herd state and expires it after 30 minutes (RL-6)', function (): void {
    $context = setupSandbox();
    [$state, $secret] = HerdState::begin($context->basePath, $context, 'my-app.test');

    expect($secret)->toMatch('/^[0-9a-f]{64}$/')
        ->and((string) file_get_contents($context->path(HerdState::PATH)))->not->toContain($secret)
        ->and(HerdState::load($context->basePath)?->matches($secret))->toBeTrue()
        ->and($state->matches(str_repeat('0', 64)))->toBeFalse();

    $this->travel(31)->minutes();

    expect(HerdState::load($context->basePath)?->isFresh())->toBeFalse();
});

it('never stores the super admin\'s password or its hash in the Herd state (RL-5, RL-6)', function (): void {
    $context = setupSandbox();
    $context->answers = Answers::fromArray(['start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com', 'super_admin_password' => 'correct-horse-battery'], Detection::none(), $context->folderName());
    $hash = (string) $context->answers->superAdminPasswordHash;
    $stored = function () use ($context, $hash): void {
        $file = (string) file_get_contents($context->path(HerdState::PATH));

        expect($file)->not->toContain('correct-horse-battery')
            ->and($file)->not->toContain($hash)
            ->and(StateFile::readJson($context->path(HerdState::PATH))['answers'] ?? [])->toHaveKey('super_admin_password_hash', null)
            ->and(HerdState::load($context->basePath)?->answers->superAdminPasswordHash)->toBeNull();
    };

    [$state] = HerdState::begin($context->basePath, $context, 'my-app.test');
    $stored();
    expect($state->answers->superAdminPasswordHash)->toBeNull();

    $state->absorb($context);
    $state->save($context->basePath);
    $stored();
});
