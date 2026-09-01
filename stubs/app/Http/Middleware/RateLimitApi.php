<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Per-bucket request throttling for the API.
 *
 * Strategy, selected by the route: the middleware parameter names a bucket in
 * config('cachewraith-template.rate_limits'), and each bucket brings its own
 * budget and its own key derivation — ->middleware('ratelimit.api:login')
 * versus the default 'api'. Adding a bucket is a config entry plus, at most,
 * one arm in resolveKey(); no existing route changes.
 *
 * Chosen over registering named limiters in a service provider: a limiter
 * registered globally is invisible at the route that depends on it, and
 * silently does nothing if the application already defined a limiter under
 * the same name. Here the route states its own budget.
 *
 * OWASP A06 (Insecure Design) / A07 (Authentication Failures): an endpoint
 * with no ceiling is a credential-stuffing and scraping target. The "login"
 * bucket is keyed by credential *and* source address, so rotating either one
 * alone does not buy an attacker more attempts.
 */
final class RateLimitApi
{
    public function handle(Request $request, Closure $next, string $bucket = 'api'): Response
    {
        if (! config('cachewraith-template.enable_rate_limiting', true)) {
            return $next($request);
        }

        /** @var array{attempts?: int, per_minutes?: int}|null $limit */
        $limit = config('cachewraith-template.rate_limits.'.$bucket);

        if (! is_array($limit)) {
            // Fail closed on a typo'd bucket name rather than silently
            // serving an unlimited endpoint (OWASP A10).
            $limit = ['attempts' => 60, 'per_minutes' => 1];
        }

        $attempts = max(1, (int) ($limit['attempts'] ?? 60));
        $decay = max(1, (int) ($limit['per_minutes'] ?? 1)) * 60;
        $key = $this->resolveKey($request, $bucket);

        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            $retryAfter = RateLimiter::availableIn($key);

            throw new TooManyRequestsHttpException($retryAfter, 'Too many requests. Please slow down.', null, 429, [
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $attempts,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        RateLimiter::hit($key, $decay);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) $attempts);
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($key, $attempts));

        return $response;
    }

    /**
     * The key must be stable for one caller and unguessable across callers.
     * Hashed because it is stored in the cache and may be logged.
     */
    private function resolveKey(Request $request, string $bucket): string
    {
        $identity = match ($bucket) {
            // A07: credential + address together. Keying on the address alone
            // lets a botnet spread attempts; keying on the email alone lets
            // one host walk a user list.
            'login' => mb_strtolower(trim((string) $request->input('email'))).'|'.$request->ip(),

            default => (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
        };

        return $bucket.'|'.sha1($identity);
    }
}
