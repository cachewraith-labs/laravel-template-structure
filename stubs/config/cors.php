<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| OWASP A02 (Security Misconfiguration). The two mistakes this file exists to
| prevent:
|
|  1. allowed_origins => ['*'] together with supports_credentials => true.
|     Browsers reject that combination, and the usual "fix" is to reflect the
|     Origin header back — which grants every site on the internet authenticated
|     access to this API. Name your origins.
|
|  2. A wildcard pattern like '#^https?://.*\.example\.com$#'. It also matches
|     https://evil.com/#.example.com in some parsers. Prefer exact origins.
|
| CORS is a browser convenience, not an authorisation boundary: it does not stop
| a server-side client. Authorisation is still the policies' job (A01).
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    /*
    | Explicit allow-list, from the environment. Empty by default: a same-origin
    | or server-side client needs no CORS at all, so nothing is granted until
    | someone states which browser origins are expected.
    |
    |     CORS_ALLOWED_ORIGINS="https://app.example.com,https://admin.example.com"
    */
    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],

    /*
    | Response headers a browser client is allowed to read. The rate-limit
    | headers are here so a client can back off politely instead of hammering.
    */
    'exposed_headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After'],

    'max_age' => 3600,

    /*
    | Token-in-header authentication (Sanctum's API mode) does not need cookies.
    | Turn this on only for the cookie/SPA flow, and only with a real origin
    | list above — never with a wildcard.
    */
    'supports_credentials' => false,

];
