<?php

declare(strict_types=1);

/** covers §4, §12 */

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/*
| scripts/traceability.php (§12 (3), CD-17) against the real spec and tests, and against small projects written to
| storage/framework/testing: a spec with a §4 section and a tests/ folder with one file per test.
*/

/** A spec whose §4 has two subsections, three bullets, and a §5 bullet the script must ignore. */
$spec = <<<'MD'
    # Spec

    ## 4. Feature inventory

    Every item is covered by a test.

    ### 4.1 Hosts
    - §4.1.1 The host decides the organisation.
    - §4.1.2 Unknown hosts answer 404.

    ### 4.2 Login
    - §4.2.1 Login is scoped per host.

    ## 5. Setup
    - Not a §4 bullet.
    MD;

/** The PHP of a test file whose first docblock is $docblock. */
$testFile = fn (string $docblock): string => "<?php\n\ndeclare(strict_types=1);\n\n{$docblock}\n\nit('works', function (): void {\n    expect(true)->toBeTrue();\n});\n";

/**
 * Writes $spec and the test files ($name => contents, under tests/) to a fresh project folder and runs the script on
 * them.
 *
 * @param  array<string, string>  $tests
 */
$run = function (string $spec, array $tests): ProcessResult {
    $directory = storage_path('framework/testing/traceability-'.Str::uuid());
    File::ensureDirectoryExists($directory.'/tests/Feature');
    File::put($directory.'/spec.md', $spec);

    foreach ($tests as $name => $contents) {
        File::put($directory.'/tests/Feature/'.$name, $contents);
    }

    try {
        return Process::path(base_path())->run([PHP_BINARY, 'scripts/traceability.php', '--spec='.$directory.'/spec.md', '--tests='.$directory.'/tests']);
    } finally {
        File::deleteDirectory($directory);
    }
};

it('finds every §4 bullet of the design spec cited by a test (§12 (3), CD-17)', function (): void {
    $result = Process::path(base_path())->run([PHP_BINARY, 'scripts/traceability.php']);

    expect($result->output())->toMatch('/^§4 traceability: (\d+) bullets, \1 cited by a test, 0 uncovered, 0 problems\.$/m')
        ->and($result->output())->not->toContain('Uncovered:')
        ->and($result->exitCode())->toBe(0);
});

it('passes when every bullet is cited, in one-line and multi-line covers docblocks (CD-17)', function () use ($run, $spec, $testFile): void {
    $result = $run($spec, [
        'HostsTest.php' => $testFile('/** covers §4.1.1, §4.1.2, §6.2 */'),
        'LoginTest.php' => $testFile("/**\n * The login rules.\n *\n * covers §4.2.1\n */"),
    ]);

    expect($result->output())->toContain('§4 traceability: 3 bullets, 3 cited by a test, 0 uncovered, 0 problems.')
        ->and($result->exitCode())->toBe(0);
});

it('lists a bullet no test cites and fails (CD-17)', function () use ($run, $spec, $testFile): void {
    $result = $run($spec, [
        'HostsTest.php' => $testFile('/** covers §4.1.1 */'),
        'LoginTest.php' => $testFile('/** covers §4.2.1 */'),
    ]);

    expect($result->output())->toContain('Uncovered: §4.1.2 Unknown hosts answer 404.')
        ->and($result->output())->toContain('3 bullets, 2 cited by a test, 1 uncovered, 0 problems.')
        ->and($result->exitCode())->toBe(1);
});

it('counts neither a subsection id, nor an id outside the first docblock, nor a file that is no test (CD-17)', function () use ($run, $spec, $testFile): void {
    $result = $run($spec, [
        'HostsTest.php' => $testFile('/** covers §4.1, §4.2.1 */'),
        'LoginTest.php' => $testFile("/** covers §6.2 */\n\n/** covers §4.1.1 */"),
        'Helpers.php' => $testFile('/** covers §4.1.2 */'),
    ]);

    expect($result->output())->toContain('Uncovered: §4.1.1 ')
        ->and($result->output())->toContain('Uncovered: §4.1.2 ')
        ->and($result->exitCode())->toBe(1);
});

it('reports a covers line that cites an id §4 does not have (CD-17)', function () use ($run, $spec, $testFile): void {
    $result = $run($spec, [
        'HostsTest.php' => $testFile('/** covers §4.1.1, §4.1.2, §4.2.1, §4.1.9 */'),
    ]);

    expect($result->output())->toContain('Problem: Feature/HostsTest.php: covers §4.1.9, which §4 does not have')
        ->and($result->output())->toContain('0 uncovered, 1 problems.')
        ->and($result->exitCode())->toBe(1);
});

it('reports a §4 bullet without an id, with another subsection\'s id or with an id used twice (CD-17)', function (string $bullet, string $problem) use ($run, $spec, $testFile): void {
    $result = $run(str_replace('- §4.2.1 Login is scoped per host.', $bullet, $spec), [
        'AllTest.php' => $testFile('/** covers §4.1.1, §4.1.2, §4.2.1 */'),
    ]);

    expect($result->output())->toContain('Problem: spec.md:'.$problem)
        ->and($result->exitCode())->toBe(1);
})->with([
    'no id' => ['- Login is scoped per host.', '12: a §4 bullet without the id of its subsection (§4.2.n)'],
    'another subsection' => ['- §4.1.3 Login is scoped per host.', '12: a §4 bullet without the id of its subsection (§4.2.n)'],
    'used twice' => ["- §4.2.1 Login is scoped per host.\n- §4.2.1 Logout ends the session.", '13: §4.2.1 is used twice'],
]);
