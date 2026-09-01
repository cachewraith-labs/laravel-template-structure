<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * One JSON envelope for every endpoint in the application.
 *
 * A trait rather than a base controller: PHP traits are the local spelling of
 * a mixin, so a controller keeps extending Laravel's own Controller and any
 * other class — a console command, a job — can adopt the same shape without
 * inheriting a web-layer parent. The alternative (an abstract ApiController)
 * would force single inheritance for no gain.
 *
 * The envelope matches App\Exceptions\Handler exactly, so a client parses one
 * shape whether the request succeeded, failed validation, or blew up:
 *
 *     {"success": bool, "message": string, "data": mixed|absent,
 *      "errors": object|null, "code": int}
 *
 * OWASP A10: the failure branch never carries an exception message, stack
 * frame or SQL fragment — callers pass a message they wrote deliberately.
 */
trait ApiResponse
{
    /**
     * @param  array<string, mixed>|JsonResource|ResourceCollection|null  $data
     */
    protected function respondSuccess(
        mixed $data = null,
        string $message = 'OK',
        int $status = Response::HTTP_OK,
        array $headers = [],
    ): JsonResponse {
        $payload = [
            'success' => true,
            'message' => $message,
            'errors' => null,
            'code' => $status,
        ];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $status, $headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function respondCreated(mixed $data = null, string $message = 'Created.'): JsonResponse
    {
        return $this->respondSuccess($data, $message, Response::HTTP_CREATED);
    }

    /**
     * @param  array<string, array<int, string>>|null  $errors
     */
    protected function respondError(
        string $message,
        int $status = Response::HTTP_BAD_REQUEST,
        ?array $errors = null,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'code' => $status,
        ], $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * A paginated collection, with the page metadata hoisted out of Laravel's
     * default "meta"/"links" wrapper so the envelope stays the same shape.
     *
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     */
    protected function respondPaginated(
        ResourceCollection $collection,
        LengthAwarePaginator $paginator,
        string $message = 'OK',
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $collection,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'errors' => null,
            'code' => Response::HTTP_OK,
        ], Response::HTTP_OK, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Clamp client-controlled pagination.
     *
     * OWASP A06 (Insecure Design): ?per_page=1000000 is a cheap
     * denial-of-service against an endpoint that trusts the parameter.
     */
    protected function perPage(?int $requested = null): int
    {
        $default = (int) config('cachewraith-template.pagination.per_page', 15);
        $max = (int) config('cachewraith-template.pagination.max_per_page', 100);

        return max(1, min($requested ?? $default, $max));
    }
}
