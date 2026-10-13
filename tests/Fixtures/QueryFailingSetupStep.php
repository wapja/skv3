<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Setup\SetupContext;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use Illuminate\Database\QueryException;
use PDOException;

/** A database step whose query fails with a password hash among its bindings (RL-6). */
final readonly class QueryFailingSetupStep implements SetupStep
{
    public function key(): string
    {
        return 'database';
    }

    public function label(): string
    {
        return 'Database';
    }

    public function isComplete(SetupContext $context): bool
    {
        return false;
    }

    public function isLong(): bool
    {
        return false;
    }

    public function run(SetupContext $context): StepResult
    {
        throw new QueryException('setup', 'insert into users (password) values (?)', ['$2y$12$secret-hash'], new PDOException('UNIQUE constraint failed: users.email'));
    }
}
