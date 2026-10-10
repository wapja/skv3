<?php

declare(strict_types=1);

/** covers §6.2 */

use App\Models\Organisation;
use App\Tenancy\Facades\Tenant;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\RecordTenantJob;

beforeEach(function (): void {
    config(['queue.default' => 'database']);
});

/** @return array<string, mixed> */
function lastQueuedPayload(): array
{
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) DB::table('jobs')->latest('id')->value('payload'), true, flags: JSON_THROW_ON_ERROR);

    return $payload;
}

function workOneJob(): void
{
    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true, '--tries' => 3]);
}

it('adds the current tenant to every queued payload', function (): void {
    $organisation = Organisation::factory()->create();

    Tenant::run($organisation, function (): void {
        Bus::dispatch(new RecordTenantJob);
    });

    expect(lastQueuedPayload()['tenant_id'])->toBe($organisation->id);
});

it('queues central work without a tenant', function (): void {
    Bus::dispatch(new RecordTenantJob);

    expect(lastQueuedPayload()['tenant_id'])->toBeNull();
});

it('binds the tenant and the team while the worker runs the job', function (): void {
    $organisation = Organisation::factory()->create();
    Tenant::run($organisation, function (): void {
        Bus::dispatch(new RecordTenantJob);
    });

    workOneJob();

    expect(Cache::get('job.tenant'))->toBe($organisation->id)
        ->and(Cache::get('job.team'))->toBe($organisation->id)
        ->and(Tenant::check())->toBeFalse();
});

it('fails the job without retry when the organisation is gone', function (): void {
    $organisation = Organisation::factory()->create();
    Tenant::run($organisation, function (): void {
        Bus::dispatch(new RecordTenantJob);
    });
    $organisation->delete();

    workOneJob();

    expect(Cache::has('job.ran'))->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});

it('restores the request tenant after a synchronous job', function (): void {
    config(['queue.default' => 'sync']);
    $organisation = Organisation::factory()->create();
    Tenant::set($organisation);

    Bus::dispatch(new RecordTenantJob);

    expect(Cache::get('job.tenant'))->toBe($organisation->id)
        ->and(Tenant::current()?->id)->toBe($organisation->id);
});

it('queues a job returned from Tenant::run() under that tenant', function (): void {
    $organisation = Organisation::factory()->create();

    Tenant::run($organisation, fn (): PendingDispatch => dispatch(new RecordTenantJob));

    expect(lastQueuedPayload()['tenant_id'])->toBe($organisation->id);
});
