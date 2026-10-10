<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

final class RecordUsersJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::put('job.users', User::query()->orderBy('id')->pluck('id')->all());
    }
}
