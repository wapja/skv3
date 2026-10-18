<?php

declare(strict_types=1);

/*
 * Spec §4 traceability (§12 (3), CD-17): every bullet of §4 carries a frozen id `§4.x.n`, and every bullet is cited by
 * at least one test file's `covers` docblock line. Lists the bullets no test cites, the ids a covers line cites that
 * §4 does not have, and the §4 bullets without a valid id; exits 1 when there are any, 0 otherwise.
 *
 * Usage: php scripts/traceability.php [--spec=<file>] [--tests=<directory>]
 * Defaults: the design spec under docs/superpowers/specs and the tests/ folder. Relative paths are read from the
 * project root. A plain `§4.x` in a covers line names a subsection and cites none of its bullets.
 */

$root = dirname(__DIR__);
$options = getopt('', ['spec:', 'tests:']);
$resolve = fn (string $path): string => str_starts_with($path, '/') ? $path : $root.'/'.$path;
$specPath = $resolve(is_string($options['spec'] ?? null) ? $options['spec'] : 'docs/superpowers/specs/2026-10-10-skv3-design.md');
$testsPath = $resolve(is_string($options['tests'] ?? null) ? $options['tests'] : 'tests');

if (! is_file($specPath) || ! is_dir($testsPath)) {
    fwrite(STDERR, "Cannot read the spec [{$specPath}] or the tests folder [{$testsPath}].\n");

    exit(1);
}

/*
 * The §4 bullets: the top-level `- ` lines from `## 4.` up to the next `## ` heading, keyed by id. A bullet's id is
 * the `§4.x.n` that starts it, and x must be its `### 4.x` subsection.
 */
$bullets = [];
$problems = [];
$inSection = false;
$subsection = null;

foreach (preg_split('/\R/', (string) file_get_contents($specPath)) ?: [] as $number => $line) {
    if (preg_match('/^## /', $line) === 1) {
        $inSection = preg_match('/^## 4\. /', $line) === 1;

        continue;
    }

    if (! $inSection) {
        continue;
    }

    if (preg_match('/^### 4\.(\d+) /', $line, $heading) === 1) {
        $subsection = $heading[1];

        continue;
    }

    if (! str_starts_with($line, '- ')) {
        continue;
    }

    $where = basename($specPath).':'.($number + 1);

    if (preg_match('/^- §4\.(\d+)\.(\d+) (.+)$/u', $line, $bullet) !== 1 || $bullet[1] !== $subsection) {
        $problems[] = "{$where}: a §4 bullet without the id of its subsection (§4.{$subsection}.n)";

        continue;
    }

    $id = "§4.{$bullet[1]}.{$bullet[2]}";

    if (isset($bullets[$id])) {
        $problems[] = "{$where}: {$id} is used twice";

        continue;
    }

    $bullets[$id] = $bullet[3];
}

/*
 * The ids each test file cites: the `covers` lines of its first docblock.
 */
$cited = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsPath, FilesystemIterator::SKIP_DOTS));

foreach ($files as $file) {
    if (! $file instanceof SplFileInfo || ! str_ends_with($file->getFilename(), 'Test.php')) {
        continue;
    }

    if (preg_match('#/\*\*(.*?)\*/#s', (string) file_get_contents($file->getPathname()), $docblock) !== 1) {
        continue;
    }

    $relative = substr($file->getPathname(), strlen(rtrim($testsPath, '/')) + 1);

    foreach (preg_split('/\R/', $docblock[1]) ?: [] as $line) {
        if (preg_match('/^\s*\*?\s*covers\s+(.+)$/u', $line, $covers) !== 1) {
            continue;
        }

        preg_match_all('/§4\.\d+\.\d+/u', $covers[1], $ids);

        foreach ($ids[0] as $id) {
            if (! isset($bullets[$id])) {
                $problems[] = "{$relative}: covers {$id}, which §4 does not have";

                continue;
            }

            $cited[$id][] = $relative;
        }
    }
}

$uncovered = array_diff_key($bullets, $cited);

foreach ($problems as $problem) {
    fwrite(STDOUT, "Problem: {$problem}\n");
}

foreach ($uncovered as $id => $text) {
    fwrite(STDOUT, "Uncovered: {$id} ".mb_strimwidth($text, 0, 100, '…')."\n");
}

fwrite(STDOUT, sprintf("§4 traceability: %d bullets, %d cited by a test, %d uncovered, %d problems.\n", count($bullets), count($bullets) - count($uncovered), count($uncovered), count($problems)));

exit($uncovered === [] && $problems === [] && $bullets !== [] ? 0 : 1);
