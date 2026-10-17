<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Authorization\SetSuperAdmin;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Auth\PasskeyRelyingParty;
use App\Tenancy\Hosts;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\SoftwareAuthenticator;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\Exception\CounterException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withSession;

beforeEach(function (): void {
    usePasskeysModule(true);
});

/** Register a passkey for the user through the real endpoints on the current host, then sign out. */
function registerPasskeyFor(User $user, SoftwareAuthenticator $authenticator, string $origin): void
{
    actingAs($user);
    withSession(['auth.password_confirmed_at' => time()]);

    /** @var array<string, mixed> $options */
    $options = getJson(route('passkey.registration-options'))->assertOk()->json('options');
    postJson(route('passkey.store'), ['name' => 'Test key', 'credential' => $authenticator->attest($options, $origin)])
        ->assertSuccessful();

    Auth::guard('web')->logout();
}

/** Switch to a test host ('tenant', 'apex' or 'admin') and return its browser origin; another name is a typo. */
function passkeyHost(string $host, Organisation $organisation): string
{
    match ($host) {
        'tenant' => onTenant($organisation),
        'apex' => onApex(),
        'admin' => onAdminHost(),
        default => throw new LogicException("Unknown host kind [{$host}]."),
    };

    return Hosts::origin(match ($host) {
        'tenant' => Hosts::tenant($organisation->slug),
        'apex' => Hosts::apex(),
        default => Hosts::admin(),
    });
}

/** @return TestResponse<Response> */
function signInWithPasskey(SoftwareAuthenticator $authenticator, string $origin): TestResponse
{
    /** @var array<string, mixed> $options */
    $options = getJson(route('passkey.login-options'))->assertOk()->json('options');

    return postJson(route('passkey.login'), ['credential' => $authenticator->assert($options, $origin)]);
}

/**
 * Loads config/fortify.php with the given environment variables set and returns its passkey relying-party id.
 *
 * @param  array<string, string>  $variables
 */
function passkeyRelyingPartyIdWith(array $variables): mixed
{
    $saved = [];

    try {
        foreach ($variables as $name => $value) {
            $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        /** @var array{passkeys: array{relying_party_id: mixed}} $fortify */
        $fortify = require config_path('fortify.php');

        return $fortify['passkeys']['relying_party_id'];
    } finally {
        foreach ($saved as $name => [$env, $server]) {
            unset($_ENV[$name], $_SERVER[$name]);

            if ($env !== null) {
                $_ENV[$name] = $env;
            }

            if ($server !== null) {
                $_SERVER[$name] = $server;
            }
        }
    }
}

it('holds only the relying-party id override in the Fortify config (FU3-2)', function (): void {
    expect(config('app.url'))->toBe('http://localhost')
        ->and(passkeyRelyingPartyIdWith(['TENANCY_APEX_DOMAIN' => 'example.test']))->toBeNull()
        ->and(passkeyRelyingPartyIdWith([
            'TENANCY_APEX_DOMAIN' => 'example.test',
            'PASSKEYS_RELYING_PARTY_ID' => 'id.example.test',
        ]))->toBe('id.example.test');
});

it('does not report failed WebAuthn ceremonies (FU3-1)', function (): void {
    $handler = app(ExceptionHandler::class);

    expect($handler->shouldReport(AuthenticatorResponseVerificationException::create('Invalid signature.')))->toBeFalse();
});

it('uses the lower-cased apex as the relying-party id unless it is overridden (FU3-2)', function (): void {
    config(['tenancy.apex_domain' => 'Example.TEST', 'fortify.passkeys.relying_party_id' => null]);
    expect(PasskeyRelyingParty::id())->toBe('example.test');

    config(['fortify.passkeys.relying_party_id' => 'ID.Example.Test']);
    expect(PasskeyRelyingParty::id())->toBe('id.example.test');
});

it('has a relying-party id after the module is switched on at runtime (FU3-2)', function (): void {
    usePasskeysModule(true);

    expect(config('passkeys.relying_party_id'))->toBe(PasskeyRelyingParty::id());
});

it('allows the current host\'s origin for passkey ceremonies (P1)', function (string $host, string $origin): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    passkeyHost($host, $organisation);

    $this->get(route('login'))->assertOk();

    expect(config('passkeys.allowed_origins'))->toBe([$origin]);
})->with([
    'tenant' => ['tenant', 'http://acme.localhost'],
    'apex' => ['apex', 'http://localhost'],
    'admin' => ['admin', 'http://admin.localhost'],
]);

it('allows the host with APP_URL\'s scheme and port, not the request\'s (CD-4, G1-06)', function (): void {
    config(['app.url' => 'https://localhost']);
    Organisation::factory()->create(['slug' => 'acme']);

    $this->get('http://acme.localhost:8443/login')->assertOk();

    expect(config('passkeys.allowed_origins'))->toBe(['https://acme.localhost']);
});

it('registers a passkey on a tenant host and signs the member in with it there', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $authenticator = new SoftwareAuthenticator;
    onTenant($organisation);

    registerPasskeyFor($member, $authenticator, 'http://acme.localhost');

    signInWithPasskey($authenticator, 'http://acme.localhost')->assertOk();
    $this->assertAuthenticatedAs($member);
});

it('refuses a passkey sign-in on a host the user does not belong to', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $authenticator = new SoftwareAuthenticator;
    onTenant($organisation);
    registerPasskeyFor($member, $authenticator, 'http://acme.localhost');

    onApex();

    signInWithPasskey($authenticator, 'http://localhost')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('credential');
    $this->assertGuest();
});

it('signs a super admin in with a passkey on central and tenant hosts (P2)', function (string $host): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $superAdmin = User::factory()->superAdmin()->create();
    $authenticator = new SoftwareAuthenticator;
    onAdminHost();
    registerPasskeyFor($superAdmin, $authenticator, 'http://admin.localhost');

    signInWithPasskey($authenticator, passkeyHost($host, $organisation))->assertOk();
    $this->assertAuthenticatedAs($superAdmin);
})->with(['tenant', 'apex', 'admin']);

it('refuses a passkey of a user who is no longer active or was deleted', function (string $change): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $authenticator = new SoftwareAuthenticator;
    onTenant($organisation);
    registerPasskeyFor($member, $authenticator, 'http://acme.localhost');

    match ($change) {
        'disabled' => DB::table('users')->where('id', $member->id)->update(['status' => 'disabled']),
        'deleted' => $member->delete(),
        default => throw new LogicException("Unknown change [{$change}]."),
    };

    signInWithPasskey($authenticator, 'http://acme.localhost')->assertUnprocessable();
    $this->assertGuest();
})->with(['disabled', 'deleted']);

it('answers a passkey from a foreign origin with 422 instead of a server error', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $authenticator = new SoftwareAuthenticator;
    onTenant($organisation);
    registerPasskeyFor($member, $authenticator, 'http://acme.localhost');

    signInWithPasskey($authenticator, 'http://evil.localhost')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('credential');
    $this->assertGuest();
});

it('reports a signature counter that did not increase, and no other failed WebAuthn ceremony (CD-6)', function (): void {
    $handler = app(ExceptionHandler::class);

    expect($handler->shouldReport(CounterException::create(1, 1, 'Invalid counter.')))->toBeTrue()
        ->and($handler->shouldReport(AuthenticatorResponseVerificationException::create('Invalid signature.')))->toBeFalse();
});

it('refuses a cloned authenticator and logs it once as a warning, without a stack trace (CD-6)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $authenticator = new SoftwareAuthenticator;
    onTenant($organisation);
    registerPasskeyFor($member, $authenticator, 'http://acme.localhost');
    // The same key, credential and signature counter: what a copied authenticator presents.
    $clone = clone $authenticator;
    signInWithPasskey($authenticator, 'http://acme.localhost')->assertOk();
    Auth::guard('web')->logout();
    expect(recordedActivity(ActivityEvent::LoginFailed))->toBeEmpty();
    $log = Log::spy();

    signInWithPasskey($clone, 'http://acme.localhost')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('credential');

    $this->assertGuest();
    $log->shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'signature counter')
        && $context === [
            'credential_id' => $member->passkeys()->sole()->credential_id,
            'received_counter' => 1,
            'stored_counter' => 1,
            'host' => 'acme.localhost',
            'ip' => '127.0.0.1',
        ]);
    $log->shouldNotHaveReceived('error');
    expect(recordedActivity(ActivityEvent::LoginFailed)->sole()->subject_id)->toBe($member->id);
});

it('records a failed passkey sign-in like a failed password sign-in, naming the member whose passkey it was (CD-6)', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $authenticator = new SoftwareAuthenticator;
    onTenant($organisation);
    registerPasskeyFor($member, $authenticator, 'http://acme.localhost');

    signInWithPasskey($authenticator, 'http://evil.localhost')->assertUnprocessable();

    $entry = recordedActivity(ActivityEvent::LoginFailed)->sole();
    expect([$entry->subject_id, $entry->causer_id, $entry->organisation_id])->toBe([$member->id, null, $organisation->id])
        ->and($entry->properties?->all())->toBe(['method' => 'passkey']);
});

it('names the passkey\'s user on a central host, where every user belongs (CD-6)', function (string $host): void {
    $superAdmin = User::factory()->superAdmin()->create();
    $authenticator = new SoftwareAuthenticator;
    registerPasskeyFor($superAdmin, $authenticator, passkeyHost('admin', Organisation::factory()->create()));
    passkeyHost($host, Organisation::factory()->create());

    signInWithPasskey($authenticator, 'http://evil.localhost')->assertUnprocessable();

    $entry = recordedActivity(ActivityEvent::LoginFailed)->sole();
    expect([$entry->subject_id, $entry->organisation_id])->toBe([$superAdmin->id, null])
        ->and($entry->getProperty('method'))->toBe('passkey');
})->with(['admin', 'apex']);

it('records a failed passkey sign-in without a user when the passkey names nobody this host may show (CD-6, R1)', function (string $owner): void {
    $acme = Organisation::factory()->create(['slug' => 'acme']);
    $beta = Organisation::factory()->create(['slug' => 'beta']);
    $authenticator = new SoftwareAuthenticator;

    match ($owner) {
        'another organisation\'s member' => registerPasskeyFor(User::factory()->for($beta)->create(), $authenticator, passkeyHost('tenant', $beta)),
        'a super admin' => registerPasskeyFor(User::factory()->superAdmin()->create(), $authenticator, passkeyHost('admin', $acme)),
        // SetSuperAdmin, the flag's only writer, keeps the organisation_id of a promoted member (G4-01's premise).
        'a super admin promoted from this organisation' => registerPasskeyFor(
            tap(User::factory()->for($acme)->create(), fn (User $promoted) => app(SetSuperAdmin::class)->handle($promoted, true)),
            $authenticator,
            passkeyHost('admin', $acme),
        ),
        'an unknown passkey' => null, // nothing is registered
        default => throw new LogicException("Unknown owner [{$owner}]."),
    };
    onTenant($acme);

    signInWithPasskey($authenticator, 'http://evil.localhost')->assertUnprocessable();

    $entry = recordedActivity(ActivityEvent::LoginFailed)->sole();
    expect([$entry->subject_id, $entry->organisation_id])->toBe([null, $acme->id])
        ->and($entry->getProperty('method'))->toBe('passkey');
})->with(['an unknown passkey', 'another organisation\'s member', 'a super admin', 'a super admin promoted from this organisation']);

it('records a failed passkey sign-in sent as a form post (CD-6)', function (): void {
    onTenant(Organisation::factory()->create(['slug' => 'acme']));
    /** @var array<string, mixed> $options */
    $options = getJson(route('passkey.login-options'))->assertOk()->json('options');

    post(route('passkey.login'), ['credential' => new SoftwareAuthenticator()->assert($options, 'http://acme.localhost')])
        ->assertRedirect()
        ->assertSessionHasErrors('credential');

    expect(recordedActivity(ActivityEvent::LoginFailed)->sole()->getProperty('method'))->toBe('passkey');
});

it('records a form post whose credential id is not valid UTF-8 without a user instead of failing (CD-6)', function (): void {
    onTenant(Organisation::factory()->create(['slug' => 'acme']));

    // PostgreSQL refuses these bytes in a bound parameter (SQLSTATE 22021); SQLite accepts them.
    post(route('passkey.login'), ['credential' => ['id' => 'x', 'rawId' => "\xC3\x28", 'type' => 'public-key', 'response' => ['clientDataJSON' => 'x']]])
        ->assertRedirect()
        ->assertSessionHasErrors('credential');

    expect(recordedActivity(ActivityEvent::LoginFailed)->sole()->subject_id)->toBeNull();
});
