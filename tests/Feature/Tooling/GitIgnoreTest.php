<?php

declare(strict_types=1);

/** covers §5.3.2 */
it('keeps an answers file in the project root out of git, because it may hold the super admin password (F16)', function (): void {
    $patterns = file(base_path('.gitignore'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    expect($patterns)->toContain('/answers.json');
});

it('keeps the state files\' temporary copies out of git, because a copy of .env holds every secret (RL-5)', function (): void {
    // A process killed between StateFile::write()'s fopen() and rename() leaves <dir>/.state-<hex> in the project
    // root or in .claude/; for .env and .env.testing it holds the whole file.
    $patterns = file(base_path('.gitignore'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    expect($patterns)->toContain('.state-*');
});
