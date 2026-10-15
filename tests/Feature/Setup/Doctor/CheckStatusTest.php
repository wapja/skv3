<?php

declare(strict_types=1);

/** covers §5.4, §6.5 */

use App\Enums\CheckStatus;

it('labels and colours every status for the System page (§6.5)', function (CheckStatus $status, string $label, string $color): void {
    expect($status->label())->toBe(__($label))
        ->and($status->color())->toBe($color);
})->with([
    'pass' => [CheckStatus::Pass, 'passed', 'lime'],
    'warn' => [CheckStatus::Warn, 'warning', 'amber'],
    'fail' => [CheckStatus::Fail, 'failed', 'red'],
    'skipped' => [CheckStatus::Skipped, 'skipped', 'zinc'],
]);
