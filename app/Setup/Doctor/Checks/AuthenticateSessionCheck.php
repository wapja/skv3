<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Session\Middleware\AuthenticateSession;

/** §5.4: AuthenticateSession runs in the web group (bootstrap/app.php). Missing it is a kit bug, so there is no fix. */
final readonly class AuthenticateSessionCheck implements Check
{
    public function key(): string
    {
        return 'authenticate-session';
    }

    public function label(): string
    {
        return __('Session authentication');
    }

    public function run(DoctorContext $context): CheckResult
    {
        // The HTTP kernel holds the middleware groups that bootstrap/app.php registers, also in the console.
        $web = app(HttpKernel::class)->getMiddlewareGroups()['web'] ?? [];

        if (in_array(AuthenticateSession::class, $web, true)) {
            return CheckResult::pass(__('AuthenticateSession runs in the web middleware group'));
        }

        return CheckResult::fail(__('AuthenticateSession is missing from the web middleware group'));
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
