<?php

declare(strict_types=1);

/** covers §6.4 */

use App\Actions\Auth\IssueHandoff;
use App\Events\HandoffConsumed;
use App\Models\HandoffToken;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Hosts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

it('signs a super admin in on another host with a single-use link', function (): void {
    Event::fake([HandoffConsumed::class]);
    Organisation::factory()->create(['slug' => 'acme']);
    $superAdmin = User::factory()->superAdmin()->create();

    $url = app(IssueHandoff::class)->handle($superAdmin, Hosts::tenant('acme'), '/settings/profile');

    expect($url)->toStartWith('http://acme.localhost/auth/handoff/');
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

it('signs out a different user and starts a clean session', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $superAdmin = User::factory()->superAdmin()->create();
    $url = app(IssueHandoff::class)->handle($superAdmin, Hosts::tenant('acme'));
    $this->actingAs($member)->withSession(['auth.password_confirmed_at' => time()]);

    $this->get($url);

    $this->assertAuthenticatedAs($superAdmin);
    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();
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
