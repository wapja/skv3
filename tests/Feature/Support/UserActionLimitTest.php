<?php

declare(strict_types=1);

/** covers §4.2 */

use App\Models\User;
use App\Support\RateLimits\UserActionLimit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->freezeTime();
});

it('admits the limit, then refuses on the field with the seconds left, per user (CD-3)', function (): void {
    $ada = User::factory()->create();
    $bob = User::factory()->create();
    $limit = new UserActionLimit('test-action', 3, 600);

    foreach (range(1, 3) as $attempt) {
        $limit->attempt($ada, 'email');
    }
    $this->travel(100)->seconds();

    $refusal = null;

    try {
        $limit->attempt($ada, 'email');
    } catch (ValidationException $exception) {
        $refusal = $exception->errors();
    }

    expect($refusal)->toBe(['email' => [__('Too many attempts. Try again in :seconds seconds.', ['seconds' => 500])]]);

    $limit->attempt($bob, 'email');
    expect($limit->key($ada))->toBe('test-action:'.$ada->id);
});

it('checks without counting, and counts only what hit() is told (CD-3)', function (): void {
    $user = User::factory()->create();
    $limit = new UserActionLimit('test-action', 2, 60);

    foreach (range(1, 5) as $check) {
        $limit->ensureAvailable($user, 'email');
    }
    $limit->hit($user);
    $limit->hit($user);

    expect(fn () => $limit->ensureAvailable($user, 'email'))->toThrow(ValidationException::class);
});

it('counts an attempt before it checks it, so a refused attempt counts too (CD-3, DEC-27 (2))', function (): void {
    $user = User::factory()->create();
    $limit = new UserActionLimit('test-action', 5, 60);

    foreach (range(1, 5) as $attempt) {
        $limit->attempt($user, 'current_password');
    }

    expect(fn () => $limit->attempt($user, 'current_password'))->toThrow(ValidationException::class)
        ->and(RateLimiter::attempts($limit->key($user)))->toBe(6);
});

it('reserves without a hit in between, refuses past the limit and takes a released slot back (CD-3, DEC-27 (2))', function (): void {
    $user = User::factory()->create();
    $limit = new UserActionLimit('test-send', 5, 60);

    foreach (range(1, 5) as $reservation) {
        $limit->reserve($user, 'email');
    }

    expect(fn () => $limit->reserve($user, 'email'))->toThrow(ValidationException::class);

    // The refused reservation counted (6); two releases bring it back under the limit, so one more passes.
    $limit->release($user);
    $limit->release($user);
    $limit->reserve($user, 'email');

    expect(RateLimiter::attempts($limit->key($user)))->toBe(5)
        ->and(fn () => $limit->reserve($user, 'email'))->toThrow(ValidationException::class);
});

it('gives nothing back into the next window when the reservation\'s window has passed (CD-3)', function (): void {
    // The window ended between reserve() and a throwing action: a blind decrement re-created the key at -cost, so the
    // next window admitted the limit plus the cost.
    $user = User::factory()->create();
    $limit = new UserActionLimit('test-send', 3, 60);

    $limit->reserve($user, 'email', cost: 2);
    $this->travel(61)->seconds();
    $limit->release($user, cost: 2);

    foreach (range(1, 3) as $reservation) {
        $limit->reserve($user, 'email');
    }

    expect(fn () => $limit->reserve($user, 'email'))->toThrow(ValidationException::class);
});

it('reserves around an action, returns its result and gives the slot back when it throws (CD-3)', function (): void {
    $user = User::factory()->create();
    $limit = new UserActionLimit('test-send', 2, 60);

    expect($limit->around($user, 'email', fn (): string => 'sent'))->toBe('sent')
        ->and(fn () => $limit->around($user, 'email', fn () => throw new RuntimeException('refused')))->toThrow(RuntimeException::class, 'refused')
        ->and(RateLimiter::attempts($limit->key($user)))->toBe(1);

    $limit->around($user, 'email', fn (): null => null);

    expect(fn () => $limit->around($user, 'email', fn (): string => 'never'))->toThrow(ValidationException::class)
        ->and(RateLimiter::attempts($limit->key($user)))->toBe(3);
});

it('weighs a batch by its cost and refuses a batch that would pass the limit (M7d)', function (): void {
    $user = User::factory()->create();
    $limit = new UserActionLimit('test-batch', 10, 3600);

    $limit->attempt($user, 'emails', cost: 7);
    $limit->ensureAvailable($user, 'emails', cost: 3);

    expect(fn () => $limit->ensureAvailable($user, 'emails', cost: 4))->toThrow(ValidationException::class)
        ->and(fn () => $limit->ensureAvailable($user, 'emails', cost: 11))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $limit->hit($user, cost: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $limit->release($user, cost: 11))->toThrow(InvalidArgumentException::class);
});

it('opens again once the window has passed (CD-3)', function (): void {
    $user = User::factory()->create();
    $limit = UserActionLimit::passwordUpdate();

    foreach (range(1, 5) as $attempt) {
        $limit->attempt($user, 'current_password');
    }
    $this->travel(61)->seconds();

    $limit->attempt($user, 'current_password');

    expect($limit->key($user))->toBe('password-update:'.$user->id);
});

it('holds the limits of CD-3', function (): void {
    $limits = array_map(
        fn (UserActionLimit $limit): array => [$limit->name, $limit->maxAttempts, $limit->decaySeconds],
        [UserActionLimit::emailChange(), UserActionLimit::passwordUpdate(), UserActionLimit::twoFactorConfirm(), UserActionLimit::invite(), UserActionLimit::remind()],
    );

    expect($limits)->toBe([
        ['email-change', 3, 600],
        ['password-update', 5, 60],
        ['two-factor-confirm', 5, 60],
        ['invite', 10, 60],
        ['remind', 10, 60],
    ]);
});
