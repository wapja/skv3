<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Tenancy\Auth\AuthenticateOnThisHost;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::authenticateUsing(fn (Request $request): ?User => app(AuthenticateOnThisHost::class)($request));
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
            $email = Str::transliterate(Str::lower((string) $request->input(Fortify::username())));

            return [
                Limit::perMinute(5)->by('login:'.$email.'|'.$request->ip())
                    ->after($this->failedAttempt($request))
                    ->response($this->loginThrottled(...)),
                Limit::perMinute(20)->by('login-ip:'.$request->ip())
                    ->after($this->failedAttempt($request))
                    ->response($this->loginThrottled(...)),
                Limit::perMinute(30)->by('login-burst:'.$email.'|'.$request->ip())
                    ->response($this->loginThrottled(...)),
            ];
        });

        RateLimiter::for('two-factor', function (Request $request): array {
            $challenge = $request->session()->get('login.id');

            return [
                Limit::perMinute(5)->by('two-factor:'.$challenge)->after($this->failedAttempt($request)),
                Limit::perMinute(10)->by('two-factor-burst:'.$challenge),
            ];
        });

        RateLimiter::for('password-confirm', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('password-confirm:'.$request->user()?->getAuthIdentifier()));

        RateLimiter::for('passkeys', function (Request $request): array {
            $credentialId = $request->input('credential.id');

            return [
                Limit::perMinute(10)
                    ->by('passkeys:'.($credentialId ?: $request->session()->getId()).'|'.$request->ip())
                    ->after($this->failedAttempt($request)),
                Limit::perMinute(30)->by('passkeys-burst:'.$request->ip()),
            ];
        });

        RateLimiter::for('password-email', fn (Request $request): array => [
            Limit::perMinute(5)->by('password-email:'.Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by('password-email-ip:'.$request->ip()),
        ]);

        RateLimiter::for('handoff', fn (Request $request): Limit => Limit::perMinute(10)->by('handoff:'.$request->ip()));
    }

    /** @return Closure(Response): bool */
    private function failedAttempt(Request $request): Closure
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

        return back()
            ->withInput($request->only(Fortify::username()))
            ->withErrors([Fortify::username() => $message])
            ->withHeaders($headers);
    }
}
