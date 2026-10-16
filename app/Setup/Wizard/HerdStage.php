<?php

declare(strict_types=1);

namespace App\Setup\Wizard;

use App\Actions\Auth\IssueHandoff;
use App\Models\User;
use App\Setup\Http\DirectRequest;
use App\Setup\Http\SetupHostGuard;
use App\Setup\Marker;
use App\Setup\SetupContext;
use App\Setup\StepResult;
use App\Setup\Steps\HerdSiteStep;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\Hosts;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

    /**
     * The Herd state for this request's secret. Anything else is a 404 (RL-4, RL-5, RL-6): not local, not direct, a
     * host other than the pinned one or its admin host, or a wrong or stale secret. A caller that needs the secret
     * reads it first: this removes it from the request, because StartSession stores fullUrl() as the previous URL,
     * and fullUrl() reads QUERY_STRING (g3-27, as EnsureSetupAccess does for the token).
     */
    public function state(Request $request): HerdState
    {
        abort_unless(DirectRequest::isDirect($request), 404);

        try {
            $state = HerdState::load(app(SetupContext::class)->basePath);
        } catch (JsonException|InvalidArgumentException) {
            abort(404);
        }

        $secret = $request->query('secret');
        abort_unless($state instanceof HerdState && SetupHostGuard::allows($request, $state->host) && is_string($secret)
            && $state->isFresh() && $state->matches($secret), 404);

        $request->query->remove('secret');
        $request->server->set('QUERY_STRING', Arr::query($request->query->all()));

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
        $basePath = app(SetupContext::class)->basePath;

        SetupLock::run($basePath, function () use ($state, $basePath): void {
            // The detached Herd step may have finished since this request loaded the state: its answer stands (RL-6).
            // A state with another secret belongs to another finish() and is not this browser's to settle.
            $current = HerdState::load($basePath);

            // The login used the state up and removed the lock: this late request removes the lock it recreated (g3-02).
            if (! $current instanceof HerdState) {
                SetupLock::forget($basePath);

                return;
            }

            if (! hash_equals($state->secretHash, $current->secretHash) || $current->result instanceof StepResult) {
                return;
            }

            $this->wizard->settleWithoutHttps($current, __('Continued without HTTPS.'));
        });
    }

    /** The Herd command the manual page offers (RL-7), or null when there is none to run. */
    public function manualCommand(HerdState $state): ?string
    {
        $basePath = app(SetupContext::class)->basePath;

        return app(HerdSiteStep::class)->manualCommand($state->context($basePath));
    }

    /**
     * @return array<string, mixed> the marker's summary (§5.3.4: the summary is read from the marker); none when the
     *                              marker no longer reads back, so the finish page and its login stay usable (g3-06)
     */
    public function summary(): array
    {
        try {
            return Marker::read(app(SetupContext::class))['summary'] ?? [];
        } catch (JsonException) {
            return [];
        }
    }

    /**
     * The handoff for the installed super admin, or the login page for a demo or for a super admin with a confirmed
     * second factor, which a handoff would skip (CD-14); the secret is used up (RL-6). The state is claimed under the
     * SetupLock, so two logins never both pass and a late Herd step never writes it back.
     */
    public function loginUrl(HerdState $state): string
    {
        $basePath = app(SetupContext::class)->basePath;

        SetupLock::run($basePath, function () use ($state, $basePath): void {
            $current = HerdState::load($basePath);
            abort_unless($current instanceof HerdState && hash_equals($state->secretHash, $current->secretHash), 404);
            HerdState::forget($basePath);
            SetupLock::forget($basePath);
        });

        if ($state->answers->startWith !== 'super_admin' || $state->answers->superAdminEmail === null) {
            return Hosts::url(Hosts::apex(), '/login');
        }

        $user = Tenant::bypass(fn (): ?User => User::query()->where('email', $state->answers->superAdminEmail)->first());
        abort_unless($user instanceof User && $user->isSuperAdmin(), 404);

        if ($user->two_factor_confirmed_at !== null) {
            return Hosts::url(Hosts::apex(), '/login');
        }

        return app(IssueHandoff::class)->handle($user, Hosts::admin(), '/');
    }
}
