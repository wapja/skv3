<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

final class FailingCountUsersJob implements ShouldQueue
{
    use Queueable;

    public function handle(): never
    {
        throw new RuntimeException('boom');
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put('failed-hook.users', User::query()->count());
    }
}
