<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Support\Config;

/**
 * The only writer of audit entries (§4.8, RL-6). The event's value is stored as event and description and
 * its group as log name; custom data goes into properties and diffs into attribute_changes. The entry
 * passes through the configured log action (App\Actions\Audit\LogActivity), which tags the organisation
 * and adds the request's IP address and user agent. Never put a password, a token or a link in here.
 */
final class RecordActivity
{
    /**
     * @param  array<string, mixed>  $properties
     * @param  array{attributes?: array<string, mixed>, old?: array<string, mixed>}  $changes
     * @param  User|null  $causer  who did it; null means the signed-in user, unless $defaultCauser is false
     * @param  Organisation|null  $organisation  explicit tag for subject-mode events (RL-5); null means derive it
     */
    public function handle(
        ActivityEvent $event,
        ?Model $subject = null,
        array $properties = [],
        array $changes = [],
        ?User $causer = null,
        ?Organisation $organisation = null,
        bool $defaultCauser = true,
    ): Activity {
        $causer ??= $defaultCauser ? $this->signedInUser() : null;

        $activity = new Activity;
        $activity->forceFill([
            'log_name' => $event->group(),
            'event' => $event->value,
            'properties' => $properties,
            'attribute_changes' => $changes === [] ? null : $changes,
            'organisation_id' => $organisation?->id,
            'subject_type' => null,
            'subject_id' => null,
            'causer_type' => null,
            'causer_id' => null,
        ]);

        if ($subject instanceof Model) {
            $activity->subject()->associate($subject);
        }

        if ($causer instanceof User) {
            $activity->causer()->associate($causer);
        }

        Config::logActivityAction()->execute($activity, $event->value);

        return $activity;
    }

    private function signedInUser(): ?User
    {
        $user = Auth::guard('web')->user();

        return $user instanceof User ? $user : null;
    }
}
