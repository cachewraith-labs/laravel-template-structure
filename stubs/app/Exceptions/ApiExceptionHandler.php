<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Renders every API failure as one envelope:
 *
 *     {"success": false, "message": "...", "errors": {...}|null, "code": 422}
 *
 * Chain of Responsibility: each mapper below claims the exceptions it
 * recognises and the rest fall through to the catch-all, so adding a new
 * translation never touches the existing ones (Open/Closed). Clients get to
 * parse one shape instead of guessing between Laravel's HTML page, its
 * validation JSON, and a bare 500.
 *
 * OWASP A10 (Mishandling of Exceptional Conditions) and A02: an unrecognised
 * exception becomes a generic 500. The class name, message, file, line and
 * stack trace are attached only when APP_DEBUG is on — those strings routinely
 * carry absolute paths, SQL fragments and credentials, and APP_DEBUG must be
 * false in production.
 *
 * OWASP A01: "not found" and "forbidden" are reported exactly as raised. If an
 * endpoint must not reveal that a record exists at all, have the policy or
 * controller raise a 404 instead of a 403 — do not try to blur it here.
 */
final class ApiExceptionHandler
{
    /**
     * Laravel 11/12: call from bootstrap/app.php inside withExceptions().
     *
     * @param  \Illuminate\Foundation\Configuration\Exceptions  $exceptions
     */
    public static function register($exceptions): void
    {
        $handler = new self();

        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request, Throwable $e): bool => $handler->wantsJson($request)
        );

        $exceptions->render(
            static fn (Throwable $e, Request $request): ?JsonResponse => $handler->render($request, $e)
        );
    }

    /**
     * Laravel 10: call from App\Exceptions\Handler::register().
     */
    public function render(Request $request, Throwable $e): ?JsonResponse
    {
        if (! $this->wantsJson($request)) {
            return null;
        }

        [$status, $message, $errors] = $this->translate($e);

        $payload = [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'code' => $status,
        ];

        if (config('app.debug') && $status >= 500) {
            $payload['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }

        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        return response()->json($payload, $status, $headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{0: int, 1: string, 2: array<string, array<int, string>>|null}
     */
    private function translate(Throwable $e): array
    {
        return match (true) {
            $e instanceof ValidationException => [
                $e->status,
                'The given data was invalid.',
                $e->errors(),
            ],

            $e instanceof AuthenticationException => [
                401,
                'Unauthenticated.',
                null,
            ],

            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => [
                403,
                'This action is unauthorized.',
                null,
            ],

            // Deliberately generic: the model class name is an internal detail.
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => [
                404,
                'Resource not found.',
                null,
            ],

            $e instanceof MethodNotAllowedHttpException => [
                405,
                'The requested method is not supported for this route.',
                null,
            ],

            $e instanceof TooManyRequestsHttpException => [
                429,
                'Too many requests. Please slow down.',
                null,
            ],

            // Any other deliberate HTTP error keeps its status; its message is
            // used only when the application set one explicitly.
            $e instanceof HttpExceptionInterface => [
                $e->getStatusCode(),
                $e->getMessage() !== '' ? $e->getMessage() : 'Request failed.',
                null,
            ],

            // Catch-all. Nothing about the exception crosses the wire.
            default => [
                500,
                'Server error. The failure has been logged.',
                null,
            ],
        };
    }

    private function wantsJson(Request $request): bool
    {
        $prefix = trim((string) config('smn-template.api_version_prefix', 'api'), '/');

        return $request->is($prefix.'/*') || $request->expectsJson();
    }
}
