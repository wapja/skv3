<?php

declare(strict_types=1);

namespace App\Support\RateLimits;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * A per-user limit on a Livewire action (§4.2, CD-3). Every Livewire action shares one update endpoint, so a
 * route limiter cannot tell them apart: the component checks the limit itself, before it calls the action.
 * Actions never count, so a caller that loops over an action (M7d's bulk invitations) counts under its own limit.
 *
 * The counter is `{name}:{user id}` on Laravel's rate limiter (the default cache store, as for Fortify's limiters),
 * in a fixed window of $decaySeconds from the first hit. A refusal is a ValidationException on the form's field.
 */
final readonly class UserActionLimit
{
    public function __construct(
        public string $name,
        public int $maxAttempts,
        public int $decaySeconds,
    ) {
        if ($maxAttempts < 1 || $decaySeconds < 1) {
            throw new InvalidArgumentException('A user action limit needs at least one attempt and one second.');
        }
    }

    /** Request an email change on the profile page: 3 sends per 10 minutes. */
    public static function emailChange(): self
    {
        return new self('email-change', 3, 600);
    }

    /** Change the password on the security page: 5 attempts per minute, counted before the current password is checked. */
    public static function passwordUpdate(): self
    {
        return new self('password-update', 5, 60);
    }

    /** Confirm a new TOTP secret: 5 attempts per minute, counted before the code is checked. */
    public static function twoFactorConfirm(): self
    {
        return new self('two-factor-confirm', 5, 60);
    }

    /** Invite a user from the invite page: 10 invitations per minute. */
    public static function invite(): self
    {
        return new self('invite', 10, 60);
    }

    /** Send an invitation again (pending list, accept page): 10 reminders per minute; RemindInvitation adds a per-invitation cooldown. */
    public static function remind(): self
    {
        return new self('remind', 10, 60);
    }

    /** The rate-limiter key: a string for any user id, never built from client input (G1-02). */
    public function key(User $user): string
    {
        return $this->name.':'.$user->id;
    }

    /**
     * Counts $cost attempts first and refuses when the new count passes the limit: RateLimiter::increment() returns
     * that count atomically, so parallel requests cannot all read the old one (CD-3, DEC-27 (2)). A refused attempt
     * still counts; it is a burst guard and is never given back. For limits that count every attempt.
     *
     * @throws ValidationException
     */
    public function attempt(User $user, string $field, int $cost = 1): void
    {
        $this->assertCost($cost);

        if (RateLimiter::increment($this->key($user), $this->decaySeconds, $cost) > $this->maxAttempts) {
            $this->refuse($user, $field);
        }
    }

    /**
     * Reserves $cost attempts before a completed-send action (email change, invite, remind), as attempt() does.
     * The caller gives them back with release() when the action throws, so a refused address costs nothing;
     * around() does both.
     *
     * @throws ValidationException
     */
    public function reserve(User $user, string $field, int $cost = 1): void
    {
        $this->attempt($user, $field, $cost);
    }

    /**
     * Gives back $cost reserved attempts of an action that did not go through. When the reservation's window has
     * passed meanwhile, the decrement lands on a fresh counter below zero; that counter is cleared instead, so the
     * next window never admits more than the limit.
     */
    public function release(User $user, int $cost = 1): void
    {
        $this->assertCost($cost);

        if (RateLimiter::decrement($this->key($user), $this->decaySeconds, $cost) < 0) {
            RateLimiter::clear($this->key($user));
        }
    }

    /**
     * The one shape of reserve() and release() for a completed-send action: reserves $cost attempts, runs $action
     * and gives them back when it throws, then rethrows, so a refused address or any other failure costs nothing.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $action
     * @return TResult
     *
     * @throws ValidationException
     */
    public function around(User $user, string $field, Closure $action, int $cost = 1): mixed
    {
        $this->reserve($user, $field, $cost);

        try {
            return $action();
        } catch (Throwable $exception) {
            $this->release($user, $cost);

            throw $exception;
        }
    }

    /**
     * Refuses when $cost more attempts would pass the limit; counts nothing. A pre-check that parallel requests can
     * outrun, so the kit's limits use attempt() or reserve(); kept for M7d.
     *
     * @throws ValidationException
     */
    public function ensureAvailable(User $user, string $field, int $cost = 1): void
    {
        $this->assertCost($cost);

        if (RateLimiter::tooManyAttempts($this->key($user), $this->maxAttempts - $cost + 1)) {
            $this->refuse($user, $field);
        }
    }

    /** Counts $cost attempts without a check (M7d; tests fill a limit with it). */
    public function hit(User $user, int $cost = 1): void
    {
        $this->assertCost($cost);

        RateLimiter::increment($this->key($user), $this->decaySeconds, $cost);
    }

    private function assertCost(int $cost): void
    {
        if ($cost < 1 || $cost > $this->maxAttempts) {
            throw new InvalidArgumentException("A cost of {$cost} does not fit the {$this->name} limit of {$this->maxAttempts}.");
        }
    }

    /**
     * The refusal on the form's field, with the seconds left in the window.
     *
     * @throws ValidationException
     */
    private function refuse(User $user, string $field): never
    {
        throw ValidationException::withMessages([
            $field => __('Too many attempts. Try again in :seconds seconds.', [
                'seconds' => max(1, RateLimiter::availableIn($this->key($user))),
            ]),
        ]);
    }
}
