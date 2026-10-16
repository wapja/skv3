<?php

declare(strict_types=1);

/** covers §5.3.4, §6.8, §7.2 */

use App\Models\HandoffToken;
use App\Models\Organisation;
use App\Models\User;
use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Marker;
use App\Setup\SetupContext;
use App\Setup\SetupToken;
use App\Setup\StepResult;
use App\Setup\Steps\HerdSiteStep;
use App\Setup\Summary;
use App\Setup\Support\EnvFile;
use App\Setup\Support\Toolchain;
use App\Setup\Wizard\HerdStage;
use App\Setup\Wizard\HerdState;
use App\Setup\Wizard\SetupLock;
use App\Setup\Wizard\StepLauncher;
use App\Setup\Wizard\WizardRunner;
use App\Setup\Wizard\WizardState;
use App\Tenancy\Hosts;
use Dom\HTMLDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Laravel\Boost\Middleware\InjectBoost;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\InlineStepLauncher;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

/**
 * The state finish() leaves: the marker, and a Herd state; returns the secret. The Herd state keeps the context's
 * detection (CD-15), so a test that gives the stage Herd passes a detection that says so.
 */
$afterStepNine = function (SetupContext $context, string $startWith = 'demo', string $host = 'localhost', ?Detection $detection = null, bool $https = true): string {
    $detected = $detection ?? Detection::none();
    $stage = new SetupContext($context->basePath, Answers::fromArray(
        $startWith === 'demo' ? ['modules' => ['impersonation'], 'https' => $https] : ['start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com', 'super_admin_password' => 'correct-horse-battery', 'modules' => ['impersonation'], 'https' => $https],
        $detected,
        $context->folderName(),
    ), $detected);
    $context->answers = $stage->answers;
    Marker::write($stage, Summary::build($stage));
    @unlink(new SetupToken($context->basePath)->path());

    return HerdState::begin($context->basePath, $stage, $host)[1];
};

/** What the Detector finds next to a Herd stub: Herd, on the pinned host. */
$herdDetection = fn (string $host = 'localhost'): Detection => new Detection(herd: true, herdPro: false, herdMail: false, herdSmtpPort: null, postgres: false, fluxPro: false, claude: false, host: $host, scheme: 'http');

/**
 * Herd's read-only listings: the project parked (served) or not, and `herd secured` printing $secured.
 *
 * @return array<string, mixed>
 */
$herdListings = fn (SetupContext $context, bool $served, string $secured = ''): array => [
    "*herd' 'services:list'*" => Process::result(''),
    "*herd' 'links'*" => Process::result(''),
    "*herd' 'paths'*" => Process::result(json_encode([$served ? dirname($context->basePath) : '/somewhere/else'], JSON_THROW_ON_ERROR)),
    "*herd' 'secured'*" => Process::result($secured),
];

/** A herd stub in a home inside the sandbox (removed with it), so the wizard sees Herd; every herd command stays faked. */
$withHerd = function (SetupContext $context): void {
    $herd = $context->path('home/Library/Application Support/Herd/bin/herd');
    File::ensureDirectoryExists(dirname($herd));
    File::put($herd, "#!/bin/sh\n");
    chmod($herd, 0755);
    app()->instance(Toolchain::class, new Toolchain($context->path('home'), $context->basePath));
};

it('reports running until the Herd step has a result, then done (RL-6)', function () use ($afterStepNine): void {
    $secret = $afterStepNine(useSetupWizard());

    $this->getJson('/welcome-setup/herd-status?secret='.$secret)->assertOk()->assertExactJson(['state' => 'running']);

    $state = HerdState::load(app(SetupContext::class)->basePath) ?? throw new LogicException('No Herd state.');
    $state->result = StepResult::skipped('Herd is not installed');
    $state->save(app(SetupContext::class)->basePath);

    $this->getJson('/welcome-setup/herd-status?secret='.$secret)->assertExactJson(['state' => 'done']);
});

it('answers 404 to a wrong or stale secret, or behind a proxy (RL-6)', function () use ($afterStepNine): void {
    $secret = $afterStepNine(useSetupWizard());

    $this->getJson('/welcome-setup/herd-status?secret='.str_repeat('0', 64))->assertNotFound();
    $this->getJson('/welcome-setup/herd-status?secret='.$secret, ['X-Forwarded-For' => '1.2.3.4'])->assertNotFound();

    $this->travel(31)->minutes();

    $this->getJson('/welcome-setup/herd-status?secret='.$secret)->assertNotFound();
});

it('answers 404 on every Herd-stage endpoint outside the local environment, even with the secret (RL-6)', function (string $endpoint) use ($afterStepNine): void {
    // Arrange: a finished Herd step, so each endpoint would answer locally (status, summary, login).
    $context = useSetupWizard();
    $secret = $afterStepNine($context);
    $state = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    $state->result = StepResult::skipped('Herd is not installed');
    $state->save($context->basePath);
    app()->detectEnvironment(fn (): string => 'production');

    $this->get('/welcome-setup/'.$endpoint.'?secret='.$secret)->assertNotFound();

    expect(HerdState::load($context->basePath))->not->toBeNull();
})->with(['herd-status', 'finish', 'login']);

it('shows a finishing page while the Herd step runs, and the manual command after a timeout (RL-6, RL-7)', function () use ($afterStepNine, $withHerd, $herdDetection, $herdListings): void {
    // Arrange: Herd serves the site but has not secured it, so the command left is `herd secure`.
    $context = useSetupWizard();
    $secret = $afterStepNine($context, detection: $herdDetection());
    $withHerd($context);
    Process::fake($herdListings($context, served: true));

    $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()->assertHeader('Refresh', '2');
    $this->get('/welcome-setup/finish?secret='.$secret.'&timeout=1')->assertOk()
        ->assertSee('herd secure '.$context->folderName())
        ->assertSee(__('Herd did not confirm HTTPS in time.'));
});

it('offers herd link, not herd secure, while Herd does not serve the site (RL-7, g3-23)', function (bool $https, string $heading) use ($afterStepNine, $withHerd, $herdDetection, $herdListings): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context, detection: $herdDetection(), https: $https);
    $withHerd($context);
    Process::fake($herdListings($context, served: false));

    $this->get('/welcome-setup/finish?secret='.$secret.'&timeout=1')->assertOk()
        ->assertSee('data-test="setup-manual-herd"', false)
        ->assertSee('herd link '.$context->folderName())
        ->assertDontSee('herd secure')
        ->assertSee(__($heading));
})->with([
    'HTTPS chosen' => [true, 'Herd did not confirm HTTPS in time.'],
    'no HTTPS' => [false, 'Herd did not confirm the site in time.'],
]);

it('shows no command on the manual page without Herd (RL-7)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context);

    $this->get('/welcome-setup/finish?secret='.$secret.'&timeout=1')->assertOk()
        ->assertSee('data-test="setup-manual-herd"', false)
        ->assertDontSee('herd secure')
        ->assertDontSee('<code', false);
});

it('words the manual page for the choice made and the command left, never promising what does not happen (RL-7, M5)', function (bool $https, bool $herd, string $sentence, string $button) use ($afterStepNine, $withHerd, $herdDetection, $herdListings): void {
    // Arrange: with Herd, it does not serve the site, so `herd link` is left; without Herd there is no command.
    $context = useSetupWizard();
    $secret = $afterStepNine($context, detection: $herd ? $herdDetection() : null, https: $https);

    if ($herd) {
        $withHerd($context);
        Process::fake($herdListings($context, served: false));
    }

    $html = (string) $this->get('/welcome-setup/finish?secret='.$secret.'&timeout=1')->assertOk()->getContent();
    $continue = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('[data-test="setup-continue-without-https"]');

    expect($html)->toContain(e(__($sentence)))
        ->and(trim((string) $continue?->textContent))->toBe(__($button));
})->with([
    'HTTPS chosen, a command left' => [true, true, 'Run it in a terminal, then reload this page, or continue without HTTPS (passkeys are then switched off).', 'Continue without HTTPS'],
    'no HTTPS, a command left' => [false, true, 'Run it in a terminal, then reload this page, or continue and run it later.', 'Continue'],
    'HTTPS chosen, no command' => [true, false, 'There is no Herd command to run. Continue without HTTPS (passkeys are then switched off).', 'Continue without HTTPS'],
    'no HTTPS, no command' => [false, false, 'There is no Herd command to run. Continue to finish setting up.', 'Continue'],
]);

it('is not overdue at the start when an earlier stage left its exit file (g3-24)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    File::put($context->path(HerdState::EXIT), "0\n");

    $secret = $afterStepNine($context);

    $this->getJson('/welcome-setup/herd-status?secret='.$secret)->assertExactJson(['state' => 'running']);
});

it('offers the manual page when the Herd step cannot start, instead of settling at once (g3-22)', function () use ($withHerd, $herdDetection, $herdListings): void {
    // Arrange: steps 1–8 are done under Herd, which serves the site without securing it.
    $context = useSetupWizard();
    $withHerd($context);
    Process::fake($herdListings($context, served: true));
    $answers = Answers::fromArray(['modules' => ['impersonation'], 'https' => true], $herdDetection(), $context->folderName());
    new WizardState('localhost', $answers, $herdDetection(), array_fill_keys(WizardRunner::PAGE_STEPS, StepResult::done('ok')))->save($context->basePath);
    /** @var InlineStepLauncher $launcher */
    $launcher = app(StepLauncher::class);
    $launcher->refuses = true;

    $secret = app(WizardRunner::class)->finish();

    expect(HerdState::load($context->basePath)?->result)->toBeNull()
        ->and(Marker::read($context)['answers']['https'] ?? null)->toBeTrue();
    $this->getJson('/welcome-setup/herd-status?secret='.$secret)->assertExactJson(['state' => 'timeout']);
    $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()
        ->assertSee('data-test="setup-manual-herd"', false)
        ->assertSee('herd secure '.$context->folderName());
});

it('reports a timeout and shows the manual command once the Herd step exited without a result (§5.3.4, RL-6)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context);
    File::put($context->path(HerdState::EXIT), "255\n");

    $this->getJson('/welcome-setup/herd-status?secret='.$secret)->assertExactJson(['state' => 'timeout']);
    $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()->assertHeaderMissing('Refresh')
        ->assertSee('data-test="setup-manual-herd"', false);
});

it('shows the manual command instead of the finishing page once the poll window has passed (§5.3.4, RL-6)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context);

    $this->travel(HerdState::POLL_SECONDS + 1)->seconds();

    $this->getJson('/welcome-setup/herd-status?secret='.$secret)->assertExactJson(['state' => 'timeout']);
    $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()->assertHeaderMissing('Refresh')
        ->assertSee('data-test="setup-manual-herd"', false);
});

it('settles a site secured by hand once herd secured lists it, when the manual page is reloaded (§5.3.4, RL-6)', function () use ($afterStepNine, $withHerd, $herdDetection): void {
    $context = useSetupWizard();
    $host = $context->folderName().'.test';
    $secret = $afterStepNine($context, 'demo', $host, $herdDetection($host));
    config(['tenancy.apex_domain' => $host]);
    $withHerd($context);
    Process::fake([
        "*herd' 'services:list'*" => Process::result(''),
        "*herd' 'links'*" => Process::result(''),
        "*herd' 'paths'*" => Process::result(json_encode([dirname($context->basePath)], JSON_THROW_ON_ERROR)),
        // Not secured when the poll timed out; listed after the user ran `herd secure` by hand.
        "*herd' 'secured'*" => Process::sequence()->push(Process::result(''))
            ->whenEmpty(Process::result("| Site | Expires |\n| {$context->folderName()}.test | 2027-01-01 00:00:00 GMT |\n")),
    ]);
    $this->travel(HerdState::POLL_SECONDS + 1)->seconds();

    $this->get('http://'.$host.'/welcome-setup/finish?secret='.$secret.'&timeout=1')->assertSee('data-test="setup-manual-herd"', false);
    $this->get('http://'.$host.'/welcome-setup/finish?secret='.$secret)->assertOk()->assertSee('data-test="setup-summary"', false);

    $state = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    expect($state->result?->message)->toStartWith(HerdSiteStep::SECURED)
        ->and(new EnvFile($context->path('.env'))->get('APP_URL'))->toBe('https://'.$context->folderName().'.test')
        ->and(Marker::read($context)['answers']['https'] ?? null)->toBeTrue();
    // Only Herd's listings ran in the web request: never link, isolate or secure.
    Process::assertDidntRun(fn ($process): bool => preg_match("/herd' '(link|isolate|secure)'/", implode(' ', (array) $process->command)) === 1);
});

it('keeps Boost\'s browser logger off the setup pages, whose URLs carry the secret (RL-6)', function () use ($afterStepNine): void {
    $secret = $afterStepNine(useSetupWizard());
    $page = $this->get('/welcome-setup/finish?secret='.$secret.'&timeout=1')->assertOk()->baseResponse;

    $served = new InjectBoost()->handle(Request::create('/welcome-setup/finish'), fn () => $page);

    expect((string) $served->getContent())->not->toContain('Browser logger active');
});

it('continues without HTTPS: APP_URL stays http and the marker says so (OD-2, RL-6)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context);

    $this->get('/welcome-setup/finish?secret='.$secret.'&without_https=1')->assertOk()->assertSee('data-test="setup-summary"', false);

    expect(Marker::read($context)['answers']['https'] ?? null)->toBeFalse();
});

it('keeps the first answer when Herd finished while the user chose (RL-6)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context);
    $state = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    $state->result = StepResult::skipped('Herd is not installed');
    $state->save($context->basePath);

    $this->get('/welcome-setup/finish?secret='.$secret.'&without_https=1')->assertOk()->assertSee('data-test="setup-summary"', false);

    expect(Marker::read($context)['answers']['https'] ?? null)->toBeTrue();
});

it('signs the new super admin in once through a handoff to the admin host (RL-6)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context, 'super_admin');
    User::factory()->superAdmin()->create(['email' => 'owner@example.com']);

    // No login while the Herd step runs: the handoff would land on an origin that is about to change.
    $this->get('/welcome-setup/login?secret='.$secret)->assertNotFound();

    $state = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    $state->result = StepResult::skipped('Herd is not installed');
    $state->save($context->basePath);

    $location = (string) $this->get('/welcome-setup/login?secret='.$secret)->assertRedirect()->headers->get('Location');

    expect($location)->toMatch('#^'.preg_quote(Hosts::url(Hosts::admin(), '/auth/handoff/'), '#').'[A-Za-z0-9]{64}$#')
        ->and(is_file($context->path(HerdState::PATH)))->toBeFalse();

    $this->get('/welcome-setup/login?secret='.$secret)->assertNotFound();
});

it('issues no handoff for an account that is no super admin (RL-6)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context, 'super_admin');
    User::factory()->create(['email' => 'owner@example.com']);
    $state = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    $state->result = StepResult::skipped('Herd is not installed');
    $state->save($context->basePath);

    $this->get('/welcome-setup/login?secret='.$secret)->assertNotFound();

    expect(HandoffToken::query()->count())->toBe(0);
});

it('sends a demo install to the login page (RL-6)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context);
    $state = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    $state->result = StepResult::skipped('Herd is not installed');
    $state->save($context->basePath);

    $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()->assertSee('data-test="setup-summary"', false)->assertSee('password')
        ->assertSee('data-test="setup-next-steps"', false)->assertSee('composer dev');
    $this->get('/welcome-setup/login?secret='.$secret)->assertRedirect(Hosts::url(Hosts::apex(), '/login'));
});

it('issues one handoff when two logins arrive at once (RL-6)', function () use ($afterStepNine): void {
    // Arrange: both requests loaded the state; the first one used the secret up before the second claims it.
    $context = useSetupWizard();
    $afterStepNine($context, 'super_admin');
    User::factory()->superAdmin()->create(['email' => 'owner@example.com']);
    $state = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    $state->result = StepResult::skipped('Herd is not installed');
    $state->save($context->basePath);
    app(HerdStage::class)->loginUrl($state);

    expect(fn () => app(HerdStage::class)->loginUrl($state))->toThrow(HttpException::class)
        ->and(HandoffToken::query()->count())->toBe(1);
});

it('shows the finish page without a summary when the marker no longer reads back (g3-06)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context);
    $state = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    $state->result = StepResult::skipped('Herd is not installed');
    $state->save($context->basePath);
    File::put($context->path(Marker::FILE), 'null');

    $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()
        ->assertSee('data-test="setup-summary"', false)
        ->assertSee('data-test="setup-login"', false)
        ->assertDontSee('data-test="setup-next-steps"', false);
});

it('answers 404 on a host other than the pinned one or its admin host (RL-5, g3-25)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context);
    // An organisation, so the tenant resolver lets its host through to the Herd stage.
    Organisation::factory()->create(['slug' => 'acme']);

    $this->getJson(Hosts::url(Hosts::admin(), '/welcome-setup/herd-status?secret='.$secret))->assertOk();
    $this->getJson(Hosts::url(Hosts::tenant('acme'), '/welcome-setup/herd-status?secret='.$secret))->assertNotFound();
    $this->get(Hosts::url(Hosts::tenant('acme'), '/welcome-setup/finish?secret='.$secret))->assertNotFound();
});

it('answers 404 to a Herd-stage request from another machine (RL-4, g4-01)', function () use ($afterStepNine): void {
    $secret = $afterStepNine(useSetupWizard());

    $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])
        ->getJson('/welcome-setup/herd-status?secret='.$secret)->assertNotFound();
});

it('sends Referrer-Policy: no-referrer from every Herd-stage endpoint, whose URLs carry the secret (g3-13)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context);

    $this->getJson('/welcome-setup/herd-status?secret='.$secret)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
    $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');

    $state = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    $state->result = StepResult::skipped('Herd is not installed');
    $state->save($context->basePath);

    $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
    $this->get('/welcome-setup/login?secret='.$secret)->assertRedirect()->assertHeader('Referrer-Policy', 'no-referrer');
});

it('never stores the secret in the session, not even as the previous URL (g3-27, M21)', function (string $endpoint) use ($afterStepNine): void {
    // Arrange: a finished Herd step, so every endpoint answers; get(), not getJson(): the page's fetch polls send no
    // X-Requested-With, so StartSession stores their URL as the previous one.
    $context = useSetupWizard();
    $secret = $afterStepNine($context);
    $state = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    $state->result = StepResult::skipped('Herd is not installed');
    $state->save($context->basePath);

    $response = $this->get('/welcome-setup/'.$endpoint.'?secret='.$secret);

    expect($response->status())->toBeLessThan(400)
        ->and((string) session()->previousUrl())->not->toContain($secret)
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain($secret);
})->with(['herd-status', 'finish', 'login']);

it('sends a super admin with a confirmed second factor to the login page, not through a handoff (CD-14, g4-03)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context, 'super_admin');
    User::factory()->superAdmin()->withTwoFactor()->create(['email' => 'owner@example.com']);
    $state = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    $state->result = StepResult::skipped('Herd is not installed');
    $state->save($context->basePath);

    $this->get('/welcome-setup/login?secret='.$secret)->assertRedirect(Hosts::url(Hosts::apex(), '/login'));

    expect(HandoffToken::query()->count())->toBe(0)
        ->and(is_file($context->path(HerdState::PATH)))->toBeFalse();
});

it('leaves no lock file when the Herd stage acts after the login used the secret (g3-02)', function (Closure $late) use ($afterStepNine): void {
    // Arrange: the login deleted the Herd state and the lock; a request that loaded the state before arrives late.
    $context = useSetupWizard();
    $afterStepNine($context);
    $state = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    app(HerdStage::class)->loginUrl($state);

    $late($state);

    expect($context->path(SetupLock::PATH))->not->toBeFile();
})->with([
    'the server-side confirmation' => [fn (HerdState $state): mixed => app(WizardRunner::class)->confirmHerd($state)],
    'continue without HTTPS' => [function (HerdState $state): void {
        app(HerdStage::class)->continueWithoutHttps($state);
    }],
]);

/** What happens to the Herd state between a request's load and its lock (g4-12). */
$movedOn = [
    'the Herd step answered meanwhile' => [function (SetupContext $context): void {
        $current = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
        $current->result = StepResult::skipped('Herd is not installed');
        $current->save($context->basePath);
    }],
    'another finish replaced the state' => [function (SetupContext $context) use ($afterStepNine, $herdDetection): void {
        $afterStepNine($context, detection: $herdDetection());
    }],
];

it('leaves a Herd state that changed after this request loaded it on continue without HTTPS (RL-6, g4-12)', function (Closure $moveOn) use ($afterStepNine, $herdDetection): void {
    $context = useSetupWizard();
    $afterStepNine($context, detection: $herdDetection());
    $stale = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    $moveOn($context);
    $before = (string) file_get_contents($context->path(HerdState::PATH));

    app(HerdStage::class)->continueWithoutHttps($stale);

    expect((string) file_get_contents($context->path(HerdState::PATH)))->toBe($before)
        ->and(Marker::read($context)['answers']['https'] ?? null)->toBeTrue();
})->with($movedOn);

it('leaves a Herd state that changed after this request loaded it on the server-side confirmation (RL-6, g4-12)', function (Closure $moveOn) use ($afterStepNine, $withHerd, $herdDetection, $herdListings): void {
    // Arrange: Herd now serves and secures the site, so a confirmation without its guards would settle the stage.
    $context = useSetupWizard();
    $afterStepNine($context, detection: $herdDetection());
    $withHerd($context);
    Process::fake($herdListings($context, served: true, secured: "| Site | Expires |\n| {$context->folderName()}.test | 2027-01-01 00:00:00 GMT |\n"));
    $stale = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    $moveOn($context);
    $before = (string) file_get_contents($context->path(HerdState::PATH));

    app(WizardRunner::class)->confirmHerd($stale);

    expect((string) file_get_contents($context->path(HerdState::PATH)))->toBe($before)
        ->and(new EnvFile($context->path('.env'))->get('APP_URL'))->not->toStartWith('https://');
})->with($movedOn);

it('renders the Herd-stage pages without a Vite manifest (§5.3.4, M55)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context);
    Vite::useManifestFilename('missing-'.Str::uuid().'.json');

    $finishing = $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()->assertHeader('Refresh', '2');
    $manual = $this->get('/welcome-setup/finish?secret='.$secret.'&timeout=1')->assertOk()->assertHeaderMissing('Refresh');
    $state = HerdState::load($context->basePath) ?? throw new LogicException('No Herd state.');
    $state->result = StepResult::skipped('Herd is not installed');
    $state->save($context->basePath);
    $summary = $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()->assertSee('data-test="setup-summary"', false);

    foreach ([$finishing, $manual, $summary] as $response) {
        expect((string) $response->getContent())->not->toContain('/build/');
    }
});
