<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Security headers
    |--------------------------------------------------------------------------
    |
    | Master switch for App\Http\Middleware\SecurityHeaders. Leave this on.
    | Turning it off removes the CSP, framing, MIME-sniffing and referrer
    | protections (OWASP A02: Security Misconfiguration).
    |
    */

    'enable_security_headers' => env('CACHEWRAITH_SECURITY_HEADERS', true),

    /*
    |--------------------------------------------------------------------------
    | Header values — API profile
    |--------------------------------------------------------------------------
    |
    | Applied to requests under the API prefix, and to anything that asked for
    | JSON. Tuned for a JSON-only response: nothing may be loaded, framed,
    | executed or submitted, because a JSON document needs none of it.
    |
    | SecurityHeaders picks between this profile and the web one below per
    | request, so hardening the API costs the Blade pages nothing and relaxing
    | the Blade pages costs the API nothing. That split is the entire reason
    | there are two arrays here rather than one compromise.
    |
    */

    'security_headers' => [
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
        'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ],

    /*
    |--------------------------------------------------------------------------
    | Header values — web (Blade) profile
    |--------------------------------------------------------------------------
    |
    | Applied to every other request. The API profile would break an HTML page
    | outright — "default-src 'none'" blocks the stylesheet and
    | "form-action 'none'" blocks every POST, including sign-in.
    |
    | This profile is still an allowlist: same-origin only, and deliberately
    | no 'unsafe-inline' and no 'unsafe-eval'. The scaffolded Blade templates
    | contain no inline <script>, no onclick= and no inline style attributes,
    | which is what makes that affordable — and what keeps a stored XSS from
    | executing even if one is ever injected (OWASP A05).
    |
    | If you add a script or a font from another origin, name that origin here.
    | Do not reach for 'unsafe-inline': it disables the protection wholesale
    | for the sake of one handler that belongs in a file anyway.
    |
    */

    'web_security_headers' => [
        'Content-Security-Policy' => implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]),
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        // Looser than the API's no-referrer so ordinary outbound links still
        // work, but a path is never sent to another origin.
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security
    |--------------------------------------------------------------------------
    |
    | Only ever sent over an already-secure connection (OWASP A04). Enable
    | "preload" once you are certain every subdomain is HTTPS-only.
    |
    */

    'hsts' => [
        'enabled' => env('CACHEWRAITH_HSTS', true),
        'max_age' => 31536000,
        'include_subdomains' => true,
        'preload' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Force HTTPS
    |--------------------------------------------------------------------------
    |
    | Applied in AppServiceProvider via URL::forceScheme so generated URLs
    | never downgrade to http. Null means "on outside local and testing".
    |
    */

    'force_https' => env('CACHEWRAITH_FORCE_HTTPS', null),

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    |
    | Buckets read by App\Http\Middleware\RateLimitApi, selected per route:
    | ->middleware('ratelimit.api') or ->middleware('ratelimit.api:login').
    | Add a bucket here and name it from the route (OWASP A06 / A07).
    |
    */

    'enable_rate_limiting' => env('CACHEWRAITH_RATE_LIMITING', true),

    'rate_limits' => [
        'api' => ['attempts' => 60, 'per_minutes' => 1],
        'login' => ['attempts' => 5, 'per_minutes' => 1],
    ],

    /*
    |--------------------------------------------------------------------------
    | API versioning
    |--------------------------------------------------------------------------
    |
    | Every entry is loaded from routes/api_{version}.php under the prefix
    | "{api_version_prefix}/{version}", with the api middleware group and the
    | route-name prefix "{version}.".
    |
    | Adding v2 is: write routes/api_v2.php, add 'v2' below. Never edit a
    | released version in place (Open/Closed).
    |
    */

    'api_version_prefix' => env('CACHEWRAITH_API_PREFIX', 'api'),

    'versions' => ['v1'],

    /*
    |--------------------------------------------------------------------------
    | Web UI (Blade)
    |--------------------------------------------------------------------------
    |
    | The Blade front door, loaded from routes/web_ui.php under the "web"
    | middleware group. Your own routes/web.php is never touched by the
    | installer and keeps working alongside it.
    |
    | There is no version prefix here on purpose: versioning is a contract
    | with clients you cannot redeploy, and the only consumer of these pages
    | is a browser you ship the HTML to. Change the page and ship it.
    |
    | "prefix" is a URL prefix, empty by default, so the pages sit at /login,
    | /dashboard and /items. Set it to "app" or "admin" to move the whole UI
    | under one path without editing a single route.
    |
    | Turning "enabled" off unregisters every scaffolded page in one switch —
    | useful when a deployment should expose the API only.
    |
    */

    'web' => [
        'enabled' => env('CACHEWRAITH_WEB', true),
        'prefix' => env('CACHEWRAITH_WEB_PREFIX', ''),
        'routes_file' => 'routes/web_ui.php',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | max_per_page is enforced by App\Traits\ApiResponse so a client cannot ask
    | for an unbounded result set (OWASP A06: Insecure Design).
    |
    */

    'pagination' => [
        'per_page' => 15,
        'max_per_page' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | Reported alongside minor-unit amounts by the v2 resources. Prices are
    | stored as integers; a float would accumulate rounding error.
    |
    */

    'currency' => env('CACHEWRAITH_CURRENCY', 'USD'),

];
