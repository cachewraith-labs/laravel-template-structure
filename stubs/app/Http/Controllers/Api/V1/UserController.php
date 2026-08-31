<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\UserData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Requests\Api\V1\UpdateUserRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * v1 user endpoints.
 *
 * A controller does four things and nothing else: authorise, take already
 * validated input, hand it to a service, and shape the response. There is no
 * business logic and no query building here — if either starts to appear, it
 * belongs in App\Services or App\Repositories.
 *
 * OWASP A01 (Broken Access Control): every method authorises. Write methods are
 * gated in their FormRequest via the policy; read methods call Gate::authorize
 * explicitly, because a missing check on a read is just as much a leak.
 *
 * Open/Closed: this class is frozen once v1 ships. A breaking change means a
 * new App\Http\Controllers\Api\V2\UserController and a new route file, so
 * existing clients keep working.
 */
final class UserController extends Controller
{
    public function __construct(private readonly UserService $users)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        return UserResource::collection(
            $this->users->paginate($this->perPage($request))
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        // Authorised by StoreUserRequest::authorize() before we get here.
        $user = $this->users->create(
            UserData::fromRequest($request),
            $request->user()?->getKey(),
        );

        return UserResource::make($user)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return UserResource::make($user);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        // Only validated keys are forwarded — never $request->all() (A01).
        $updated = $this->users->update(
            $user,
            $request->validated(),
            $request->user()?->getKey(),
        );

        return UserResource::make($updated);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        Gate::authorize('delete', $user);

        $this->users->delete($user, $request->user()?->getKey());

        return response()->json([
            'success' => true,
            'message' => 'User deleted.',
            'errors' => null,
            'code' => Response::HTTP_OK,
        ]);
    }

    /**
     * Clamp client-controlled pagination (OWASP A06: a request for
     * ?per_page=1000000 is a cheap denial-of-service otherwise).
     */
    private function perPage(Request $request): int
    {
        $default = (int) config('cachewraith-template.pagination.per_page', 15);
        $max = (int) config('cachewraith-template.pagination.max_per_page', 100);

        return max(1, min($request->integer('per_page', $default), $max));
    }
}
