<?php

declare(strict_types=1);

/** covers §5.3.4, §7.2 */

use App\Setup\Http\EnsureSetupAccess;
use App\Setup\Marker;
use App\Setup\SetupToken;
use App\Setup\Wizard\WizardState;
use App\Tenancy\Hosts;
use Illuminate\Support\Str;

it('answers 404 without the token, with a wrong token, or outside setup mode (§7.2)', function (): void {
    $context = useSetupWizard();

    $this->get('/welcome-setup')->assertNotFound();
    $this->get('/welcome-setup?token='.str_repeat('0', 64))->assertNotFound();

    $token = (string) new SetupToken($context->basePath)->value();
    app()->detectEnvironment(fn (): string => 'production');

    $this->get('/welcome-setup?token='.$token)->assertNotFound();
});

it('answers 404 once the marker exists (§5.3.4)', function (): void {
    $context = useSetupWizard();
    $token = (string) new SetupToken($context->basePath)->value();
    Marker::write($context, []);

    $this->get('/welcome-setup?token='.$token)->assertNotFound();
});

it('answers 404 behind a proxy or tunnel (§7.2, DEC-24)', function (string $header): void {
    $context = useSetupWizard();
    $token = (string) new SetupToken($context->basePath)->value();

    $this->get('/welcome-setup?token='.$token, [$header => 'x'])->assertNotFound();
})->with(['X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Forwarded-Port', 'X-Forwarded-Prefix', 'X-Original-Host', 'Forwarded', 'X-Expose-Subdomain', 'Via', 'X-Real-IP', 'True-Client-IP', 'CF-Connecting-IP', 'CF-Ray']);

it('keeps the token in the session and drops it from the address (RL-2)', function (): void {
    $context = useSetupWizard();
    $token = (string) new SetupToken($context->basePath)->value();

    $this->get('/welcome-setup?token='.$token)->assertRedirect(url('/welcome-setup'));

    expect(session(EnsureSetupAccess::SESSION_KEY))->toBe(hash('sha256', $token));

    $this->get('/welcome-setup')->assertOk()->assertSee('data-test="setup-wizard"', false);
});

it('refuses a Livewire update without the session token (§7.2, RL-2)', function (): void {
    $context = useSetupWizard();
    $this->get('/welcome-setup?token='.new SetupToken($context->basePath)->value());
    $snapshot = pageSnapshot(url('/welcome-setup'), 'pages::setup.wizard');
    // Setup mode runs locally, where VerifyCsrfToken is active: send the token as Livewire's script does.
    $this->withHeader('X-CSRF-TOKEN', session()->token());

    callPageAction($snapshot, '$refresh')->assertOk();

    session()->forget(EnsureSetupAccess::SESSION_KEY);

    callPageAction($snapshot, '$refresh')->assertNotFound();
});

it('refuses a session that holds the hash of another token, on a GET and on a Livewire update (RL-2)', function (): void {
    $context = useSetupWizard();
    $this->get('/welcome-setup?token='.new SetupToken($context->basePath)->value());
    $snapshot = pageSnapshot(url('/welcome-setup'), 'pages::setup.wizard');
    $this->withHeader('X-CSRF-TOKEN', session()->token());
    // A token from an earlier bootstrap: its hash is a string, but not the current token's.
    session()->put(EnsureSetupAccess::SESSION_KEY, hash('sha256', 'stale'));

    $this->get('/welcome-setup')->assertNotFound();
    callPageAction($snapshot, '$refresh')->assertNotFound();
});

it('answers 404 to a request from another machine and never hands it the token (RL-4, g4-01)', function (string $address): void {
    $context = useSetupWizard();
    $token = (string) new SetupToken($context->basePath)->value();
    $this->withServerVariables(['REMOTE_ADDR' => $address]);

    $home = (string) $this->get('/')->assertRedirect()->headers->get('Location');

    expect($home)->not->toContain('token=');
    $this->get('/welcome-setup?token='.$token)->assertNotFound();
})->with(['LAN' => '192.168.1.20', 'public' => '203.0.113.7', 'IPv6' => '2001:db8::1']);

it('lets every loopback address through (RL-4)', function (string $address): void {
    $context = useSetupWizard();
    $this->withServerVariables(['REMOTE_ADDR' => $address]);

    $this->get('/welcome-setup?token='.new SetupToken($context->basePath)->value())->assertRedirect(url('/welcome-setup'));
})->with(['127.0.0.1', '127.0.0.2', '::1']);

it('issues a new session id when it accepts the token, so a planted id never becomes authorised (g3-26)', function (): void {
    $context = useSetupWizard();
    $planted = Str::random(40);

    $this->withCookie((string) config('session.cookie'), $planted)
        ->get('/welcome-setup?token='.new SetupToken($context->basePath)->value())
        ->assertRedirect(url('/welcome-setup'));

    expect(session()->getId())->not->toBe($planted)
        ->and(session(EnsureSetupAccess::SESSION_KEY))->toBe(app(SetupToken::class)->hash())
        ->and((string) session()->getHandler()->read($planted))->not->toContain((string) app(SetupToken::class)->hash());
});

it('carries nothing of a planted session over and destroys its file when it accepts the token (L7b-11)', function (): void {
    // Arrange: a session file written beforehand under an id the attacker knows, holding data of their choosing.
    $context = useSetupWizard();
    $planted = Str::random(40);
    session()->getHandler()->write($planted, json_encode(['planted' => 'by-someone-else', '_token' => 'known-csrf-token'], JSON_THROW_ON_ERROR));

    $this->withCookie((string) config('session.cookie'), $planted)
        ->get('/welcome-setup?token='.new SetupToken($context->basePath)->value())
        ->assertRedirect(url('/welcome-setup'));

    expect(session()->getId())->not->toBe($planted)
        ->and(session()->has('planted'))->toBeFalse()
        ->and(session()->token())->not->toBe('known-csrf-token')
        ->and(session(EnsureSetupAccess::SESSION_KEY))->toBe(app(SetupToken::class)->hash())
        ->and(session()->getHandler()->read($planted))->toBe('');
});

it('never stores the token in the session, not even as the previous URL (g3-27)', function (): void {
    $context = useSetupWizard();
    $token = (string) new SetupToken($context->basePath)->value();

    $this->get('/welcome-setup?token='.$token)->assertRedirect(url('/welcome-setup'));

    expect((string) session()->previousUrl())->not->toContain($token)
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain($token);
});

it('sends no referrer from the wizard, whose first URL carries the token (g3-13)', function (): void {
    $context = useSetupWizard();

    $this->get('/welcome-setup?token='.new SetupToken($context->basePath)->value())->assertRedirect()->assertHeader('Referrer-Policy', 'no-referrer');
    $this->get('/welcome-setup')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
});

it('answers only on the pinned host and its admin host once setup has started (RL-5, g3-25)', function (): void {
    $context = useSetupWizard();
    $this->get('/welcome-setup?token='.new SetupToken($context->basePath)->value());
    $snapshot = pageSnapshot(url('/welcome-setup'), 'pages::setup.wizard');
    $this->withHeader('X-CSRF-TOKEN', session()->token());
    callPageAction($snapshot, 'start', updates: [
        'appName' => 'Acme', 'database' => 'sqlite', 'startWith' => 'demo', 'modules' => ['impersonation'], 'https' => false, 'claude' => false,
    ])->assertOk();

    expect(WizardState::load($context->basePath)?->host)->toBe('localhost');
    callPageAction($snapshot, '$refresh', host: Hosts::admin())->assertOk();
    callPageAction($snapshot, 'advance', host: Hosts::tenant('acme'))->assertNotFound();
    $this->get(Hosts::url(Hosts::tenant('acme'), '/welcome-setup'))->assertNotFound();
    $this->get(Hosts::url(Hosts::apex(), '/welcome-setup'))->assertOk();
});

it('opens on the apex, the admin host and a tenant host until the marker exists (§6.2, g3-35)', function (string $kind): void {
    $context = useSetupWizard();
    $host = match ($kind) {
        'apex' => Hosts::apex(),
        'admin' => Hosts::admin(),
        default => Hosts::tenant('acme'),
    };
    $token = (string) new SetupToken($context->basePath)->value();

    $this->get(Hosts::url($host, '/welcome-setup?token='.$token))->assertRedirect(Hosts::url($host, '/welcome-setup'));
    $this->get(Hosts::url($host, '/welcome-setup'))->assertOk()->assertSee('data-test="setup-wizard"', false);

    Marker::write($context, []);

    $this->get(Hosts::url($host, '/welcome-setup'))->assertNotFound();
})->with(['apex', 'admin', 'tenant']);
