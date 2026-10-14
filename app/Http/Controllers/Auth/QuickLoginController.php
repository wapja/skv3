<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Setup\QuickLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** §6.5, RL-9: the local quick login. */
final class QuickLoginController extends Controller
{
    public function __invoke(Request $request, QuickLogin $quickLogin): RedirectResponse
    {
        $url = $quickLogin->handoffUrl($request->string('email')->toString());

        abort_if($url === null, 404);

        if ($url === false) {
            return to_route('login')->with('status', __('This demo account no longer uses the demo password.'));
        }

        return redirect()->away($url);
    }
}
