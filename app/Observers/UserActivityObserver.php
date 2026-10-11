<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\User;

/**
 * Records user.updated with old and new values when a user's name or email address changes (§4.4, §4.8,
 * RL-6), whoever saved it: the admin edit page, the profile page or the email-change link. Passwords,
 * remember tokens and two-factor columns are never recorded; status, roles, super admin, deletion and
 * restore have their own events. A quiet save records nothing, for writers that record their own event.
 */
final readonly class UserActivityObserver
{
    private const array AUDITED = ['name', 'email'];

    public function __construct(private RecordActivity $recordActivity) {}

    /** During "updated" the original still holds the old values; it is synced after "saved". */
    public function updated(User $user): void
    {
        $old = [];
        $new = [];

        foreach (self::AUDITED as $field) {
            if ($user->wasChanged($field)) {
                $old[$field] = $user->getOriginal($field);
                $new[$field] = $user->getAttribute($field);
            }
        }

        if ($new === []) {
            return;
        }

        $this->recordActivity->handle(ActivityEvent::UserUpdated, $user, changes: ['old' => $old, 'attributes' => $new]);
    }
}
