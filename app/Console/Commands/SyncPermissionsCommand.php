<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Authorization\SyncPermissions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Sync the permission catalogue and the system roles')]
#[Signature('permissions:sync')]
final class SyncPermissionsCommand extends Command
{
    public function handle(SyncPermissions $syncPermissions): int
    {
        $result = $syncPermissions->handle();

        $this->components->info($result->changed() ? 'Permissions synced.' : 'Permissions already in sync.');

        foreach ($result->created as $name) {
            $this->components->twoColumnDetail($name, 'created');
        }

        foreach ($result->pruned as $name) {
            $this->components->twoColumnDetail($name, 'pruned');
        }

        return self::SUCCESS;
    }
}
