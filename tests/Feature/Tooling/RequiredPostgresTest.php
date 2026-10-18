<?php

declare(strict_types=1);

/** covers §7.2, §8 */

use Illuminate\Support\Facades\File;
use Tests\Fixtures\RequiredPostgres;

/** Runs $test with REQUIRE_POSTGRES set to $value (null: unset), then puts the runner's own value back. */
$withFlag = function (?string $value, Closure $test): void {
    $original = getenv(RequiredPostgres::FLAG);
    putenv($value === null ? RequiredPostgres::FLAG : RequiredPostgres::FLAG.'='.$value);

    try {
        $test();
    } finally {
        putenv($original === false ? RequiredPostgres::FLAG : RequiredPostgres::FLAG.'='.$original);
    }
};

it('skips a PostgreSQL test only while REQUIRE_POSTGRES is unset (CD-10)', function () use ($withFlag): void {
    $withFlag(null, function (): void {
        expect(RequiredPostgres::skips(fn (): bool => false))->toBeTrue()
            ->and(RequiredPostgres::skips(fn (): bool => true))->toBeFalse();
    });

    $withFlag('true', function (): void {
        $probed = false;

        expect(RequiredPostgres::skips(function () use (&$probed): bool {
            $probed = true;

            return false;
        }))->toBeFalse()
            // A required run never probes: it reaches the test body, which fails on the missing server or client.
            ->and($probed)->toBeFalse();
    });

    $withFlag('false', function (): void {
        expect(RequiredPostgres::skips(fn (): bool => false))->toBeTrue();
    });
});

it('sends every skip on a missing PostgreSQL server or pg_dump through RequiredPostgres (CD-10, F16, F17)', function (): void {
    $skips = collect(File::allFiles(base_path('tests')))
        // This file names the pattern itself.
        ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), 'Test.php') && $file->getFilename() !== 'RequiredPostgresTest.php')
        ->flatMap(fn (SplFileInfo $file): array => collect(preg_split('/\R/', (string) file_get_contents($file->getPathname())) ?: [])
            ->filter(fn (string $line): bool => str_contains($line, '->skip(') && preg_match('/postgresReachable|PgDump/', $line) === 1)
            ->map(fn (string $line): string => $file->getFilename().': '.trim($line))
            ->all());

    expect($skips->reject(fn (string $line): bool => str_contains($line, 'RequiredPostgres::skips('))->values()->all())->toBe([])
        ->and($skips->map(fn (string $line): string => strstr($line, ':', true) ?: $line)->all())
        ->toContain('BackupRunTest.php', 'SetupWizardTest.php', 'WizardPageTest.php');
});
