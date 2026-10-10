<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;

/** A handoff link was used; M4's audit log records it (§6.4). */
final readonly class HandoffConsumed
{
    public function __construct(public User $user, public string $host) {}
}
