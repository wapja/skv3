<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Whether the suite runs in the kit's own repository or in an app generated from it (M8b). The installer keeps the
 * kit's composer.json name, so the name tells nothing; the release check's workflow does: it is export-ignored, so a
 * Packagist install never has it. The kit's CI fails when the file goes missing (tests.yml, "Kit keeps its release
 * check"), so a test that skips outside the kit cannot hide a lost file in the kit.
 */
final class KitRepository
{
    public const string MARKER = '.github/workflows/release-check.yml';

    public static function isKit(): bool
    {
        return is_file(base_path(self::MARKER));
    }
}
