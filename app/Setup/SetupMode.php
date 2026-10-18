<?php

declare(strict_types=1);

namespace App\Setup;

use App\Models\User;
use App\Setup\Http\DirectRequest;
use App\Setup\Http\SetupHostGuard;
use App\Setup\Support\Toolchain;
use App\Tenancy\Facades\Tenant;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use ReflectionProperty;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Setup mode (RL-1, §5.3.4): the app runs locally, has no marker and still has its setup token. While it is on, the
 * web requests never depend on the database that setup changes under them, the tenant resolver stands aside, and
 * `/` leads to the wizard until a super admin exists.
 */
final readonly class SetupMode
{
    /** The request attribute EnterSetupMode sets; InitializeTenancy and the gate read it. */
    public const string ATTRIBUTE = 'setup_mode';

    public function __construct(private string $basePath) {}

    public function active(): bool
    {
        return app()->isLocal()
            && ! is_file(rtrim($this->basePath, '/').'/'.Marker::FILE)
            && $this->token()->exists();
    }

    public function token(): SetupToken
    {
        return new SetupToken($this->basePath);
    }

    /**
     * A file session, a file cache and the sync queue: drivers that already need no database (array, file, cookie,
     * sync) are kept, so tests keep theirs. The permission registrar and the rate limiter, built at boot on the old
     * cache store, are pointed at the new one: Livewire asks the limiter on every update (its checksum limit), which
     * would reach a database that does not exist yet.
     */
    public function applyRuntime(): void
    {
        $runtime = [];
        // The doctor (step 8) runs inside this request and must check the project's drivers (§5.4). A replacement
        // recorded earlier in the same process stays, because the config then already holds the setup drivers.
        /** @var array{session?: string, queue?: string} $replaced */
        $replaced = config('setup.replaced', []);

        if (! in_array(config('session.driver'), ['file', 'array', 'cookie'], true)) {
            $replaced['session'] = (string) config('session.driver');
            $runtime['session.driver'] = 'file';
        }

        if (! in_array(config('cache.default'), ['file', 'array'], true)) {
            $runtime['cache.default'] = 'file';
        }

        if (config('queue.default') !== 'sync') {
            $replaced['queue'] = (string) config('queue.default');
            $runtime['queue.default'] = 'sync';
        }

        config(['setup.replaced' => $replaced] + $runtime);

        if (app()->resolved(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->initializeCache();
        }

        if (isset($runtime['cache.default']) && app()->resolved(RateLimiter::class)) {
            $this->moveRateLimiter();
        }
    }

    /**
     * What `/` does in setup mode (§5.3.4, RL-5), from one look at the gate, the token, the super admin, the pin and
     * the host (G6-07: the host is resolved at most once, so a hung `herd tld` stalls `/` for one timeout, not two).
     * - `redirect`: the wizard with the token, for a direct visitor while no super admin exists, on the pinned host or
     *   its admin host once begin() pinned one, else on a host the wizard would pin. A proxied or tunnelled request
     *   never gets the token (DEC-24): it would leave the machine in the tunnel's logs. Nor does a host the wizard
     *   cannot pin (RL-5): a page on such a name could read the token from the redirect.
     * - `problem`: before begin(), why such a visitor gets no token on this host, with `suggestion` the address to open
     *   instead (g3-34: localhost on the request's port without Herd; {folder}.{tld} under Herd), or null when no
     *   address would do (g3-10: the folder; g3-28: `herd tld`).
     * Both are null when `/` has nothing to do with setup.
     *
     * @return array{redirect: ?string, problem: array{suggestion: ?string, problem: string}|null}
     */
    public function home(Request $request): array
    {
        $none = ['redirect' => null, 'problem' => null];

        if ($request->attributes->get(self::ATTRIBUTE) !== true || ! DirectRequest::isDirect($request)) {
            return $none;
        }

        $token = $this->token()->value();

        if ($token === null || $this->superAdminExists()) {
            return $none;
        }

        $wizard = '/welcome-setup?token='.$token;
        $pinned = SetupHostGuard::pinnedWizardHost($this->basePath);

        if ($pinned !== null) {
            return SetupHostGuard::allows($request, $pinned) ? ['redirect' => $wizard, 'problem' => null] : $none;
        }

        $resolved = SetupHostGuard::resolve($request->getHttpHost(), app(Toolchain::class), app(SetupContext::class)->folderName());

        return $resolved['host'] !== null
            ? ['redirect' => $wizard, 'problem' => null]
            : ['redirect' => null, 'problem' => ['suggestion' => $resolved['suggestion'], 'problem' => (string) $resolved['problem']]];
    }

    /** A limiter on the setup cache that keeps the named limiters boot registered (Fortify's, the kit's). */
    private function moveRateLimiter(): void
    {
        $limiters = new ReflectionProperty(RateLimiter::class, 'limiters')->getValue(app(RateLimiter::class));
        $limiter = new RateLimiter(cache()->store('file'));

        foreach (is_array($limiters) ? $limiters : [] as $name => $callback) {
            $limiter->for($name, $callback);
        }

        app()->instance(RateLimiter::class, $limiter);
        RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
    }

    /** False when the database cannot be read yet (a first install): the wizard is what sets it up. */
    private function superAdminExists(): bool
    {
        try {
            return Tenant::bypass(fn (): bool => User::query()->where('is_super_admin', true)->exists());
        } catch (Throwable) {
            return false;
        }
    }
}
