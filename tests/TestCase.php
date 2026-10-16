<?php

declare(strict_types=1);

namespace Tests;

use Database\Seeders\EssentialsSeeder;
use Illuminate\Foundation\Testing\Attributes\Seeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Livewire\Livewire;

#[Seeder(EssentialsSeeder::class)]
abstract class TestCase extends BaseTestCase
{
    /**
     * Every test starts a new request cycle, as a php-fpm worker does: Livewire keeps the `@assets` it rendered (the
     * passkey script) and other per-request state in static properties, which would otherwise leak into the HTML of
     * the next test in the same process.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Livewire::flushState();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
