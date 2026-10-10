<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Authorization\SyncPermissions;
use Illuminate\Database\Seeder;

/** Always seeded (§4.12): the permission catalogue and the system roles. Idempotent. */
final class EssentialsSeeder extends Seeder
{
    public function run(SyncPermissions $syncPermissions): void
    {
        $syncPermissions->handle();
    }
}
