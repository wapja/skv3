<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Cache\ArrayStore;
use RuntimeException;

/** A cache store that reads (always a miss) but cannot be written: a full disk or a read-only replica (L-30). */
final class UnwritableCacheStore extends ArrayStore
{
    /**
     * @param  string  $key
     * @param  mixed  $value
     * @param  int  $seconds
     */
    public function put($key, $value, $seconds): never
    {
        throw new RuntimeException('The cache store cannot be written.');
    }

    /**
     * @param  string  $key
     * @param  mixed  $value
     */
    public function forever($key, $value): never
    {
        throw new RuntimeException('The cache store cannot be written.');
    }

    /**
     * @param  string  $key
     * @param  mixed  $value
     */
    public function increment($key, $value = 1): never
    {
        throw new RuntimeException('The cache store cannot be written.');
    }
}
