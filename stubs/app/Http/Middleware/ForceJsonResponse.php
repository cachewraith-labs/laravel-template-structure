<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces every API request to be treated as a JSON request.
 *
 * Prepended to the api middleware group, so it runs before authentication and
 * before validation. Without it, a client that omits Accept: application/json
 * gets Laravel's HTML behaviour on the error paths: a redirect to a login route
 * that may not exist, or a rendered exception page. Both are worse than a JSON
 * error — the second can leak stack frames, file paths and environment values
 * (OWASP A02 / A10).
 */
final class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
