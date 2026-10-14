<?php

declare(strict_types=1);

/** covers §5.3.4, §6.8, §7.2 */

use App\Models\HandoffToken;
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
use App\Setup\Wizard\HerdState;
use App\Tenancy\Hosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Boost\Middleware\InjectBoost;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
});

/** The state finish() leaves: the marker, and a Herd state; returns the secret. */
$afterStepNine = function (SetupContext $context, string $startWith = 'demo', string $host = 'localhost'): string {
    $context->answers = Answers::fromArray(
        $startWith === 'demo' ? ['modules' => ['impersonation'], 'https' => true] : ['start_with' => 'super_admin', 'super_admin_email' => 'owner@example.com', 'super_admin_password' => 'correct-horse-battery', 'modules' => ['impersonation'], 'https' => true],
        Detection::none(),
        $context->folderName(),
    );
    Marker::write($context, Summary::build($context));
    @unlink(new SetupToken($context->basePath)->path());

    return HerdState::begin($context->basePath, $context, $host)[1];
};

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

it('shows a finishing page while the Herd step runs, and the manual command after a timeout (RL-6)', function () use ($afterStepNine): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context);

    $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()->assertHeader('Refresh', '2');
    $this->get('/welcome-setup/finish?secret='.$secret.'&timeout=1')->assertOk()
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

it('settles a site secured by hand once herd secured lists it, when the manual page is reloaded (§5.3.4, RL-6)', function () use ($afterStepNine, $withHerd): void {
    $context = useSetupWizard();
    $secret = $afterStepNine($context, 'demo', $context->folderName().'.test');
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

    $this->get('/welcome-setup/finish?secret='.$secret.'&timeout=1')->assertSee('data-test="setup-manual-herd"', false);
    $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()->assertSee('data-test="setup-summary"', false);

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

    $this->get('/welcome-setup/finish?secret='.$secret)->assertOk()->assertSee('data-test="setup-summary"', false)->assertSee('password');
    $this->get('/welcome-setup/login?secret='.$secret)->assertRedirect(Hosts::url(Hosts::apex(), '/login'));
});
