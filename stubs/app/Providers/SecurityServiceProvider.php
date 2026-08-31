<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

/**
 * Application-wide security defaults.
 *
 * Deliberately a provider of its own rather than extra lines in
 * AppServiceProvider: the installer must not rewrite a file your application
 * already owns, and keeping these concerns together means a reviewer can see
 * every global security default in one place (Single Responsibility).
 *
 * Registered in bootstrap/providers.php (Laravel 11+) or config/app.php
 * (Laravel 10) by php artisan smn:install.
 */
final class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->forceHttps();
        $this->registerPolicies();
        $this->registerRateLimiters();
        $this->applyStrictModels();
    }

    /**
     * OWASP A04: never generate an http:// URL outside local development. Set
     * smn-template.force_https explicitly if you terminate TLS at a proxy and
     * have not configured TrustProxies yet.
     */
    private function forceHttps(): void
    {
        $force = config('smn-template.force_https');

        if ($force === null) {
            $force = ! $this->app->environment(['local', 'testing']);
        }

        if ($force) {
            URL::forceScheme('https');
        }
    }

    /**
     * OWASP A01: registered explicitly so authorisation does not depend on
     * class-name convention discovery continuing to match.
     */
    private function registerPolicies(): void
    {
        Gate::policy(User::class, UserPolicy::class);
    }

    /**
     * OWASP A06 / A07: a named limiter is only defined here if the application
     * has not already defined its own, so this never silently loosens limits
     * you set yourself.
     */
    private function registerRateLimiters(): void
    {
        if (! config('smn-template.enable_rate_limiting', true)) {
            return;
        }

        if (RateLimiter::limiter('api') === null) {
            $attempts = (int) config('smn-template.rate_limits.api.attempts', 60);

            RateLimiter::for('api', static fn (Request $request): Limit => Limit::perMinute($attempts)
                ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
        }

        if (RateLimiter::limiter('login') === null) {
            $attempts = (int) config('smn-template.rate_limits.login.attempts', 5);

            // Keyed by credential and address together, so neither rotating
            // the email nor rotating the IP alone buys extra attempts.
            RateLimiter::for('login', static fn (Request $request): Limit => Limit::perMinute($attempts)
                ->by(sha1(mb_strtolower((string) $request->input('email')).'|'.$request->ip())));
        }
    }

    /**
     * Fail loudly in development instead of silently in production: strict mode
     * turns a silently discarded (non-fillable) attribute and an accidental
     * lazy-load into exceptions you can see (OWASP A01 / A06).
     */
    private function applyStrictModels(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Laravel 11+: refuse migrate:fresh / db:wipe against production.
        if ($this->app->isProduction() && method_exists(DatabaseManager::class, 'prohibitDestructiveCommands')) {
            DB::prohibitDestructiveCommands();
        }
    }
}
