<?php

declare(strict_types=1);

/** covers §4.9 */

use App\Enums\CheckStatus;
use App\Http\Middleware\ThrottleHealthChecks;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Support\Toolchain;
use App\Support\Health\HealthCheckKey;
use App\Support\Health\HealthReport;
use App\Support\Health\OpsCache;
use App\Support\Scheduler\Heartbeat;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tests\Fixtures\FixedStatusCheck;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    $this->sandbox = setupSandbox();
    app()->instance(Toolchain::class, new Toolchain($this->sandbox->path('home')));
    app()->bind(function (): DoctorContext {
        throw new LogicException('Web code builds its doctor context with DoctorContext::withoutDetection().');
    });
    app()->instance(HealthReport::class, new HealthReport([new FixedStatusCheck(CheckStatus::Pass, 'database')], $this->sandbox->basePath));
});

it('keeps the health report, the throttle and the heartbeat on the ops store, never the default store (RL-11, F7)', function (): void {
    // A named store distinct from the default one: the suite's CACHE_STORE and OPS_CACHE_STORE are both `array`.
    $key = str_repeat('k', 40);
    config(['skv.ops_cache_store' => 'ops-test']);
    config(['skv.health_check.key' => $key]);
    config(['cache.stores.ops-test' => ['driver' => 'array']]);
    $bucket = ThrottleHealthChecks::KEY_BUCKET_PREFIX.hash_hmac('sha256', $key, (string) config('app.key'));
    $ops = Cache::store('ops-test');
    $default = Cache::store();

    app(Heartbeat::class)->beat();
    $report = app(HealthReport::class);
    $report->refresh();
    $this->withHeaders([HealthCheckKey::HEADER => $key])->getJson('/health-check')->assertOk();

    expect(OpsCache::store()->getStore())->toBe($ops->getStore())
        ->and($ops->get(Heartbeat::CACHE_KEY))->not->toBeNull()
        ->and($default->get(Heartbeat::CACHE_KEY))->toBeNull()
        ->and($ops->get($report->key()))->not->toBeNull()
        ->and($default->get($report->key()))->toBeNull()
        ->and(new RateLimiter($ops)->attempts($bucket))->toBe(1)
        ->and(new RateLimiter($default)->attempts($bucket))->toBe(0);
});

it('names the ops store in one place only (RL-11, F7)', function (): void {
    expect(filesMatching(kitSourceFiles('app', 'resources/views', 'routes'), '/ops_cache_store/'))->toBe(['app/Support/Health/OpsCache.php']);
});

it('runs the suite on a named array ops store, so no test writes the developer\'s cache (L-23)', function (): void {
    $store = config('skv.ops_cache_store');

    expect($store)->toBe('array')
        ->and(config('cache.stores.'.$store.'.driver'))->toBe('array');
});
