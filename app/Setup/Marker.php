<?php

declare(strict_types=1);

namespace App\Setup;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Date;

/** storage/app/skv-installed (§5.3.3 step 9, RL-14): written once setup has finished; its absence means "not set up". */
final class Marker
{
    public const string FILE = 'storage/app/skv-installed';

    public static function exists(SetupContext $context): bool
    {
        return is_file($context->path(self::FILE));
    }

    /** @param  array<string, mixed>  $summary */
    public static function write(SetupContext $context, array $summary): void
    {
        $path = $context->path(self::FILE);
        @mkdir(dirname($path), 0755, true);

        file_put_contents($path, json_encode([
            'kit_version' => InstalledVersions::getRootPackage()['pretty_version'],
            'answers' => $context->answers->toPublicArray(),
            'start_with' => $context->answers->startWith,
            'installed_at' => Date::now()->toIso8601String(),
            'summary' => $summary,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    /** @return array{kit_version: string, answers: array<string, mixed>, start_with: string, installed_at: string, summary: array<string, mixed>}|null */
    public static function read(SetupContext $context): ?array
    {
        if (! self::exists($context)) {
            return null;
        }

        /** @var array{kit_version: string, answers: array<string, mixed>, start_with: string, installed_at: string, summary: array<string, mixed>} $marker */
        $marker = json_decode((string) file_get_contents($context->path(self::FILE)), true, flags: JSON_THROW_ON_ERROR);

        return $marker;
    }
}
