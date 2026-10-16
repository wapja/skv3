<?php

declare(strict_types=1);

/** covers §6.5 */

use App\Support\ByteSize;

it('formats a size in 1024-based units with at most one decimal, without ext-intl (CD-9, F20)', function (int|float $bytes, string $expected): void {
    expect(ByteSize::format($bytes))->toBe($expected);
})->with([
    'nothing' => [0, '0 B'],
    'a few bytes' => [7, '7 B'],
    'just under a kilobyte' => [1023, '1023 B'],
    'a kilobyte' => [1024, '1 KB'],
    'one and a half kilobytes' => [1536, '1.5 KB'],
    'rounded to one decimal' => [1100, '1.1 KB'],
    'rounding into the next unit' => [1048575, '1 MB'],
    'a float, as usedStorage() returns' => [2.5 * 1024 ** 2, '2.5 MB'],
    'gigabytes' => [5 * 1024 ** 3, '5 GB'],
    'terabytes' => [1.5 * 1024 ** 4, '1.5 TB'],
    'beyond terabytes' => [2048 * 1024 ** 4, '2048 TB'],
    'a negative size' => [-5, '0 B'],
]);
