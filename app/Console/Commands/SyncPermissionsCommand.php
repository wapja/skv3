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

        foreach ([
            'organisation admin template updated' => $result->organisationAdminChanged ? 1 : 0,
            'super-only permissions detached from team roles' => $result->superOnlyDetached,
            'super admin badges repaired' => $result->badgesRepaired,
            'super admin badge permissions stripped' => $result->badgePermissionsStripped,
        ] as $label => $count) {
            if ($count > 0) {
                $this->components->twoColumnDetail($label, (string) $count);
            }
        }

        return self::SUCCESS;
    }
}
