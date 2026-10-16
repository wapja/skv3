<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Auth\PasskeyRelyingParty;
use App\Tenancy\Hosts;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\SoftwareAuthenticator;
use Webauthn\Exception\AuthenticatorResponseVerificationException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
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

/** Switch to a test host ('tenant', 'apex' or 'admin') and return its browser origin. */
function passkeyHost(string $host, Organisation $organisation): string
{
    match ($host) {
        'tenant' => onTenant($organisation),
        'apex' => onApex(),
        default => onAdminHost(),
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
        default => $member->delete(),
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
