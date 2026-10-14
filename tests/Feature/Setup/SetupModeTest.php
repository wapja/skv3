<?php

declare(strict_types=1);

/** covers §5.3.4, §6.2 */

use App\Enums\CheckStatus;
use App\Models\Organisation;
use App\Models\User;
use App\Setup\Doctor\Checks\SessionDriverCheck;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Marker;
use App\Setup\SetupMode;
use App\Setup\SetupToken;
use App\Setup\Support\Toolchain;
use App\Tenancy\Facades\Tenant;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

it('is active only locally, without a marker and with a token (RL-1)', function (): void {
    $context = useSetupWizard();
    $mode = new SetupMode($context->basePath);

    expect($mode->active())->toBeTrue();

    app()->detectEnvironment(fn (): string => 'testing');
    expect($mode->active())->toBeFalse();

    app()->detectEnvironment(fn (): string => 'local');
    unlink(new SetupToken($context->basePath)->path());
    expect($mode->active())->toBeFalse();

    new SetupToken($context->basePath)->ensure();
    Marker::write($context, []);
    expect($mode->active())->toBeFalse();
});

it('moves every web request onto a file session, a file cache and the sync queue (§5.3.4, RL-1)', function (): void {
    useSetupWizard();
    config(['session.driver' => 'database', 'cache.default' => 'database', 'queue.default' => 'database']);

    $this->get('/login')->assertOk();

    expect(config('session.driver'))->toBe('file')
        ->and(config('cache.default'))->toBe('file')
        ->and(config('queue.default'))->toBe('sync');
});

it('lets every host through the tenant resolver in setup mode (§5.3.4)', function (): void {
    $this->get('http://nobody.localhost/login')->assertNotFound();

    useSetupWizard();

    $this->get('http://nobody.localhost/login')->assertOk();
});

it('sends / to the wizard with the token while no super admin exists (§5.3.4, §6.2)', function (): void {
    $context = useSetupWizard();
    $token = (string) new SetupToken($context->basePath)->value();

    $this->get('/')->assertRedirect('/welcome-setup?token='.$token);

    User::factory()->superAdmin()->create();

    $this->get('/')->assertRedirect(route('login'));
});

it('never hands the token to a proxied or tunnelled request (§5.3.4, DEC-24)', function (string $header): void {
    useSetupWizard();

    $this->get('/', [$header => 'x'])->assertRedirect(route('login'));
})->with(['X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Original-Host', 'Forwarded', 'X-Expose-Subdomain']);

it('leaves / alone outside setup mode (§6.2)', function (): void {
    $this->get('/')->assertRedirect(route('login'));
});

it('lets the doctor check the project drivers, not the ones setup mode put in their place (§5.3.4, §5.4)', function (): void {
    $context = useSetupWizard();
    config(['session.driver' => 'database', 'queue.default' => 'database']);

    app(SetupMode::class)->applyRuntime();
    $doctor = DoctorContext::fromSetup($context, app(Toolchain::class));

    expect(app(SessionDriverCheck::class)->run($doctor)->status)->toBe(CheckStatus::Pass)
        ->and(config('session.driver'))->toBe('file')
        ->and(config('setup.replaced'))->toBe(['session' => 'database', 'queue' => 'database']);
});

it('keeps the drivers it replaced when a second request in the same process applies the runtime again (§5.3.4)', function (): void {
    useSetupWizard();
    config(['session.driver' => 'database', 'queue.default' => 'database']);

    app(SetupMode::class)->applyRuntime();
    app(SetupMode::class)->applyRuntime();

    expect(config('setup.replaced'))->toBe(['session' => 'database', 'queue' => 'database'])
        ->and(config('session.driver'))->toBe('file');
});

it('never resolves a tenant in setup mode, even an existing one (§5.3.4)', function (): void {
    Organisation::factory()->create(['slug' => 'acme']);
    useSetupWizard();

    $this->get('http://acme.localhost/login')->assertOk();

    expect(Tenant::check())->toBeFalse();
});
