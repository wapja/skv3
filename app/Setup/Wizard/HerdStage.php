<?php

declare(strict_types=1);

namespace App\Setup\Wizard;

use App\Actions\Auth\IssueHandoff;
use App\Models\User;
use App\Setup\Http\DirectRequest;
use App\Setup\Marker;
use App\Setup\SetupContext;
use App\Setup\StepResult;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\Hosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use JsonException;

/**
 * The wizard after step 9 (RL-6): the Herd step runs detached, the page polls with the secret only its browser holds,
 * and the new super admin signs in once through a handoff. Every endpoint is local, direct and secret-bound.
 */
final readonly class HerdStage
{
    public function __construct(private WizardRunner $wizard) {}

    /** The Herd state for this request's secret; anything else (not local, a proxy, a wrong or stale secret) is a 404. */
    public function state(Request $request): HerdState
    {
        abort_unless(DirectRequest::isDirect($request), 404);

        try {
            $state = HerdState::load(app(SetupContext::class)->basePath);
        } catch (JsonException|InvalidArgumentException) {
            abort(404);
        }

        $secret = $request->query('secret');
        abort_unless($state instanceof HerdState && is_string($secret) && $state->isFresh() && $state->matches($secret), 404);

        return $state;
    }

    /**
     * Whether the Herd step's answer is overdue (§5.3.4): it has none, and its process exited without writing one (the
     * exit file comes after the result), or the poll's 60 seconds since the stage began are over.
     */
    public function isOverdue(HerdState $state): bool
    {
        return ! $state->result instanceof StepResult
            && (is_file(app(SetupContext::class)->path(HerdState::EXIT))
                || Date::now()->getTimestamp() - $state->startedAt >= HerdState::POLL_SECONDS);
    }

    /** The server-side confirmation of an overdue Herd step: its result once Herd serves (and secures) the site, else null. */
    public function confirm(HerdState $state): ?StepResult
    {
        return $this->wizard->confirmHerd($state);
    }

    /** "Continue without HTTPS": the Herd step gave no answer in time, so the HTTPS consequence applies now (OD-2). */
    public function continueWithoutHttps(HerdState $state): void
    {
        // The detached Herd step may have finished since this request loaded the state: its answer stands (RL-6). A
        // state with another secret belongs to another finish() and is not this browser's to settle.
        $current = HerdState::load(app(SetupContext::class)->basePath);

        if (! $current instanceof HerdState || ! hash_equals($state->secretHash, $current->secretHash) || $current->result instanceof StepResult) {
            return;
        }

        $this->wizard->settleWithoutHttps($current, __('Continued without HTTPS.'));
    }

    /** @return array<string, mixed> the marker's summary (§5.3.4: the summary is read from the marker) */
    public function summary(): array
    {
        return Marker::read(app(SetupContext::class))['summary'] ?? [];
    }

    /** The handoff for the installed super admin, or the login page for a demo; the secret is used up (RL-6). */
    public function loginUrl(HerdState $state): string
    {
        HerdState::forget(app(SetupContext::class)->basePath);

        if ($state->answers->startWith !== 'super_admin' || $state->answers->superAdminEmail === null) {
            return Hosts::url(Hosts::apex(), '/login');
        }

        $user = Tenant::bypass(fn (): ?User => User::query()->where('email', $state->answers->superAdminEmail)->first());
        abort_unless($user instanceof User && $user->isSuperAdmin(), 404);

        return app(IssueHandoff::class)->handle($user, Hosts::admin(), '/');
    }
}
