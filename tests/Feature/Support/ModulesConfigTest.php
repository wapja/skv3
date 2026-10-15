<?php

declare(strict_types=1);

/** covers §6.8 */

use App\Support\Skv;

it('ships every module flag and the health-check key in .env.example (§5.2)', function (): void {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)
        ->toContain("\nMODULE_PASSKEYS=true\n")
        ->toContain("\nMODULE_BACKUPS=true\n")
        ->toContain("\nMODULE_IMPERSONATION=true\n")
        ->toContain("\nHEALTH_CHECK_KEY=\n")
        ->toContain("\nOPS_CACHE_STORE=file\n");
});

it('keeps the backups module off on SQLite whatever MODULE_BACKUPS says (§6.8)', function (string $connection, bool $flag, bool $expected): void {
    expect(Skv::backupsAvailable($connection, $flag))->toBe($expected);
})->with([
    'sqlite, flag on' => ['sqlite', true, false],
    'pgsql, flag on' => ['pgsql', true, true],
    'pgsql, flag off' => ['pgsql', false, false],
]);

it('reads the backups flag through that rule', function (): void {
    // base_path('config/skv.php'): the allowed config/skv.php form; NameLeakTest scans tests/ too.
    expect((string) file_get_contents(base_path('config/skv.php')))->toContain('Skv::backupsAvailable(');
});
