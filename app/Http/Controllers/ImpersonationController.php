<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Users\StopImpersonation;
use App\Authorization\Permission;
use App\Models\User;
use App\Tenancy\Auth\Impersonation;
use Illuminate\Http\RedirectResponse;

/** DELETE /impersonation (§4.7): back to your own account, on whatever host the session lives. */
final class ImpersonationController extends Controller
{
    public function destroy(StopImpersonation $stopImpersonation): RedirectResponse
    {
        // The route is registered only while the module is on; a cached route file can outlive the flag (RK-I12).
        abort_unless(Impersonation::enabled(), 404);

        $impersonator = $stopImpersonation->handle();

        if (! $impersonator instanceof User) {
            return to_route('dashboard');
        }

        return to_route($impersonator->can(Permission::UsersView) ? 'users.index' : 'dashboard')
            ->with('status', __('You are back in your own account.'));
    }
}
