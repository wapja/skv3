<?php

declare(strict_types=1);

/** covers §4.5, §4.8, §6.2 */

use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\UserNames;

it('names users across organisations, including super admins and deleted users', function (): void {
    $acme = Organisation::factory()->create();
    $beta = Organisation::factory()->create();
    $member = User::factory()->for($acme)->create(['name' => 'Ada Acme']);
    $foreign = User::factory()->for($beta)->create(['name' => 'Bea Beta']);
    $superAdmin = User::factory()->superAdmin()->create(['name' => 'Sam Super']);
    $deleted = User::factory()->for($beta)->create(['name' => 'Dee Deleted', 'deleted_at' => now()]);
    onTenant($acme);

    expect(UserNames::of([$member->id, $foreign->id, $superAdmin->id, $deleted->id, $member->id, 999_999]))->toEqual([
        $member->id => 'Ada Acme',
        $foreign->id => 'Bea Beta',
        $superAdmin->id => 'Sam Super',
        $deleted->id => 'Dee Deleted',
    ])->and(UserNames::of([]))->toBe([]);
});
