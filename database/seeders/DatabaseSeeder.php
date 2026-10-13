<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database. Model events stay on: the demo data runs through the real actions (DEC-28).
     */
    public function run(): void
    {
        $this->call(EssentialsSeeder::class);

        // Demo data never runs in production (§4.12); setup's database step decides for installs.
        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
