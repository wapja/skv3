<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Laravel\Passkeys\Passkeys;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records a failed passkey sign-in like a failed password sign-in (§4.8, CD-6): auth.login_failed with
 * method "passkey". laravel/passkeys fires no Failed event, so the outcome is read from the passkey.login
 * response: a 422 (a WebAuthn or validation failure, JSON) or a redirect that flashed errors (a form post).
 * A throttled request (429) is not a sign-in attempt. The entry names a user only when the posted credential
 * is one of their passkeys and, on a tenant host, they are the host's own member: never another organisation's
 * member or a super admin (R1). RequestHandled runs outside the kernel's exception handling, so a failure here is
 * reported and never replaces the response. Auto-discovered.
 */
final readonly class RecordFailedPasskeySignIn
{
    public function __construct(private RecordActivity $recordActivity) {}

    public function handle(RequestHandled $event): void
    {
        $request = $event->request;

        if (! $request->routeIs('passkey.login') || ! $this->failed($request, $event->response)) {
            return;
        }

        // An audit failure is reported, never answered instead of the response (a 500 for a refused sign-in).
        try {
            $this->recordActivity->handle(ActivityEvent::LoginFailed, $this->ownerOf($request), ['method' => 'passkey']);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * StartSession saved the session before this event and aged this request's flash data, so the errors it
     * flashed are still there, and errors flashed by an earlier request are gone.
     */
    private function failed(Request $request, Response $response): bool
    {
        return $response->getStatusCode() === 422
            || ($response->isRedirection() && $request->hasSession() && $request->session()->has('errors'));
    }

    private function ownerOf(Request $request): ?User
    {
        $rawId = $request->input('credential.rawId');

        // A credential id is base64url (stored unpadded); anything else, invalid UTF-8 included, names nobody and never
        // reaches the database: PostgreSQL refuses invalid UTF-8 (SQLSTATE 22021), and RequestHandled runs outside the
        // kernel's exception handling, so a throw here would turn the refused sign-in into a 500.
        if (! is_string($rawId) || preg_match('/^[A-Za-z0-9_-]{1,1400}={0,2}$/', $rawId) !== 1) {
            return null;
        }

        // The passkey model is App\Tenancy\Auth\UnscopedPasskey: its user is found on every host, deleted ones included.
        $user = Passkeys::passkeyModel()::query()->where('credential_id', rtrim($rawId, '='))->first()?->user;

        if (! $user instanceof User) {
            return null;
        }

        $organisation = Tenant::current();

        if ($organisation === null) {
            return $user;
        }

        // R1 (as G4-01): a host's log names only its own members, never a super admin, also not one promoted from
        // this organisation, who keeps its organisation_id.
        return $user->organisation_id === $organisation->id && ! $user->isSuperAdmin() ? $user : null;
    }
}
