<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A size in bytes for people (CD-9, F20): 1024-based, B to TB, at most one decimal with a point. Laravel's own
 * file-size helper needs ext-intl, which the kit does not require.
 */
final readonly class ByteSize
{
    private const array UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    public static function format(int|float $bytes): string
    {
        $value = max(0.0, (float) $bytes);
        $unit = 0;

        // Rounded first, so 1023.96 KB reads as 1 MB, never as 1024 KB.
        while ($unit < count(self::UNITS) - 1 && round($value, 1) >= 1024) {
            $value /= 1024;
            $unit++;
        }

        $rounded = round($value, 1);

        return number_format($rounded, floor($rounded) === $rounded ? 0 : 1, '.', '').' '.self::UNITS[$unit];
    }
}
