<?php

declare(strict_types=1);

namespace App\Setup\Steps;

use App\Setup\SetupContext;
use App\Setup\SetupStep;
use App\Setup\StepResult;

/**
 * §5.3.3 step 3: herd.yml with the site name, PHP 8.4, the HTTPS answer and, on pgsql, Herd's PostgreSQL 18 service.
 * The step owns those four top-level keys; every other top-level key (aliases, a Forge link) stays as it was.
 */
final class HerdManifestStep implements SetupStep
{
    /** The PostgreSQL version exactly as `herd services:list --json` lists it (Herd 1.30.2). */
    public const string POSTGRES_VERSION = '18';

    /** The top-level keys this step writes. */
    private const array OWN_KEYS = ['name', 'php', 'secured', 'services'];

    public function key(): string
    {
        return 'herd-manifest';
    }

    public function label(): string
    {
        return __('herd.yml');
    }

    public function isComplete(SetupContext $context): bool
    {
        $path = $context->path('herd.yml');

        return is_file($path) && file_get_contents($path) === $this->manifest($context);
    }

    public function isLong(): bool
    {
        return false;
    }

    public function run(SetupContext $context): StepResult
    {
        file_put_contents($context->path('herd.yml'), $this->manifest($context));

        return StepResult::done();
    }

    /**
     * The fixed herd.yml (§5.3.3 step 3), written by hand: symfony/yaml is no runtime dependency of the kit. The
     * folder name is a DNS-safe slug (Herd site names are), so it needs no YAML quoting.
     */
    private function manifest(SetupContext $context): string
    {
        $lines = ['name: '.$context->folderName(), "php: '8.4'", 'secured: '.($context->answers->https ? 'true' : 'false')];

        if ($context->answers->database === 'pgsql') {
            $lines[] = 'services:';
            $lines[] = '    postgresql:';
            $lines[] = "        version: '".self::POSTGRES_VERSION."'";
            $lines[] = "        port: '\${DB_PORT}'";
        }

        return implode("\n", [...$lines, ...$this->otherKeys($context)])."\n";
    }

    /**
     * The lines of the existing file's top-level keys this step does not own, in order. A key starts at a line
     * without indentation that is no comment or list item, and runs until the next one; lines before the first key
     * (a comment, `---`) are dropped.
     *
     * @return list<string>
     */
    private function otherKeys(SetupContext $context): array
    {
        $path = $context->path('herd.yml');
        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        $kept = [];
        $keep = false;

        foreach (preg_split('/\R/', rtrim($existing)) ?: [] as $line) {
            if (preg_match('/^([^\s#\-][^:]*):/', $line, $match) === 1) {
                $keep = ! in_array(trim($match[1], " \t'\""), self::OWN_KEYS, true);
            }

            if ($keep) {
                $kept[] = $line;
            }
        }

        return $kept;
    }
}
