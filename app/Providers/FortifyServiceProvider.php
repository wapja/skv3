<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Support\RateLimits\ThrottleKey;
use App\Support\Skv;
use App\Tenancy\Auth\AuthenticateOnThisHost;
use App\Tenancy\Hosts;
use Closure;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse as FailedPasswordResetResponseContract;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Responses\FailedPasswordResetResponse;
use Symfony\Component\HttpFoundation\Response;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // R1: an unknown address gets the same error as a bad token, so password.update reveals no addresses.
        $this->app->bind(
            FailedPasswordResetResponseContract::class,
            fn (): FailedPasswordResetResponse => new FailedPasswordResetResponse(Password::INVALID_TOKEN),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->clearLoginFailuresOnSignIn();
    }

    /**
     * The passkeys limiter (§4.2, G1-05): failed ceremonies per signed-in user (confirm, register) or, for a guest's
     * sign-in, per IP, plus a backstop that counts every request. No bucket comes from the request body, so a client
     * cannot pick a fresh one per attempt. Public so tests that turn the module on after boot can register it (RL-3).
     *
     * @return Closure(Request): list<Limit>
     */
    public static function passkeysLimiter(): Closure
    {
        return static function (Request $request): array {
            $user = $request->user();

            return [
                Limit::perMinute(10)
                    ->by($user === null ? 'passkeys-ip:'.$request->ip() : 'passkeys-user:'.$user->getAuthIdentifier())
                    ->after(self::failedAttempt($request)),
                Limit::perMinute(30)->by('passkeys-burst:'.$request->ip()),
            ];
        };
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::authenticateUsing(fn (Request $request): ?User => app(AuthenticateOnThisHost::class)($request));

        // R1: the reset link points at the user's own host, whichever host the request came from.
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => Hosts::url(
            Hosts::homeOf($user),
            route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], absolute: false),
        ));
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (): Factory|View => view('pages::auth.login'));
        Fortify::twoFactorChallengeView(fn (): Factory|View => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn (): Factory|View => view('pages::auth.confirm-password'));
        Fortify::resetPasswordView(fn (): Factory|View => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn (): Factory|View => view('pages::auth.forgot-password'));
    }

    /**
     * §4.2: login, two-factor and passkey limiters count failed attempts only (a response of 400 or more,
     * or validation errors flashed by this request); password confirmation, password-reset and handoff
     * limiters count every request (DEC-27).
     *
     * A failure-only limit is checked before the request but counted after the response, so a burst of
     * parallel requests can pass it while its counter is still 0. Each one therefore has a backstop that
     * counts every request, set above what a real user ever reaches (DEC-27).
     *
     * The IP limits assume that $request->ip() is the client's address: behind a load balancer or CDN,
     * set TRUSTED_PROXIES (config/trustedproxy.php), or every visitor shares the proxy's buckets.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $email = $this->inputString($request, Fortify::username());

            return [
                Limit::perMinute(5)->by($this->loginFailureBucket($email, $request->ip()))
                    ->after(self::failedAttempt($request))
                    ->response($this->loginThrottled(...)),
                Limit::perMinute(20)->by('login-ip:'.$request->ip())
                    ->after(self::failedAttempt($request))
                    ->response($this->loginThrottled(...)),
                Limit::perMinute(30)->by('login-burst:'.Str::transliterate(Str::lower($email)).'|'.$request->ip())
                    ->response($this->loginThrottled(...)),
            ];
        });

        RateLimiter::for('two-factor', function (Request $request): array {
            $challenge = $request->session()->get('login.id');

            return [
                Limit::perMinute(5)->by('two-factor:'.$challenge)->after(self::failedAttempt($request)),
                Limit::perMinute(10)->by('two-factor-burst:'.$challenge),
            ];
        });

        RateLimiter::for('password-confirm', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('password-confirm:'.$request->user()?->getAuthIdentifier()));

        // CD-3: Fortify's two-factor management routes and passkey.destroy, every request, per user.
        RateLimiter::for('account-security', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('account-security:'.$request->user()?->getAuthIdentifier()));

        // §6.8 / RL-3: the passkeys limiter exists only while the module is on.
        if (Skv::passkeysEnabled()) {
            RateLimiter::for('passkeys', self::passkeysLimiter());
        }

        RateLimiter::for('password-email', fn (Request $request): array => [
            Limit::perMinute(5)->by('password-email:'.Str::lower($this->inputString($request, 'email')).'|'.$request->ip()),
            Limit::perMinute(20)->by('password-email-ip:'.$request->ip()),
        ]);

        RateLimiter::for('password-reset', fn (Request $request): array => [
            Limit::perMinute(5)->by('password-reset:'.Str::lower($this->inputString($request, 'email')).'|'.$request->ip()),
            Limit::perMinute(20)->by('password-reset-ip:'.$request->ip()),
        ]);

        RateLimiter::for('handoff', fn (Request $request): Limit => Limit::perMinute(10)->by('handoff:'.$request->ip()));

        // §6.6, CD-5: the audit CSV export, every request, per user; each download reads the whole filtered log.
        RateLimiter::for('activity-export', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('activity-export:'.$request->user()?->getAuthIdentifier()));

        // §6.6: Livewire's temporary-upload endpoint (config/livewire.php, behind `auth`), every request, per user. Each
        // upload may hold up to 2 MB on the disk until it is used or Livewire's cleanup removes it a day later.
        RateLimiter::for('livewire-upload', fn (Request $request): array => [
            Limit::perMinute(10)->by('livewire-upload:'.$request->user()?->getAuthIdentifier()),
            Limit::perHour(60)->by('livewire-upload-hour:'.$request->user()?->getAuthIdentifier()),
        ]);
    }

    /**
     * G1-03: any sign-in (password, two-factor, passkey, handoff, invitation) clears the address+IP failure bucket of
     * the `login` limiter. Fortify's LoginRateLimiter::clear() clears a key the named limiter does not use. The
     * IP-wide and burst buckets stay, so a correct password never lifts the limit for other addresses.
     */
    private function clearLoginFailuresOnSignIn(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                RateLimiter::clear(ThrottleKey::of('login', $this->loginFailureBucket($event->user->email, request()->ip())));
            }
        });
    }

    /** The address+IP failure bucket of the `login` limiter (G1-03), shared by the limiter and the sign-in listener. */
    private function loginFailureBucket(string $email, ?string $ip): string
    {
        return 'login:'.Str::transliterate(Str::lower($email)).'|'.$ip;
    }

    /** G1-02: a limiter key part from the request; an array or other non-string input counts as empty, never as a 500. */
    private function inputString(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }

    /** @return Closure(Response): bool */
    private static function failedAttempt(Request $request): Closure
    {
        return fn (Response $response): bool => $response->getStatusCode() >= 400
            || in_array('errors', (array) $request->session()->get('_flash.new', []), true);
    }

    /** @param  array<string, mixed>  $headers */
    private function loginThrottled(Request $request, array $headers): Response
    {
        $seconds = (int) ($headers['Retry-After'] ?? 60);
        $message = __('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]);

        if ($request->expectsJson()) {
            return new JsonResponse(['message' => $message, 'errors' => [Fortify::username() => [$message]]], 429, $headers);
        }

        // CD-13: the login form posts from the login page, so the refusal goes back there, never to the Referer.
        return to_route('login')
            ->withInput($request->only(Fortify::username()))
            ->withErrors([Fortify::username() => $message])
            ->withHeaders($headers);
    }
}
