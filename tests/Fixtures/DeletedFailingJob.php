<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/** Deletes itself and then throws: the queue does not fail a deleted job, so JobFailed never fires. */
final class DeletedFailingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): never
    {
        $this->delete();

        throw new RuntimeException('boom after delete');
    }
}
