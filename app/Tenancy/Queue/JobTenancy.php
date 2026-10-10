<?php

declare(strict_types=1);

namespace App\Tenancy\Queue;

use App\Authorization\PermissionsTeam;
use App\Models\Organisation;
use App\Tenancy\CentralContext;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Queue tenant context (§6.2). JobProcessing runs before the command is unserialized, so model
 * restoring already happens under the job's tenant. A missing or archived organisation fails
 * the job without retry. A payload without tenant_id runs in the central context on every
 * driver (sync included). A job with a tenant suspends any surrounding bypass, because the
 * innermost context wins. Afterwards the previous tenant, bypass and Spatie team are restored
 * (not just forgotten), so a synchronous job inside a request leaves the request's context in
 * place. end() is idempotent per job because a failing job raises more than one of the end
 * events.
 */
final class JobTenancy
{
    /** @var array<int, array{tenant: Organisation|null, team: int|null, bypass: int|null, central: bool}> state per job object */
    private array $previous = [];

    public function begin(JobProcessing $event): void
    {
        $tenant = app(CurrentTenant::class);
        $key = spl_object_id($event->job);
        $this->previous[$key] = ['tenant' => $tenant->current(), 'team' => PermissionsTeam::current(), 'bypass' => null, 'central' => false];

        $tenantId = $event->job->payload()['tenant_id'] ?? null;

        if ($tenantId === null) {
            $tenant->forget();
            app(CentralContext::class)->enter();
            $this->previous[$key]['central'] = true;

            return;
        }

        $organisation = Organisation::query()->find((int) $tenantId);

        if ($organisation === null) {
            $exception = TenantNotResolvedException::forJob((int) $tenantId);
            $event->job->fail($exception);

            throw $exception;
        }

        $tenant->set($organisation);
        $this->previous[$key]['bypass'] = $tenant->suspendBypass();
    }

    public function end(JobProcessed|JobFailed|JobExceptionOccurred $event): void
    {
        $key = spl_object_id($event->job);

        if (! array_key_exists($key, $this->previous)) {
            return;
        }

        $state = $this->previous[$key];
        unset($this->previous[$key]);

        $tenant = app(CurrentTenant::class);

        if ($state['central']) {
            app(CentralContext::class)->leave();
        }

        if ($state['bypass'] !== null) {
            $tenant->resumeBypass($state['bypass']);
        }

        if ($state['tenant'] instanceof Organisation) {
            $tenant->set($state['tenant']);
        } else {
            $tenant->forget();
        }

        PermissionsTeam::set($state['team']);
    }
}
