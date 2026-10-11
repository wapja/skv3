<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Tenancy\Auth\Impersonation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/**
 * Starts impersonating $target in the signed-in actor's session (§4.7): a reason (IMP-1), the policy
 * (module on, depth 1, the matrix), the swap, then impersonation.start with the reason. Logged after the
 * swap, so the causer rewrite records the actor with impersonated_as.
 */
final readonly class StartImpersonation
{
    public const int MAX_REASON_LENGTH = 500;

    public function __construct(private RecordActivity $recordActivity) {}

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(User $actor, User $target, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw ValidationException::withMessages(['reason' => __('Give a reason of at most 500 characters.')]);
        }

        Gate::forUser($actor)->authorize('impersonate', $target);

        if (Auth::guard('web')->id() !== $actor->id) {
            throw new LogicException('Only the signed-in user can start an impersonation.');
        }

        Impersonation::enter($actor, $target);

        try {
            $this->recordActivity->handle(ActivityEvent::ImpersonationStarted, $target, ['reason' => $reason]);
        } catch (Throwable $exception) {
            // No unlogged impersonation (§4.8).
            Impersonation::leave();

            throw $exception;
        }
    }
}
