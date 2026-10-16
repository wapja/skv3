<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Enums\CheckStatus;
use App\Http\Middleware\ThrottleHealthChecks;
use App\Setup\Doctor\Check;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Support\Toolchain;
use App\Support\Health\HealthCheckKey;
use App\Support\Health\HealthReport;
use App\Support\Health\OpsCache;
use App\Tenancy\Hosts;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\FixedStatusCheck;
use Tests\Fixtures\UnwritableCacheStore;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $this->sandbox = setupSandbox();
    app()->instance(Toolchain::class, new Toolchain($this->sandbox->path('home')));
    // RL-2: forApplication() starts herd processes and reads this repository's .env; web code never resolves it.
    app()->bind(function (): DoctorContext {
        throw new LogicException('Web code builds its doctor context with DoctorContext::withoutDetection().');
    });
    app()->instance(HealthReport::class, new HealthReport([new FixedStatusCheck(CheckStatus::Pass, 'database')], $this->sandbox->basePath));
    // 40 characters, as setup writes it: CD-4 refuses a key shorter than 32.
    $this->key = 'a-long-random-health-check-key-for-tests';
    config(['skv.health_check.key' => $this->key]);
});

it('answers 403 to a missing or wrong key, and never echoes it (§4.9)', function (?string $header): void {
    $response = $this->withHeaders($header === null ? [] : [HealthCheckKey::HEADER => $header])->getJson('/health-check');

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

    $this->withHeaders([HealthCheckKey::HEADER => ''])->getJson('/health-check')->assertForbidden();
})->with(['empty' => '', 'unset' => null]);

it('never accepts a configured key that env() cast from HEALTH_CHECK_KEY=true (§4.9)', function (): void {
    config(['skv.health_check.key' => true]);

    $this->withHeaders([HealthCheckKey::HEADER => '1'])->getJson('/health-check')->assertForbidden();
});

it('treats a configured key shorter than 32 characters as no key, even when the header matches it (CD-4, F15)', function (string $configured, int $status): void {
    config(['skv.health_check.key' => $configured]);

    $this->withHeaders([HealthCheckKey::HEADER => $configured])->getJson('/health-check')->assertStatus($status);
})->with([
    'a word' => ['monitor', 403],
    'one short of the minimum' => [str_repeat('k', HealthCheckKey::MIN_LENGTH - 1), 403],
    'the minimum' => [str_repeat('k', HealthCheckKey::MIN_LENGTH), 200],
]);

it('answers 200 with the cached report when every check passes (§4.9, RL-3)', function (): void {
    $response = $this->withHeaders([HealthCheckKey::HEADER => $this->key])->getJson('/health-check');

    $response->assertOk()
        ->assertJsonPath('healthy', true)
        ->assertJsonPath('checks.database.status', 'pass')
        ->assertJsonStructure(['checked_at', 'healthy', 'checks' => ['database' => ['label', 'status', 'message']]]);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('answers 503 when a check fails (§4.9)', function (): void {
    app()->instance(HealthReport::class, new HealthReport([new FixedStatusCheck(CheckStatus::Fail, 'database')], $this->sandbox->basePath));

    $this->withHeaders([HealthCheckKey::HEADER => $this->key])->getJson('/health-check')
        ->assertStatus(503)
        ->assertJsonPath('healthy', false);
});

it('resolves the kit\'s own health report from the container (L-26)', function (): void {
    app()->forgetInstance(HealthReport::class);

    $checks = app(HealthReport::class)->checks();

    expect(array_map(fn (Check $check): string => $check->key(), $checks))->toBe(['database', 'queue', 'failed-jobs', 'mail', 'last-backup']);
});

it('runs outside the web group: no session, no cookies, no tenant (RL-5)', function (): void {
    $route = Route::getRoutes()->getByName('health-check');
    $onUnknownTenant = $this->withHeaders([HealthCheckKey::HEADER => $this->key])
        ->getJson('http://no-such-organisation.'.Hosts::apex().'/health-check');

    expect($route?->gatherMiddleware())->toBe([ThrottleHealthChecks::class])
        ->and($onUnknownTenant->status())->toBe(200)
        ->and($onUnknownTenant->headers->getCookies())->toBe([]);
});

it('throttles the configured key to 60 requests a minute, whatever the address, with a no-store 429 (CD-4, L-27)', function (): void {
    $this->withHeaders([HealthCheckKey::HEADER => $this->key]);

    // Literals, not the constants: the title promises 60, so a changed limit fails here (M29).
    for ($i = 1; $i <= 60; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i])->getJson('/health-check')->assertOk();
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->getJson('/health-check');

    $response->assertStatus(429)->assertExactJson(['message' => 'Too Many Attempts.']);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('counts requests without the key in one shared bucket, never one per address (CD-4, F14)', function (): void {
    $limiter = new RateLimiter(OpsCache::store());

    foreach (range(1, 20) as $host) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$host])->getJson('/health-check')->assertForbidden();
    }

    expect($limiter->attempts(ThrottleHealthChecks::DENIED_BUCKET))->toBe(20)
        ->and(array_filter(range(1, 20), fn (int $host): bool => $limiter->attempts('health-check:203.0.113.'.$host) !== 0))->toBe([]);
});

it('answers 429 to wrong keys once 300 came in a minute, and still lets the monitor in (CD-4, L-28, L-29)', function (): void {
    $limiter = new RateLimiter(OpsCache::store());

    for ($i = 1; $i < 300; $i++) {
        $limiter->hit(ThrottleHealthChecks::DENIED_BUCKET, 60);
    }

    $last = $this->withHeaders([HealthCheckKey::HEADER => 'not-the-key'])->getJson('/health-check');
    $over = $this->withHeaders([HealthCheckKey::HEADER => 'not-the-key'])->getJson('/health-check');
    $monitor = $this->withHeaders([HealthCheckKey::HEADER => $this->key])->getJson('/health-check');

    $last->assertForbidden();
    $over->assertStatus(429)->assertExactJson(['message' => 'Too Many Attempts.']);
    expect($over->headers->get('Cache-Control'))->toContain('no-store');
    $monitor->assertOk();
});

it('answers 503 with JSON, never a 500, while the ops cache store is broken (CD-5)', function (): void {
    Exceptions::fake();
    config(['skv.ops_cache_store' => 'no-such-store']);

    // No Accept: application/json, as a plain uptime monitor sends it.
    $response = $this->withHeaders([HealthCheckKey::HEADER => $this->key])->get('/health-check');

    $response->assertStatus(503)->assertExactJson(['healthy' => false, 'message' => 'The health report is unavailable.']);
    expect($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    Exceptions::assertReported(InvalidArgumentException::class);
});

it('still answers 403 to a wrong key while the ops cache store is broken (CD-5)', function (): void {
    Exceptions::fake();
    config(['skv.ops_cache_store' => 'no-such-store']);

    $this->withHeaders([HealthCheckKey::HEADER => 'not-the-key'])->get('/health-check')
        ->assertForbidden()
        ->assertExactJson(['message' => 'Forbidden.']);
});

it('answers with the fresh report while the ops store reads but cannot be written, and reports the failed write (L-30)', function (): void {
    Exceptions::fake();
    Cache::extend('unwritable', fn (): Repository => Cache::repository(new UnwritableCacheStore));
    config(['skv.ops_cache_store' => 'unwritable']);
    config(['cache.stores.unwritable' => ['driver' => 'unwritable']]);

    // Without the throttle, which reports its own failed write: the report must be HealthReport::refresh()'s (M34).
    $this->withoutMiddleware(ThrottleHealthChecks::class)
        ->withHeaders([HealthCheckKey::HEADER => $this->key])->getJson('/health-check')
        ->assertOk()
        ->assertJsonPath('healthy', true)
        ->assertJsonPath('checks.database.status', 'pass');
    Exceptions::assertReported(RuntimeException::class);
});

it('answers /up on the apex, the admin host and an organisation host, without a cookie (F9)', function (string $kind): void {
    // Host kinds, not closures: Rector turns `fn () => Hosts::apex()` into a static `Hosts::apex(...)`, which Pest cannot bind.
    $host = match ($kind) {
        'apex' => Hosts::apex(),
        'admin host' => Hosts::admin(),
        default => Hosts::tenant('acme'),
    };
    $response = $this->get(Hosts::url($host, '/up'));

    $response->assertOk();
    expect($response->headers->getCookies())->toBe([]);
})->with(['apex', 'admin host', 'organisation host']);

it('answers 500 on /up when a health listener fails (F9)', function (): void {
    Exceptions::fake();
    config(['app.debug' => false]);
    Event::listen(DiagnosingHealth::class, function (): never {
        throw new RuntimeException('The cache is down.');
    });

    $this->get(Hosts::url(Hosts::apex(), '/up'))->assertStatus(500);
    Exceptions::assertReported(RuntimeException::class);
});
