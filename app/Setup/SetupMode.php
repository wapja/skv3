<?php

declare(strict_types=1);

namespace App\Setup;

use App\Models\User;
use App\Setup\Http\DirectRequest;
use App\Tenancy\Facades\Tenant;
use Illuminate\Http\Request;
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
     * sync) are kept, so tests keep theirs. The permission registrar, built at boot on the old cache store, is
     * pointed at the new one.
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
    }

    /**
     * Where `/` sends a direct visitor in setup mode while no super admin exists (§5.3.4), else null. A proxied or
     * tunnelled request never gets the token (DEC-24): it would leave the machine in the tunnel's logs.
     */
    public function homeRedirect(Request $request): ?string
    {
        if ($request->attributes->get(self::ATTRIBUTE) !== true || ! DirectRequest::isDirect($request)) {
            return null;
        }

        $token = $this->token()->value();

        return $token === null || $this->superAdminExists() ? null : '/welcome-setup?token='.$token;
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
