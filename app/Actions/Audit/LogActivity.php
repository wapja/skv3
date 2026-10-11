<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\Organisation;
use App\Models\User;
use App\Tenancy\Auth\Impersonation;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Actions\LogActivityAction;

/**
 * Enriches every entry before it is saved (§4.8): the organisation tag (RL-5) and, for a routed web
 * request only, the IP address and user agent. Registered as activitylog.actions.log_activity.
 *
 * Host mode (authentication, security, impersonation): the bound tenant, else central. Subject mode
 * (everything else): the explicit organisation, then the subject's, then the bound tenant, else central.
 */
final class LogActivity extends LogActivityAction
{
    protected function beforeActivityLogged(Model $activity): void
    {
        parent::beforeActivityLogged($activity);

        $activity->forceFill(['organisation_id' => $this->organisationIdFor($activity)]);

        $request = request();

        if ($request->route() !== null) {
            $activity->forceFill([
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        $this->rewriteImpersonatedCauser($activity);
    }

    private function organisationIdFor(Model $activity): ?int
    {
        $boundId = app(CurrentTenant::class)->current()?->id;

        if (ActivityEvent::tryFrom((string) $activity->getAttribute('event'))?->tagsByHost() === true) {
            return $boundId;
        }

        $explicit = $activity->getAttribute('organisation_id');

        if ($explicit !== null) {
            return (int) $explicit;
        }

        return $this->subjectOrganisationId($activity) ?? $boundId;
    }

    /**
     * While this session impersonates, an entry caused by the impersonated user is caused by the real user
     * and records the impersonated user's id as impersonated_as (§4.7, IMP-6). Another explicit causer is
     * kept. Independent of the module flag, like Impersonation::isActive() (RK-I3).
     */
    private function rewriteImpersonatedCauser(Model $activity): void
    {
        if (! $activity instanceof Activity || ! Impersonation::isActive()) {
            return;
        }

        $targetId = Impersonation::targetId();

        if ($targetId === null
            || $activity->causer_type !== (new User)->getMorphClass()
            || $activity->causer_id !== $targetId) {
            return;
        }

        $impersonator = Impersonation::impersonator();

        if (! $impersonator instanceof User) {
            return;
        }

        $activity->causer()->associate($impersonator);
        $activity->properties = collect([...($activity->properties?->all() ?? []), 'impersonated_as' => $targetId]);
    }

    /** The subject was associated by RecordActivity, so reading it never queries. */
    private function subjectOrganisationId(Model $activity): ?int
    {
        $subject = $activity->relationLoaded('subject') ? $activity->getRelation('subject') : null;

        if ($subject instanceof Organisation) {
            return $subject->id;
        }

        // Spatie roles carry team_id instead: role events pass their organisation explicitly.
        if ($subject instanceof Model && array_key_exists('organisation_id', $subject->getAttributes())) {
            $organisationId = $subject->getAttribute('organisation_id');

            return $organisationId === null ? null : (int) $organisationId;
        }

        return null;
    }
}
