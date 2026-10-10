<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Authorization\SetSuperAdmin;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(SetSuperAdmin $setSuperAdmin): void
    {
        $this->call(EssentialsSeeder::class);

        // Temporary development login until the M6 setup engine and demo seeder exist.
        $user = User::factory()->superAdmin()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $setSuperAdmin->handle($user, true);
    }
}
