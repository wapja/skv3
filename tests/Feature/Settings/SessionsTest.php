<?php

declare(strict_types=1);

/** covers §6.6 */

use App\Authorization\Role;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Support\RateLimits\UserActionLimit;
use App\Tenancy\Auth\Impersonation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\call;
use function Pest\Laravel\post;
use function Pest\Laravel\withCredentials;
use function Pest\Laravel\withSession;
use function Pest\Laravel\withUnencryptedCookies;

/*
| The suite runs on the array session driver (phpunit.xml), which writes no `sessions` rows. The list and "log out
| other devices" read and delete those rows, so these tests use the kit's own driver, `database` (with
| ImpersonationAwareSessionHandler). Its garbage collection is off, so a lottery win never deletes a row a test
| inserted. The test client sends no cookie by itself, and without one every request starts a new session, so each
| browser here keeps its cookies and sends them.
*/
beforeEach(function (): void {
    config(['session.driver' => 'database']);
    config(['session.lottery' => [0, 100]]);
    $this->freezeSecond();
});

/**
 * Forgets what the test's earlier requests left in memory, as a new PHP process would: between the requests of
 * one test the session manager and the `session.store` singleton keep their store and its attributes, the auth
 * manager and the `auth.driver` singleton (which the session handler asks for the user id) keep their guard and
 * its user, and the cookie jar keeps the cookies it queued.
 */
$newProcess = function (): void {
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    Auth::forgetGuards();
    app()->forgetInstance('auth.driver');
    app('cookie')->flushQueuedCookies();
};

/**
 * Signs $user in through the login form in a new browser and returns the cookies that browser then holds, encrypted
 * as the response set them: the session and, with $remember, the "remember me" cookie.
 *
 * @return array<string, string>
 */
$signIn = function (User $user, bool $remember = false) use ($newProcess): array {
    $newProcess();
    $response = post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => $remember])->assertRedirect();

    $cookies = [];

    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = (string) $cookie->getValue();
    }

    return $cookies;
};

/**
 * Makes the test's own requests (get(), pageSnapshot(), callPageAction()) come from the browser that holds $cookies,
 * with a fresh password confirmation for the security page. callPageAction() posts JSON, which carries cookies only
 * with withCredentials(). A test uses it for one browser only: the cookies merge with those set before. Other
 * browsers send their cookies with call(), which adds none of these.
 *
 * @param  array<string, string>  $cookies
 */
$useBrowser = function (array $cookies) use ($newProcess): void {
    $newProcess();
    withCredentials();
    withUnencryptedCookies($cookies);
    withSession(['auth.password_confirmed_at' => time()]);
};

/**
 * The validation errors a Livewire update returned.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, list<string>>
 */
$errorsOf = function (TestResponse $response): array {
    /** @var array{memo: array{errors?: array<string, list<string>>}} $snapshot */
    $snapshot = json_decode((string) $response->assertOk()->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);

    return $snapshot['memo']['errors'] ?? [];
};

/**
 * The ids of $user's `sessions` rows, sorted.
 *
 * @return list<string>
 */
$rowsOf = fn (User $user): array => DB::table('sessions')->where('user_id', $user->id)->orderBy('id')->pluck('id')
    ->map(fn (mixed $id): string => (string) $id)->values()->all();

it('lists the user\'s live sessions with browser, platform, IP and last activity, marks this device and never shows a session id (CD-2)', function () use ($signIn, $useBrowser, $rowsOf): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $colleague = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15');
    $useBrowser($signIn($member));
    $thisDevice = $rowsOf($member);
    $phone = sessionRowFor($member, [
        'ip_address' => '198.51.100.23',
        'user_agent' => 'Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36',
        'last_activity' => now()->subMinutes(30)->getTimestamp(),
    ]);
    // Older than the session lifetime: the driver reads this row as an empty session.
    sessionRowFor($member, [
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0',
        'last_activity' => now()->subMinutes((int) config('session.lifetime') + 1)->getTimestamp(),
    ]);
    sessionRowFor($colleague, ['user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 OPR/114.0.0.0']);

    $response = $this->get(route('security.edit'))->assertOk();

    $html = (string) $response->getContent();
    $response->assertSeeInOrder(['Safari on macOS', 'This device', '127.0.0.1', 'Samsung Internet on Android', '198.51.100.23', 'Last active 30 minutes ago'])
        ->assertDontSee('Firefox on Windows')
        ->assertDontSee('Opera on Linux')
        ->assertSee(hash_hmac('sha256', $phone, (string) config('app.key')), false);
    expect($thisDevice)->toHaveCount(1)
        ->and(substr_count($html, 'This device'))->toBe(1)
        ->and($html)->not->toContain($phone)
        ->and($html)->not->toContain($thisDevice[0]);
});

it('signs a second "remember me" browser out on its next request and keeps this device signed in (§6.6)', function () use ($newProcess, $signIn, $useBrowser, $errorsOf): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $laptop = $signIn($member, remember: true);
    $thisDevice = $signIn($member);
    $useBrowser($thisDevice);
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.sessions');

    // Before: the laptop is signed in.
    $newProcess();
    call('GET', route('dashboard'), [], $laptop)->assertOk();
    $this->assertAuthenticatedAs($member);

    $useBrowser($thisDevice);
    expect($errorsOf(callPageAction($snapshot, 'logoutOtherDevices', updates: ['password' => 'password'])))->toBe([]);

    // Its session row is gone, and its remember cookie holds the old password hash, which AuthenticateSession refuses.
    $newProcess();
    call('GET', route('dashboard'), [], $laptop)->assertRedirect(route('login'));
    $this->assertGuest();

    $newProcess();
    call('GET', route('dashboard'), [], $thisDevice)->assertOk();
    $this->assertAuthenticatedAs($member);
});

it('ends only the user\'s other sessions and records how many (§4.8, CD-9)', function () use ($signIn, $useBrowser, $errorsOf, $rowsOf): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $colleague = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $useBrowser($signIn($member));
    $thisDevice = $rowsOf($member);
    sessionRowFor($member);
    sessionRowFor($member, ['user_agent' => null, 'ip_address' => null]);
    $colleagueRow = sessionRowFor($colleague);
    $passwordHash = (string) $member->fresh()?->password;
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.sessions');

    expect($errorsOf(callPageAction($snapshot, 'logoutOtherDevices', updates: ['password' => 'password'])))->toBe([]);

    expect($rowsOf($member))->toBe($thisDevice)
        ->and($rowsOf($colleague))->toBe([$colleagueRow])
        ->and((string) $member->fresh()?->password)->not->toBe($passwordHash);
    $entry = recordedActivity(ActivityEvent::OtherSessionsEnded)->sole();
    expect([$entry->subject_id, $entry->causer_id, $entry->organisation_id])->toBe([$member->id, $member->id, $organisation->id])
        ->and($entry->properties?->all())->toBe(['count' => 2]);
});

it('refuses a wrong password on the password field and ends nothing (CD-2)', function () use ($signIn, $useBrowser, $errorsOf, $rowsOf): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $useBrowser($signIn($member));
    sessionRowFor($member);
    $rows = $rowsOf($member);
    $passwordHash = (string) $member->fresh()?->password;
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.sessions');

    expect($errorsOf(callPageAction($snapshot, 'logoutOtherDevices', updates: ['password' => 'wrong-password'])))->toHaveKey('password');

    expect($rowsOf($member))->toBe($rows)
        ->and((string) $member->fresh()?->password)->toBe($passwordHash)
        ->and(recordedActivity(ActivityEvent::OtherSessionsEnded))->toBeEmpty();
});

it('counts every attempt before it checks the password and refuses the sixth in a minute (CD-2)', function () use ($signIn, $useBrowser, $errorsOf, $rowsOf): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    $useBrowser($signIn($member));
    sessionRowFor($member);
    $rows = $rowsOf($member);
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.sessions');

    foreach (range(1, 5) as $attempt) {
        expect($errorsOf(callPageAction($snapshot, 'logoutOtherDevices', updates: ['password' => 'wrong-password'])))->toHaveKey('password');
    }

    expect($errorsOf(callPageAction($snapshot, 'logoutOtherDevices', updates: ['password' => 'password'])))
        ->toBe(['password' => [__('Too many attempts. Try again in :seconds seconds.', ['seconds' => 60])]])
        ->and($rowsOf($member))->toBe($rows)
        ->and(Hash::check('password', (string) $member->fresh()?->password))->toBeTrue()
        ->and(recordedActivity(ActivityEvent::OtherSessionsEnded))->toBeEmpty()
        ->and(UserActionLimit::logoutOtherDevices())->toEqual(new UserActionLimit('logout-other-devices', 5, 60));
});

it('hides the sessions during an impersonation and refuses an update replayed from before it (CD-2)', function () use ($newProcess, $rowsOf): void {
    useImpersonationModule(true);
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $admin = User::factory()->for($organisation)->create();
    withPermissionsTeam($organisation->id, fn (): User => $admin->assignRole(Role::OrganisationAdmin->value));
    $admin->unsetRelation('roles');
    $member = User::factory()->for($organisation)->create();
    onTenant($organisation);
    sessionRowFor($member);
    $this->actingAs($member);
    withSession(['auth.password_confirmed_at' => time()]);
    $snapshot = pageSnapshot(route('security.edit'), 'pages::settings.sessions');
    $newProcess();

    startImpersonatingOverHttp($admin, $member);
    withSession(['auth.password_confirmed_at' => time()]);

    $this->get(route('security.edit'))->assertOk()
        ->assertSee('Update password')
        ->assertDontSee('Log out other devices');
    Auth::guard('web')->forgetUser();
    $rows = $rowsOf($member);

    callPageAction($snapshot, 'logoutOtherDevices', updates: ['password' => 'password'])->assertForbidden();

    expect(Impersonation::isActive())->toBeTrue()
        ->and($rowsOf($member))->toBe($rows)
        ->and(recordedActivity(ActivityEvent::OtherSessionsEnded))->toBeEmpty();
});

it('shows the sessions with two-factor authentication and passkeys off (§6.8)', function (): void {
    usePasskeysModule(false);
    config(['fortify.features' => []]);
    asMember(Organisation::factory()->create(['slug' => 'acme']));
    withSession(['auth.password_confirmed_at' => time()]);

    $this->get(route('security.edit'))->assertOk()->assertSee('Log out other devices');
});
