<?php

declare(strict_types=1);

namespace App\Http\Middleware\Security;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attaches hardening response headers to every response.
 *
 * Decorator: the middleware wraps the response without the controllers,
 * services or routes knowing it exists, so the guarantee holds for endpoints
 * added later — including ones whose authors never read this file. That is the
 * whole point of applying it globally rather than per route.
 *
 * OWASP A02 (Security Misconfiguration) and A07 (XSS in any client that renders
 * a response). Values are configurable in config/smn-template.php; the defaults
 * assume a JSON-only API and deny everything.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! config('smn-template.enable_security_headers', true)) {
            return $response;
        }

        /** @var array<string, string> $headers */
        $headers = (array) config('smn-template.security_headers', []);

        foreach ($headers as $header => $value) {
            // Never clobber a header a route set deliberately (a docs endpoint
            // may need its own CSP, for example).
            if ($value !== '' && ! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        // HSTS is only meaningful — and only safe — over an already-secure
        // connection. Sending it over http lets a proxy pin a policy the site
        // may not be able to honour.
        if ($request->secure() && config('smn-template.hsts.enabled', true)) {
            $response->headers->set('Strict-Transport-Security', $this->hstsValue());
        }

        $this->applyNoStore($request, $response);

        // Hide the framework/runtime fingerprint where it is ours to hide.
        $response->headers->remove('X-Powered-By');

        return $response;
    }

    /**
     * OWASP A04: an authenticated API response must not be written to a shared
     * or on-disk cache. Laravel always sends "no-cache, private", which still
     * permits storage — only "no-store" forbids it.
     *
     * Scoped to API/JSON traffic so that cacheable web assets are untouched,
     * and never applied over a response that opted into caching deliberately.
     */
    private function applyNoStore(Request $request, Response $response): void
    {
        if (! $this->isApiRequest($request)) {
            return;
        }

        $current = strtolower((string) $response->headers->get('Cache-Control', ''));

        foreach (['public', 'max-age', 's-maxage', 'immutable', 'no-store'] as $directive) {
            if (str_contains($current, $directive)) {
                return;
            }
        }

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
    }

    private function isApiRequest(Request $request): bool
    {
        $prefix = trim((string) config('smn-template.api_version_prefix', 'api'), '/');

        return $request->is($prefix.'/*') || $request->expectsJson();
    }

    private function hstsValue(): string
    {
        $value = 'max-age='.(int) config('smn-template.hsts.max_age', 31536000);

        if (config('smn-template.hsts.include_subdomains', true)) {
            $value .= '; includeSubDomains';
        }

        if (config('smn-template.hsts.preload', false)) {
            $value .= '; preload';
        }

        return $value;
    }
}
