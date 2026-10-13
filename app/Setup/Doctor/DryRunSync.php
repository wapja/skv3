<?php

declare(strict_types=1);

namespace App\Setup\Doctor;

use App\Authorization\SyncPermissions;
use App\Authorization\SyncResult;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Runs SyncPermissions inside a transaction that is always rolled back, so the doctor sees the drift (FU2-13)
 * without repairing it. Only the `permissions` family of fixes runs the real sync.
 */
final readonly class DryRunSync
{
    public function __construct(private SyncPermissions $syncPermissions) {}

    public function run(): SyncResult
    {
        DB::beginTransaction();

        try {
            return $this->syncPermissions->handle();
        } finally {
            DB::rollBack();
            // The sync cleared the permission cache inside the transaction; clear it again so nothing keeps the rolled-back state.
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
