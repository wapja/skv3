<?php

declare(strict_types=1);

namespace App\Setup\Steps;

use App\Setup\SetupContext;
use App\Setup\SetupStep;
use App\Setup\StepResult;

/** §5.3.3 step 3: herd.yml with the site name, PHP 8.4, the HTTPS answer and, on pgsql, Herd's PostgreSQL 18 service. */
final class HerdManifestStep implements SetupStep
{
    /** The PostgreSQL version exactly as `herd services:list --json` lists it (Herd 1.30.2). */
    public const string POSTGRES_VERSION = '18';

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

        return implode("\n", $lines)."\n";
    }
}
