<?php

declare(strict_types=1);

namespace App\Support\Scheduler;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;

/**
 * The mutex of the System page's schedule copies (CD-8, F1). It never holds a lock and never sees one, so reading a
 * copy's filters neither takes nor releases the real overlap lock of a running job: `active` reports the module and
 * encryption conditions, not whether the job happens to run right now.
 */
final readonly class InertEventMutex implements EventMutex
{
    public function create(Event $event): bool
    {
        return true;
    }

    public function exists(Event $event): bool
    {
        return false;
    }

    public function forget(Event $event): void {}
}
