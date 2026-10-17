<?php

declare(strict_types=1);

use App\Actions\Audit\RecordActivity;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ResolvesCurrentUser;
use App\Enums\ActivityEvent;
use App\Models\HandoffToken;
use App\Support\RateLimits\UserActionLimit;
use App\Support\UserAgent;
use App\Tenancy\Auth\Impersonation;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The signed-in user's sessions on the security page (§6.6, CD-2): their live rows in the `sessions` table (the
 * kit's database driver), newest first, with the browser and platform, the IP, the last activity and "This device".
 * A row reaches the client only as a keyed hash of its id, never as the session id itself. "Log out other devices"
 * checks the password itself, so it needs no password confirmation.
 *
 * Hidden during an impersonation, and every request to it answers 403 then: the impersonator's id is in
 * sessions.user_id (ImpersonationAwareSessionHandler), and ending sessions takes the account owner's password.
 */
new class extends Component
{
    use PasswordValidationRules;
    use ResolvesCurrentUser;

    public string $password = '';

    public function mount(): void
    {
        $this->ensureNotImpersonating();
    }

    /** Every update, whatever it calls: a snapshot taken before an impersonation started is replayed during it. */
    public function hydrate(): void
    {
        $this->ensureNotImpersonating();
    }

    /**
     * The user's sessions that have not expired, newest first. A row older than the session lifetime is no session
     * any more (the driver reads it as empty), so it is not listed.
     *
     * @return list<array{key: string, browser: string|null, platform: string|null, device: 'desktop'|'mobile'|'tablet', ip: string|null, last_active: string, current: bool}>
     */
    #[Computed]
    public function sessions(): array
    {
        $currentId = session()->getId();
        $rows = DB::table('sessions')
            ->where('user_id', $this->currentUser()->id)
            ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        $sessions = [];

        foreach ($rows as $row) {
            $agent = UserAgent::parse(is_string($row->user_agent) ? $row->user_agent : null);

            $sessions[] = [
                // A keyed hash: the page never holds a session id (CD-2).
                'key' => hash_hmac('sha256', (string) $row->id, (string) config('app.key')),
                'browser' => $agent->browser,
                'platform' => $agent->platform,
                'device' => $agent->device,
                'ip' => is_string($row->ip_address) && $row->ip_address !== '' ? $row->ip_address : null,
                'last_active' => Carbon::createFromTimestamp((int) $row->last_activity)->diffForHumans(),
                'current' => hash_equals($currentId, (string) $row->id),
            ];
        }

        return $sessions;
    }

    /**
     * Logs out every other device (§6.6): the password is rehashed, so AuthenticateSession signs out every other
     * session and "remember me" cookie on its next request, and this device gets a fresh remember cookie
     * (Auth::logoutOtherDevices()); then the user's other rows and their unused handoff links are deleted. Every attempt counts against the
     * user's limit before the password is checked (CD-2), so the form cannot be used to guess it. One transaction
     * in DEC-34's lock order: users, then sessions and handoff tokens, then the activity insert.
     */
    public function logoutOtherDevices(RecordActivity $recordActivity): void
    {
        $user = $this->currentUser();

        try {
            UserActionLimit::logoutOtherDevices()->attempt($user, 'password');

            $this->validate(['password' => $this->currentPasswordRules()]);
        } catch (ValidationException $e) {
            $this->reset('password');

            throw $e;
        }

        DB::transaction(function () use ($user, $recordActivity): void {
            Auth::logoutOtherDevices($this->password);

            $ended = DB::table('sessions')
                ->where('user_id', $user->id)
                ->where('id', '!=', session()->getId())
                ->delete();

            // A handoff link issued by another session would sign that browser in again with the new hash (§6.4).
            HandoffToken::query()->where('user_id', $user->id)->whereNull('consumed_at')->delete();

            $recordActivity->handle(ActivityEvent::OtherSessionsEnded, $user, ['count' => $ended]);
        });

        $this->reset('password');
        unset($this->sessions);

        Flux::modal('logout-other-devices')->close();
        Flux::toast(variant: 'success', text: __('You were logged out on your other devices.'));
    }

    private function ensureNotImpersonating(): void
    {
        abort_if(Impersonation::isActive(), 403);
    }
};
