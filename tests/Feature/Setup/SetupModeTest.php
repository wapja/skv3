<?php

declare(strict_types=1);

/** covers §5.3.4, §6.2 */

use App\Enums\CheckStatus;
use App\Models\Organisation;
use App\Models\User;
use App\Setup\Doctor\Checks\SessionDriverCheck;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Marker;
use App\Setup\SetupContext;
use App\Setup\SetupMode;
use App\Setup\SetupToken;
use App\Setup\Support\Toolchain;
use App\Setup\Wizard\WizardRunner;
use App\Setup\Wizard\WizardState;
use App\Tenancy\Facades\Tenant;
use Illuminate\Cache\FileStore;
use Illuminate\Session\FileSessionHandler;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

// Herd installed in the sandbox home; `herd tld` answers "test", or fails with $tldExitCode.
$withHerd = function (SetupContext $context, int $tldExitCode = 0): void {
    $herd = $context->path('home/Library/Application Support/Herd/bin/herd');
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    app()->instance(Toolchain::class, new Toolchain($context->path('home'), $context->basePath));
    Process::fake([
        "*herd' 'tld'*" => Process::result($tldExitCode === 0 ? "test\n" : '', exitCode: $tldExitCode),
        "*herd' 'services:list' '--json'*" => Process::result(''),
    ]);
};

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

    // The handler the request's session actually used: switching the config after StartSession would leave
    // the request on the project's database session (ImpersonationAwareSessionHandler) and still pass a config check.
    expect(app('request')->session()->getHandler())->toBeInstanceOf(FileSessionHandler::class)
        ->and(config('session.driver'))->toBe('file')
        ->and(config('cache.default'))->toBe('file')
        ->and(config('queue.default'))->toBe('sync');
});

it('points the permission registrar at the setup cache, away from the database store it booted with (RL-1)', function (): void {
    // Arrange: the project's database cache, as the registrar was built at boot before setup mode applies.
    useSetupWizard();
    config(['cache.default' => 'database']);
    app(PermissionRegistrar::class)->initializeCache();

    $this->get('/login')->assertOk();

    expect(app(PermissionRegistrar::class)->getCacheStore())->toBeInstanceOf(FileStore::class);
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
})->with([
    'X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Original-Host', 'Forwarded', 'X-Expose-Subdomain', 'Via', 'X-Real-IP',
    'True-Client-IP', 'CF-Connecting-IP', 'CF-Ray',
    // M7c T10 #5: what other proxies, CDNs and tunnels add.
    'X-Client-IP', 'Client-IP', 'X-Cluster-Client-IP', 'Fastly-Client-IP', 'X-Host', 'X-Original-URL', 'X-Forwarded', 'Forwarded-For',
    'Tailscale-User-Login',
]);

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

it('hands the token out only on a host the wizard can pin (RL-5, g3-25)', function (): void {
    $context = useSetupWizard();
    $token = (string) new SetupToken($context->basePath)->value();

    $refused = (string) $this->get('http://example.com/')->assertRedirect()->headers->get('Location');

    expect($refused)->not->toContain('token=');
    $this->get('http://acme.localhost/')->assertRedirectContains('/welcome-setup?token='.$token);
});

it('hands the token out on / only on the pinned host and its admin host once the wizard has begun (RL-5)', function (): void {
    $context = useSetupWizard();
    $token = (string) new SetupToken($context->basePath)->value();
    app(WizardRunner::class)->begin([
        'app_name' => 'Acme', 'database' => 'sqlite', 'start_with' => 'demo', 'modules' => ['impersonation'],
        'https' => false, 'playwright' => false, 'claude' => false,
    ], 'myapp.test');

    // evil.test is a local name the guard allows before begin(): only the pin keeps the token from it.
    $refused = (string) $this->get('http://evil.test/')->assertRedirect(route('login'))->headers->get('Location');

    expect(WizardState::load($context->basePath)?->host)->toBe('myapp.test')
        ->and($refused)->not->toContain($token);
    $this->get('http://myapp.test/')->assertRedirect('/welcome-setup?token='.$token);
    $this->get('http://admin.myapp.test/')->assertRedirect('/welcome-setup?token='.$token);
});

it('sends / on a host the wizard refuses to the address to use, without the token (§5.3.4, g3-34)', function (): void {
    useSetupWizard();

    $location = (string) $this->get('http://127.0.0.1:8000/')->assertRedirect('http://localhost:8000/')->headers->get('Location');

    expect($location)->not->toContain('token=');
});

it('shows the folder problem on / under Herd, where no address would work (§5.3.4, g3-10)', function () use ($withHerd): void {
    $context = useSetupWizard(setupSandbox(prefix: 'my_app_'));
    $withHerd($context);

    $this->get('/')->assertStatus(409)
        ->assertSee(__('The folder name :folder is not a valid web address under Herd. Rename the folder to :name, then start the setup again.', [
            'folder' => $context->folderName(),
            'name' => Str::slug($context->folderName()),
        ]))
        ->assertDontSee((string) new SetupToken($context->basePath)->value());
});

it('shows on / that Herd did not answer while herd tld fails (§5.3.4, g3-28)', function () use ($withHerd): void {
    $context = useSetupWizard();
    $withHerd($context, tldExitCode: 1);

    $this->get('/')->assertStatus(409)
        ->assertSee(__('Herd did not answer. Reload the page in a moment.'))
        ->assertDontSee((string) new SetupToken($context->basePath)->value());
});

it('resolves the host once when / refuses it under Herd, so a hung herd tld stalls / for one timeout (G6-07)', function () use ($withHerd): void {
    $context = useSetupWizard();
    $withHerd($context);

    $this->get('http://evil.test/')->assertRedirect('http://'.Str::lower($context->folderName()).'.test/');

    Process::assertRanTimes(fn ($process): bool => array_slice((array) $process->command, -1) === ['tld'], 1);
});
