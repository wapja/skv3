<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

it('signs a session out once the password changed elsewhere', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password']);
    $this->get(route('dashboard'))->assertOk();

    DB::table('users')->where('id', $member->id)->update(['password' => Hash::make('changed-elsewhere')]);
    // Another device changed the password: drop the user model the test's guard still caches.
    Auth::guard('web')->forgetUser();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('signs a session out on its next Livewire update once the password changed elsewhere (DEC-27 (6))', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password']);
    $snapshot = pageSnapshot(route('profile.edit'), 'pages::settings.profile');

    DB::table('users')->where('id', $member->id)->update(['password' => Hash::make('changed-elsewhere')]);
    Auth::guard('web')->forgetUser();

    callPageAction($snapshot, '$refresh')->assertUnauthorized();
    $this->assertGuest();
});

it('keeps the current device signed in after logging out other devices', function (): void {
    Route::middleware(['web', 'auth'])->post('/_logout-other-devices', function (): string {
        Auth::logoutOtherDevices('password');

        return 'ok';
    });
    $organisation = Organisation::factory()->create();
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password']);

    $this->post('/_logout-other-devices')->assertOk();

    $this->get(route('dashboard'))->assertOk();
    $this->assertAuthenticatedAs($member);
});
