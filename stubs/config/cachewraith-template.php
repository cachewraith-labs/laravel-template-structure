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
    | Header values
    |--------------------------------------------------------------------------
    |
    | Defaults are tuned for a JSON-only API: nothing may be loaded, framed or
    | executed. If you later serve HTML from the same application, relax
    | "Content-Security-Policy" for those routes only — never globally.
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
