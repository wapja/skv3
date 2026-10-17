<?php

declare(strict_types=1);

/** covers §5.3.4 */

use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\SetupContext;
use App\Setup\StepResult;
use App\Setup\Support\StateFile;
use App\Setup\Support\Toolchain;
use App\Setup\Wizard\HerdState;
use App\Setup\Wizard\WizardRunner;
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

    new WizardState('my-app.test', $answers, Detection::none('my-app.test'), ['environment' => StepResult::done('ok')], ['a note'])->save($context->basePath);
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
    $state = new WizardState('my-app.test', Answers::defaults(Detection::none(), $context->folderName()), Detection::none('my-app.test'), ['git' => StepResult::skipped('x')]);

    $restored = $state->context($context->basePath);
    $restored->notes[] = 'later';
    $restored->results['claude'] = StepResult::done();
    $state->absorb($restored);

    expect(array_keys($state->results))->toBe(['git', 'claude'])->and($state->notes)->toBe(['later'])
        ->and($restored->detection)->toEqual(Detection::none('my-app.test'));
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

it('keeps the detection snapshot of the start in the wizard state and the Herd state (CD-15)', function (): void {
    $context = setupSandbox();
    $detection = new Detection(herd: false, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: true, fluxPro: false, claude: true, host: 'localhost', scheme: 'http', port: 8000);
    $herdContext = new SetupContext($context->basePath, $context->answers, $detection);

    new WizardState('localhost', $context->answers, $detection)->save($context->basePath);
    HerdState::begin($context->basePath, $herdContext, 'localhost');

    expect(WizardState::load($context->basePath)?->detection)->toEqual($detection)
        ->and(WizardState::load($context->basePath)?->context($context->basePath)->detection)->toEqual($detection)
        ->and(HerdState::load($context->basePath)?->detection)->toEqual($detection)
        ->and(Detection::fromArray($detection->toArray()))->toEqual($detection);
});

/** A snapshot as Detection::toArray() writes it. */
$snapshot = ['herd' => false, 'herd_pro' => false, 'herd_mail' => false, 'herd_smtp_port' => null, 'postgres' => false, 'flux_pro' => false, 'claude' => false, 'host' => 'localhost', 'scheme' => 'http', 'port' => null];

it('reads back the snapshot it writes (CD-15)', function () use ($snapshot): void {
    expect(Detection::none()->toArray())->toBe($snapshot)
        ->and(Detection::fromArray($snapshot))->toEqual(Detection::none());
});

it('refuses a detection snapshot of the wrong shape or with a value setup never writes (CD-15, L7b-30, L7b-35)', function (array $broken): void {
    expect(fn (): Detection => Detection::fromArray($broken))->toThrow(InvalidArgumentException::class);
})->with([
    'a missing key' => [array_diff_key($snapshot, ['herd' => true])],
    'a string for a boolean' => [[...$snapshot, 'postgres' => 'yes']],
    'a string port' => [[...$snapshot, 'herd_smtp_port' => '2525']],
    'no host' => [[...$snapshot, 'host' => null]],
    'a string request port' => [[...$snapshot, 'port' => '8000']],
    'no SMTP port key (L7b-30)' => [array_diff_key($snapshot, ['herd_smtp_port' => true])],
    'no request port key (L7b-30)' => [array_diff_key($snapshot, ['port' => true])],
    'a host with a path (L7b-35)' => [[...$snapshot, 'host' => 'evil.test/x']],
    'a host with a port (L7b-35)' => [[...$snapshot, 'host' => 'localhost:8000']],
    'an upper-case host (L7b-35)' => [[...$snapshot, 'host' => 'MyApp.test']],
    'a javascript scheme (L7b-35)' => [[...$snapshot, 'scheme' => 'javascript']],
    'request port 0 (L7b-35)' => [[...$snapshot, 'port' => 0]],
    'request port 65536 (L7b-35)' => [[...$snapshot, 'port' => 65536]],
    'SMTP port 0 (L7b-35)' => [[...$snapshot, 'herd_smtp_port' => 0]],
]);

it('reads back a snapshot on https with the edge ports (L7b-35)', function () use ($snapshot): void {
    $detection = Detection::fromArray([...$snapshot, 'herd_smtp_port' => 1, 'host' => 'admin.my-app.test', 'scheme' => 'https', 'port' => 65535]);

    expect($detection->herdSmtpPort)->toBe(1)
        ->and($detection->host)->toBe('admin.my-app.test')
        ->and($detection->scheme)->toBe('https')
        ->and($detection->port)->toBe(65535);
});

it('refuses a state file written before the detection snapshot, and starts over (CD-15)', function (): void {
    $context = setupSandbox();
    app()->instance(SetupContext::class, $context);
    $state = ['host' => 'localhost', 'answers' => $context->answers->toState(), 'results' => [], 'notes' => []];
    StateFile::writeJson($context->path(WizardState::PATH), $state);
    StateFile::writeJson($context->path(HerdState::PATH), [...$state, 'secret_hash' => str_repeat('0', 64), 'started_at' => time(), 'result' => null]);

    expect(fn (): ?WizardState => WizardState::load($context->basePath))->toThrow(InvalidArgumentException::class)
        ->and(fn (): ?HerdState => HerdState::load($context->basePath))->toThrow(InvalidArgumentException::class)
        ->and(app(WizardRunner::class)->state())->toBeNull()
        ->and($context->path(WizardState::PATH))->not->toBeFile();
});
