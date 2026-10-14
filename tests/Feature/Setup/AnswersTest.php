<?php

declare(strict_types=1);

/** covers §5.3.2, §7.2 */

use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Support\Toolchain;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('covers every --answers key in the e2e fixture (§7.2)', function (): void {
    /** @var array<string, mixed> $fixture */
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/e2e-answers.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($fixture))->toEqualCanonicalizing(Answers::KEYS);
    Answers::fromArray($fixture, Detection::none(), 'tmp');
});

it('defaults to the detected environment (§5.3.2)', function (): void {
    $withPostgres = new Detection(herd: true, herdPro: true, herdMail: true, herdSmtpPort: 2525, postgres: true, fluxPro: false, claude: true, host: 'my-app.test', scheme: 'http');

    $answers = Answers::defaults($withPostgres, 'my-app');

    expect($answers->appName)->toBe('My App')
        ->and($answers->database)->toBe('pgsql')
        ->and($answers->startWith)->toBe('demo')
        ->and($answers->modules)->toEqualCanonicalizing(['passkeys', 'backups', 'impersonation'])
        ->and($answers->https)->toBeTrue()
        ->and($answers->playwright)->toBeFalse()
        ->and($answers->claude)->toBeTrue();

    expect(Answers::defaults(Detection::none(), 'my-app'))
        ->database->toBe('sqlite')
        ->claude->toBeFalse()
        ->modules->toEqualCanonicalizing(['passkeys', 'impersonation']);
});

it('drops backups on SQLite and forces HTTPS while passkeys are on (§5.3.2)', function (): void {
    $answers = Answers::fromArray(['database' => 'sqlite', 'modules' => ['backups', 'passkeys'], 'https' => false], Detection::none(), 'tmp');

    expect($answers->modules)->toBe(['passkeys'])->and($answers->https)->toBeTrue();
});

it('requires the super admin credentials when starting without demo data (§5.3.2)', function (): void {
    Answers::fromArray(['start_with' => 'super_admin'], Detection::none(), 'tmp');
})->throws(ValidationException::class);

it('keeps only a hash of the super admin password (RL-5)', function (): void {
    $answers = Answers::fromArray([
        'start_with' => 'super_admin',
        'super_admin_email' => 'owner@example.com',
        'super_admin_password' => 'correct-horse-battery',
    ], Detection::none(), 'tmp');

    expect($answers->superAdminPasswordHash)->not->toBeNull()
        ->and(password_verify('correct-horse-battery', (string) $answers->superAdminPasswordHash))->toBeTrue()
        ->and(json_encode($answers->toPublicArray(), JSON_THROW_ON_ERROR))->not->toContain('correct-horse-battery')
        ->and($answers->toPublicArray())->not->toHaveKey('super_admin_password_hash');
});

it('rejects unknown keys and values (§5.3.2)', function (array $input): void {
    Answers::fromArray($input, Detection::none(), 'tmp');
})->throws(ValidationException::class)->with([
    'unknown key' => [['colour' => 'blue']],
    'unknown database' => [['database' => 'mysql']],
    'unknown module' => [['modules' => ['billing']]],
    'unknown start' => [['start_with' => 'empty']],
]);

it('accepts an app name with an apostrophe', function (): void {
    expect(Answers::fromArray(['app_name' => 'Bob\'s Shop'], Detection::none(), 'tmp')->appName)->toBe('Bob\'s Shop');
});

it('rejects an app name the environment file cannot hold literally', function (string $name): void {
    Answers::fromArray(['app_name' => $name], Detection::none(), 'tmp');
})->throws(ValidationException::class)->with([
    'a double quote' => ['Bob\'s "Shop"'],
    'a variable reference' => ['${APP_KEY}'],
    'a backslash' => ['C:\\Shop'],
    'a newline' => ["Shop\nINJECTED=true"],
    'a tab' => ["Shop\tName"],
]);

it('takes every answer it is not given from the earlier install, not from the defaults (§5.3.2, DEC-30)', function (): void {
    $withPostgres = new Detection(herd: true, herdPro: true, herdMail: true, herdSmtpPort: 2525, postgres: true, fluxPro: false, claude: true, host: 'my-app.test', scheme: 'http');
    $installed = ['app_name' => 'Owner App', 'database' => 'sqlite', 'start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com', 'modules' => [], 'https' => false, 'playwright' => false, 'claude' => false];

    $answers = Answers::fromArray(['app_name' => 'Renamed'], $withPostgres, 'my-app', $installed);

    expect($answers->appName)->toBe('Renamed')
        ->and($answers->database)->toBe('sqlite')
        ->and($answers->startWith)->toBe('super_admin')
        ->and($answers->superAdminEmail)->toBe('owner@example.com')
        ->and($answers->superAdminPasswordHash)->toBeNull()
        ->and($answers->modules)->toBe([])
        ->and($answers->https)->toBeFalse()
        ->and($answers->claude)->toBeFalse();
});

it('still requires a password for a super admin the earlier install did not create (§5.3.2)', function (): void {
    $installed = ['start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com'];

    Answers::fromArray(['super_admin_email' => 'someone-else@example.com'], Detection::none(), 'tmp', $installed);
})->throws(ValidationException::class);

it('round-trips its state with the password hash, never the password (A11)', function (): void {
    $answers = Answers::fromArray(
        ['start_with' => 'super_admin', 'super_admin_email' => 'Owner@Example.com', 'super_admin_password' => 'correct-horse-battery', 'modules' => ['impersonation']],
        Detection::none(),
        'my-app',
    );

    $state = $answers->toState();
    $restored = Answers::fromState(json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));

    expect($state)->not->toHaveKey('super_admin_password')
        ->and(json_encode($state, JSON_THROW_ON_ERROR))->not->toContain('correct-horse-battery')
        ->and(Hash::check('correct-horse-battery', (string) $state['super_admin_password_hash']))->toBeTrue()
        ->and($restored)->toEqual($answers);
});

it('refuses a state with a missing or wrongly typed key (A11)', function (string $key, mixed $value): void {
    $state = Answers::defaults(Detection::none(), 'my-app')->toState();
    $state[$key] = $value;

    expect(fn (): Answers => Answers::fromState($state))->toThrow(InvalidArgumentException::class);
})->with([
    'database' => ['database', 'mysql'],
    'modules' => ['modules', 'passkeys'],
    'https' => ['https', 'yes'],
    'start_with' => ['start_with', null],
]);
