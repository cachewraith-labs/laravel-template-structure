<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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
 * Two profiles, chosen per request. A JSON API wants "default-src 'none';
 * form-action 'none'" — a JSON document loads nothing and submits nothing. An
 * HTML page cannot live under that policy: it blocks the stylesheet and every
 * POST, sign-in included. One compromise policy would mean loosening the API's
 * headers to accommodate pages the API does not serve, so instead the request
 * picks: API prefix or JSON accept header gets "security_headers", everything
 * else gets "web_security_headers".
 *
 * Strategy, selected by the request rather than by construction: there are
 * exactly two real variants and a known axis of change (a third front door
 * would add a third profile), and the whole of the variation is a config key,
 * so a class hierarchy would be ceremony around an array lookup.
 *
 * OWASP A02 (Security Misconfiguration) and A05 (the CSP is what stops an
 * injected <script> from executing). Values live in
 * config/cachewraith-template.php.
 */
final class SecurityHeaders
{
    /**
     * Used only when config/cachewraith-template.php predates the web stack.
     * Keep it in step with the "web_security_headers" entry in that file.
     *
     * @var array<string, string>
     */
    private const WEB_FALLBACK = [
        'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! config('cachewraith-template.enable_security_headers', true)) {
            return $response;
        }

        foreach ($this->profileFor($request) as $header => $value) {
            // Never clobber a header a route set deliberately (a docs endpoint
            // may need its own CSP, for example).
            if ($value !== '' && ! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        // HSTS is only meaningful — and only safe — over an already-secure
        // connection. Sending it over http lets a proxy pin a policy the site
        // may not be able to honour.
        if ($request->secure() && config('cachewraith-template.hsts.enabled', true)) {
            $response->headers->set('Strict-Transport-Security', $this->hstsValue());
        }

        $this->applyNoStore($request, $response);

        // Hide the framework/runtime fingerprint where it is ours to hide.
        $response->headers->remove('X-Powered-By');

        return $response;
    }

    /**
     * The header profile for this request.
     *
     * The web profile falls back to a built-in default rather than to the API
     * profile when the config key is missing — an application scaffolded
     * before the web stack existed, and never re-run with --force, would
     * otherwise serve its Blade pages under "default-src 'none'" and render
     * them unusable. Missing configuration should degrade to a working secure
     * default, not to a broken one (OWASP A10).
     *
     * @return array<string, string>
     */
    private function profileFor(Request $request): array
    {
        if ($this->isApiRequest($request)) {
            return (array) config('cachewraith-template.security_headers', []);
        }

        return (array) config('cachewraith-template.web_security_headers', self::WEB_FALLBACK);
    }

    /**
     * OWASP A04: a response carrying someone's data must not be written to a
     * shared or on-disk cache. Laravel always sends "no-cache, private", which
     * still permits storage — only "no-store" forbids it.
     *
     * Applied to API/JSON traffic, and to any page rendered for a signed-in
     * visitor: a cached HTML page is still on disk after they sign out, and
     * still reachable with the back button on a shared machine. Anonymous
     * pages and static assets are left alone so they stay cacheable, and a
     * response that opted into caching deliberately is never overridden.
     */
    private function applyNoStore(Request $request, Response $response): void
    {
        if (! $this->isApiRequest($request) && ! $this->isAuthenticatedPage($request)) {
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
        $prefix = trim((string) config('cachewraith-template.api_version_prefix', 'api'), '/');

        return $request->is($prefix.'/*') || $request->expectsJson();
    }

    /**
     * hasSession() is checked first because this middleware is global: it also
     * wraps responses that never entered the web group — an unmatched URL, a
     * maintenance-mode 503 — where resolving the session guard would throw.
     * The error path must not be the thing that fails (OWASP A10).
     */
    private function isAuthenticatedPage(Request $request): bool
    {
        return $request->hasSession() && $request->user() !== null;
    }

    private function hstsValue(): string
    {
        $value = 'max-age='.(int) config('cachewraith-template.hsts.max_age', 31536000);

        if (config('cachewraith-template.hsts.include_subdomains', true)) {
            $value .= '; includeSubDomains';
        }

        if (config('cachewraith-template.hsts.preload', false)) {
            $value .= '; preload';
        }

        return $value;
    }
}
