<?php

declare(strict_types=1);

namespace App\Setup;

use App\Setup\Support\StateFile;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Date;
use JsonException;

/**
 * storage/app/skv-installed (§5.3.3 step 9, RL-14): written once setup has finished; its absence means "not set up".
 * Written atomically with mode 0600 (A17, A22).
 */
final class Marker
{
    public const string FILE = 'storage/app/skv-installed';

    public static function exists(SetupContext $context): bool
    {
        return is_file($context->path(self::FILE));
    }

    /**
     * Writes the marker with the context's current answers. A rewrite (every re-run ends with one) keeps the time of
     * the first install.
     *
     * @param  array<string, mixed>  $summary
     */
    public static function write(SetupContext $context, array $summary): void
    {
        StateFile::writeJson($context->path(self::FILE), [
            'kit_version' => InstalledVersions::getRootPackage()['pretty_version'],
            'answers' => $context->answers->toPublicArray(),
            'start_with' => $context->answers->startWith,
            'installed_at' => self::installedAt($context) ?? Date::now()->toIso8601String(),
            'summary' => $summary,
        ]);
    }

    /**
     * @return array{kit_version: string, answers: array<string, mixed>, start_with: string, installed_at: string, summary: array<string, mixed>}|null
     *
     * @throws JsonException when the marker is not a JSON object
     */
    public static function read(SetupContext $context): ?array
    {
        /** @var array{kit_version: string, answers: array<string, mixed>, start_with: string, installed_at: string, summary: array<string, mixed>}|null $marker */
        $marker = StateFile::readJson($context->path(self::FILE));

        return $marker;
    }

    /** The installed_at of an existing, readable marker, or null. */
    private static function installedAt(SetupContext $context): ?string
    {
        try {
            $marker = StateFile::readJson($context->path(self::FILE));
        } catch (JsonException) {
            return null;
        }

        return is_string($marker['installed_at'] ?? null) ? $marker['installed_at'] : null;
    }
}
