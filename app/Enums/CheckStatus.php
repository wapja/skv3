<?php

declare(strict_types=1);

namespace App\Enums;

/** The outcome of one doctor check (§5.4, RL-11): green means no Fail. */
enum CheckStatus: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
    case Skipped = 'skipped';
}
