<?php

declare(strict_types=1);

namespace App\Tenancy\Queue;

use App\Models\Organisation;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Queue tenant context (§6.2). JobProcessing runs before the command is unserialized, so model
 * restoring already happens under the job's tenant. A missing or archived organisation fails
 * the job without retry. The previous tenant is restored afterwards (not just forgotten), so a
 * synchronous job inside a request leaves the request's tenant in place. end() is idempotent
 * per job because a failing job raises more than one of the end events.
 */
final class JobTenancy
{
    /** @var array<int, Organisation|null> previous tenant per job object */
    private array $previous = [];

    public function begin(JobProcessing $event): void
    {
        $tenant = app(CurrentTenant::class);
        $this->previous[spl_object_id($event->job)] = $tenant->current();

        $tenantId = $event->job->payload()['tenant_id'] ?? null;

        if ($tenantId === null) {
            $tenant->forget();

            return;
        }

        $organisation = Organisation::query()->find((int) $tenantId);

        if ($organisation === null) {
            $exception = TenantNotResolvedException::forJob((int) $tenantId);
            $event->job->fail($exception);

            throw $exception;
        }

        $tenant->set($organisation);
    }

    public function end(JobProcessed|JobFailed|JobExceptionOccurred $event): void
    {
        $key = spl_object_id($event->job);

        if (! array_key_exists($key, $this->previous)) {
            return;
        }

        $previous = $this->previous[$key];
        unset($this->previous[$key]);

        $tenant = app(CurrentTenant::class);

        if ($previous instanceof Organisation) {
            $tenant->set($previous);
        } else {
            $tenant->forget();
        }
    }
}
