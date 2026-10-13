<?php

declare(strict_types=1);

/** covers §6.4 */

use App\Actions\Auth\IssueHandoff;
use App\Enums\ActivityEvent;
use App\Events\HandoffConsumed;
use App\Models\HandoffToken;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Hosts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Fixtures\CsrfEnforcingPreventRequestForgery;

it('signs a super admin in on another host with a single-use link', function (): void {
    Event::fake([HandoffConsumed::class]);
    Organisation::factory()->create(['slug' => 'acme']);
    $superAdmin = User::factory()->superAdmin()->create();

    $url = app(IssueHandoff::class)->handle($superAdmin, Hosts::tenant('acme'), '/settings/profile');

    expect($url)->toStartWith('http://acme.localhost/auth/handoff/')
        ->and(HandoffToken::query()->sole()->token_hash)->toBe(hash('sha256', Str::afterLast($url, '/')))
        ->not->toBe(Str::afterLast($url, '/'));
    $this->get($url)
        ->assertRedirect('http://acme.localhost/settings/profile')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    $this->assertAuthenticatedAs($superAdmin);
    Event::assertDispatched(HandoffConsumed::class, fn (HandoffConsumed $event): bool => $event->user->is($superAdmin)
        && $event->host === 'acme.localhost');
});

it('refuses a link that was used, expired, opened on another host or whose user was disabled', function (string $case): void {
    Organisation::factory()->create(['slug' => 'acme']);
    Organisation::factory()->create(['slug' => 'beta']);
    $superAdmin = User::factory()->superAdmin()->create();
    $url = app(IssueHandoff::class)->handle($superAdmin, Hosts::tenant('acme'));

    if ($case === 'used') {
        $this->get($url);
        auth()->logout();
    } elseif ($case === 'expired') {
        $this->travel(61)->seconds();
    } elseif ($case === 'other host') {
        $url = str_replace('acme.localhost', 'beta.localhost', $url);
    } else {
        DB::table('users')->where('id', $superAdmin->id)->update(['status' => 'disabled']);
    }

    $this->get($url)
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', __('This sign-in link is invalid or has expired.'));
    $this->assertGuest();
})->with(['used', 'expired', 'other host', 'disabled']);

it('lets a member hand off to their own host only', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    Organisation::factory()->create(['slug' => 'beta']);
    $member = User::factory()->for($organisation)->create();

    expect(app(IssueHandoff::class)->handle($member, Hosts::tenant('acme')))->toStartWith('http://acme.localhost/');
    expect(fn () => app(IssueHandoff::class)->handle($member, Hosts::tenant('beta')))->toThrow(AuthorizationException::class);
    expect(fn () => app(IssueHandoff::class)->handle($member, Hosts::admin()))->toThrow(AuthorizationException::class);
});

it('lets a super admin hand off only to kit hosts', function (string $host): void {
    Organisation::factory()->create(['slug' => 'acme']);
    $superAdmin = User::factory()->superAdmin()->create();

    expect(fn () => app(IssueHandoff::class)->handle($superAdmin, $host))->toThrow(AuthorizationException::class);
})->with(['ghost.localhost', 'evil.test', 'acme.localhost.evil.test']);

it('only hands off to relative paths', function (string $path): void {
    $superAdmin = User::factory()->superAdmin()->create();

    expect(fn () => app(IssueHandoff::class)->handle($superAdmin, Hosts::admin(), $path))
        ->toThrow(InvalidArgumentException::class);
})->with(['https://evil.test/', '//evil.test/', 'dashboard', '/\\evil.test']);

it('throttles handoff attempts per IP address', function (): void {
    $this->freezeTime(); // counts requests: a system sleep mid-test must not expire the one-minute window
    onApex();

    foreach (range(1, 10) as $attempt) {
        $this->get(route('auth.handoff', ['token' => Str::random(64)]))->assertRedirect(route('login'));
    }

    $this->get(route('auth.handoff', ['token' => Str::random(64)]))->assertTooManyRequests();
});

it('prunes tokens that expired more than a day ago', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();
    app(IssueHandoff::class)->handle($superAdmin, Hosts::admin());
    DB::table('handoff_tokens')->insert([
        'user_id' => $superAdmin->id, 'token_hash' => hash('sha256', 'old'), 'target_host' => Hosts::admin(),
        'path' => '/dashboard', 'expires_at' => now()->subDays(2), 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
    ]);

    Artisan::call('model:prune', ['--model' => [HandoffToken::class]]);

    expect(HandoffToken::query()->count())->toBe(1);
});

it('signs a user in on the apex and on the admin host (FU3-3)', function (string $host): void {
    Event::fake([HandoffConsumed::class]);
    $superAdmin = User::factory()->superAdmin()->create();
    $targetHost = $host === 'apex' ? Hosts::apex() : Hosts::admin();

    $url = app(IssueHandoff::class)->handle($superAdmin, $targetHost);

    $this->get($url)
        ->assertRedirect('http://'.$targetHost.'/dashboard')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    $this->assertAuthenticatedAs($superAdmin);
    expect(HandoffToken::query()->sole()->consumed_at)->not->toBeNull();
    Event::assertDispatched(HandoffConsumed::class, fn (HandoffConsumed $event): bool => $event->user->is($superAdmin)
        && $event->host === $targetHost);
})->with(['apex', 'admin']);

it('consumes a link opened by the same signed-in user without a new sign-in and records it (FU3-2, CV-14)', function (): void {
    Event::fake([HandoffConsumed::class, Login::class]);
    Organisation::factory()->create(['slug' => 'acme']);
    $superAdmin = User::factory()->superAdmin()->create(['remember_token' => 'super-admin-remember']);
    $url = app(IssueHandoff::class)->handle($superAdmin, Hosts::tenant('acme'), '/settings/profile');
    $this->actingAs($superAdmin)->withSession(['handoff-test.marker' => 'kept']);

    $this->get($url)
        ->assertRedirect('http://acme.localhost/settings/profile')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    $this->assertAuthenticatedAs($superAdmin);
    expect(HandoffToken::query()->sole()->consumed_at)->not->toBeNull()
        ->and(session('handoff-test.marker'))->toBe('kept')
        ->and(DB::table('users')->where('id', $superAdmin->id)->value('remember_token'))->toBe('super-admin-remember');
    Event::assertDispatched(HandoffConsumed::class, fn (HandoffConsumed $event): bool => $event->user->is($superAdmin)
        && $event->host === 'acme.localhost');
    Event::assertNotDispatched(Login::class);
});

it('asks before replacing another signed-in user\'s session and leaves the token unused (FU3-2, RK-I14)', function (): void {
    Event::fake([HandoffConsumed::class]);
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create(['name' => 'Mia Member']);
    $superAdmin = User::factory()->superAdmin()->create(['name' => 'Sam Super']);
    $url = app(IssueHandoff::class)->handle($superAdmin, Hosts::tenant('acme'));
    $this->actingAs($member);

    $this->get($url)
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSee(__('You are signed in as :name. Continue as :other?', ['name' => 'Mia Member', 'other' => 'Sam Super']))
        ->assertSee('action="'.$url.'"', escape: false)
        ->assertSee('data-test="handoff-continue-button"', escape: false);

    $this->assertAuthenticatedAs($member);
    expect(HandoffToken::query()->sole()->consumed_at)->toBeNull();
    Event::assertNotDispatched(HandoffConsumed::class);
});

it('replaces the session only after the confirmation POST and keeps the other user\'s remember token', function (): void {
    Event::fake([HandoffConsumed::class]);
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create(['remember_token' => 'member-remember']);
    $superAdmin = User::factory()->superAdmin()->create();
    $url = app(IssueHandoff::class)->handle($superAdmin, Hosts::tenant('acme'));
    $this->actingAs($member)->withSession(['auth.password_confirmed_at' => time()]);

    $this->get($url)->assertOk();
    $this->assertAuthenticatedAs($member);

    $this->post($url)
        ->assertRedirect('http://acme.localhost/dashboard')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    $this->assertAuthenticatedAs($superAdmin);
    expect(session()->has('auth.password_confirmed_at'))->toBeFalse()
        ->and(DB::table('users')->where('id', $member->id)->value('remember_token'))->toBe('member-remember')
        ->and(HandoffToken::query()->sole()->consumed_at)->not->toBeNull();
    Event::assertDispatched(HandoffConsumed::class, fn (HandoffConsumed $event): bool => $event->user->is($superAdmin));
});

it('records the sign-out of the user whose session the confirmation POST replaced (§4.8)', function (): void {
    // Fails before the fix: logoutCurrentDevice() fires CurrentDeviceLogout, which the audit listener ignored.
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $superAdmin = User::factory()->superAdmin()->create();
    $url = app(IssueHandoff::class)->handle($superAdmin, Hosts::tenant('acme'));
    $this->actingAs($member);

    $this->post($url)->assertRedirect('http://acme.localhost/dashboard');

    $this->assertAuthenticatedAs($superAdmin);
    $signOut = recordedActivity(ActivityEvent::Logout)->sole();
    expect([$signOut->subject_id, $signOut->causer_id, $signOut->organisation_id])->toBe([$member->id, $member->id, $organisation->id]);
});

it('refuses the confirmation POST without a CSRF token', function (): void {
    // The CSRF middleware skips itself in unit tests; this subclass turns that off for this test only.
    $this->app->bind(PreventRequestForgery::class, CsrfEnforcingPreventRequestForgery::class);
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $superAdmin = User::factory()->superAdmin()->create();
    $url = app(IssueHandoff::class)->handle($superAdmin, Hosts::tenant('acme'));
    $this->actingAs($member);
    $this->get($url)->assertOk();

    $this->post($url)->assertStatus(419);

    $this->assertAuthenticatedAs($member);
    expect(HandoffToken::query()->sole()->consumed_at)->toBeNull();

    $this->post($url, ['_token' => session()->token()])->assertRedirect('http://acme.localhost/dashboard');
    $this->assertAuthenticatedAs($superAdmin);
});

it('refuses a handoff for a member without a reachable organisation with an authorisation error (FU3-4)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $member->unsetRelation('organisation');
    $organisation->delete();

    expect(fn () => app(IssueHandoff::class)->handle($member, Hosts::tenant('acme')))
        ->toThrow(AuthorizationException::class);
});
