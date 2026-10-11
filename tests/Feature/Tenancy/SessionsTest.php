<?php

declare(strict_types=1);

/** covers §4.3, §4.4 */

use App\Models\User;
use App\Tenancy\Auth\Sessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('deletes the users\' session rows and clears their remember tokens, and leaves everyone else alone (RL-28)', function (): void {
    $first = User::factory()->create(['remember_token' => 'first-token']);
    $second = User::factory()->create(['remember_token' => 'second-token']);
    $otherUser = User::factory()->create(['remember_token' => 'other-token']);

    /** Stores a session row the way the database session driver writes it; a guest row has no user_id. */
    $storeSession = function (?User $owner): string {
        $id = Str::random(40);
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $owner?->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => time(),
        ]);

        return $id;
    };

    $storeSession($first);
    $storeSession($first);
    $storeSession($second);
    $kept = [$storeSession($otherUser), $storeSession(null)];

    Sessions::endFor([$first->id, $second->id]);

    expect(DB::table('sessions')->pluck('id')->all())->toEqualCanonicalizing($kept)
        ->and(DB::table('users')->whereIn('id', [$first->id, $second->id])->pluck('remember_token')->all())->toBe([null, null])
        ->and(DB::table('users')->where('id', $otherUser->id)->value('remember_token'))->toBe('other-token');
});
