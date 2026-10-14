<?php

declare(strict_types=1);

/** covers §5.3.4, §7.2 */

use App\Setup\Http\EnsureSetupAccess;
use App\Setup\Marker;
use App\Setup\SetupToken;

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
})->with(['X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Original-Host', 'Forwarded', 'X-Expose-Subdomain']);

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
