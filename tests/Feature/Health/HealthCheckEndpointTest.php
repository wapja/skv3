<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Enums\CheckStatus;
use App\Http\Middleware\ThrottleHealthChecks;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Support\Toolchain;
use App\Support\Health\HealthReport;
use App\Tenancy\Hosts;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\FixedStatusCheck;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $this->sandbox = setupSandbox();
    app()->instance(Toolchain::class, new Toolchain($this->sandbox->path('home')));
    // RL-2: forApplication() starts herd processes and reads this repository's .env; web code never resolves it.
    app()->bind(DoctorContext::class, function (): never {
        throw new LogicException('Web code builds its doctor context with DoctorContext::withoutDetection().');
    });
    app()->instance(HealthReport::class, new HealthReport([new FixedStatusCheck(CheckStatus::Pass, 'database')], $this->sandbox->basePath));
    config(['skv.health_check.key' => 'a-long-random-health-check-key']);
});

it('answers 403 to a missing or wrong key, and never echoes it (§4.9)', function (?string $header): void {
    $response = $this->withHeaders($header === null ? [] : ['X-Health-Check-Key' => $header])->getJson('/health-check');

    $response->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
    expect((string) $response->getContent())->not->toContain('a-long-random');
})->with([
    'no header' => null,
    'empty header' => '',
    'wrong key' => 'not-the-key',
    'a prefix of the key' => 'a-long-random',
]);

it('always answers 403 while the configured key is blank (§4.9)', function (?string $configured): void {
    config(['skv.health_check.key' => $configured]);

    $this->withHeaders(['X-Health-Check-Key' => ''])->getJson('/health-check')->assertForbidden();
})->with(['empty' => '', 'unset' => null]);

it('never accepts a configured key that env() cast from HEALTH_CHECK_KEY=true (§4.9)', function (): void {
    config(['skv.health_check.key' => true]);

    $this->withHeaders(['X-Health-Check-Key' => '1'])->getJson('/health-check')->assertForbidden();
});

it('answers 200 with the cached report when every check passes (§4.9, RL-3)', function (): void {
    $response = $this->withHeaders(['X-Health-Check-Key' => 'a-long-random-health-check-key'])->getJson('/health-check');

    $response->assertOk()
        ->assertJsonPath('healthy', true)
        ->assertJsonPath('checks.database.status', 'pass')
        ->assertJsonStructure(['checked_at', 'healthy', 'checks' => ['database' => ['label', 'status', 'message']]]);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('answers 503 when a check fails (§4.9)', function (): void {
    app()->instance(HealthReport::class, new HealthReport([new FixedStatusCheck(CheckStatus::Fail, 'database')], $this->sandbox->basePath));

    $this->withHeaders(['X-Health-Check-Key' => 'a-long-random-health-check-key'])->getJson('/health-check')
        ->assertStatus(503)
        ->assertJsonPath('healthy', false);
});

it('runs outside the web group: no session, no cookies, no tenant (RL-5)', function (): void {
    $route = Route::getRoutes()->getByName('health-check');
    $onUnknownTenant = $this->withHeaders(['X-Health-Check-Key' => 'a-long-random-health-check-key'])
        ->getJson('http://no-such-organisation.'.Hosts::apex().'/health-check');

    expect($route?->gatherMiddleware())->toBe([ThrottleHealthChecks::class])
        ->and($onUnknownTenant->status())->toBe(200)
        ->and($onUnknownTenant->headers->getCookies())->toBe([]);
});

it('throttles to 60 requests a minute per address on the ops store (CD-5)', function (): void {
    $headers = ['X-Health-Check-Key' => 'a-long-random-health-check-key'];

    for ($i = 0; $i < ThrottleHealthChecks::PER_MINUTE; $i++) {
        $this->withHeaders($headers)->getJson('/health-check')->assertOk();
    }

    $this->withHeaders($headers)->getJson('/health-check')->assertStatus(429);
});

it('answers 503 with JSON, never a 500, while the ops cache store is broken (CD-5)', function (): void {
    Exceptions::fake();
    config(['skv.ops_cache_store' => 'no-such-store']);

    // No Accept: application/json, as a plain uptime monitor sends it.
    $response = $this->withHeaders(['X-Health-Check-Key' => 'a-long-random-health-check-key'])->get('/health-check');

    $response->assertStatus(503)->assertExactJson(['healthy' => false, 'message' => 'The health report is unavailable.']);
    expect($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    Exceptions::assertReported(InvalidArgumentException::class);
});

it('still answers 403 to a wrong key while the ops cache store is broken (CD-5)', function (): void {
    Exceptions::fake();
    config(['skv.ops_cache_store' => 'no-such-store']);

    $this->withHeaders(['X-Health-Check-Key' => 'not-the-key'])->get('/health-check')
        ->assertForbidden()
        ->assertExactJson(['message' => 'Forbidden.']);
});
