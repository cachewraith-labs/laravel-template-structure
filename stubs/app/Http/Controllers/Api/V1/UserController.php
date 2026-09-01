<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreUserRequest;
use App\Http\Requests\V1\UpdateUserRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use App\Services\UserService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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
    use ApiResponse;

    public function __construct(private readonly UserService $users)
    {
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $users = $this->users->paginate($this->perPage($request->integer('per_page') ?: null));

        return $this->respondPaginated(UserResource::collection($users), $users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        // Authorised by StoreUserRequest::authorize() before we get here.
        $user = $this->users->create(
            $request->validated(),
            $request->user()?->getKey(),
        );

        return $this->respondCreated(UserResource::make($user), 'User created.');
    }

    public function show(Request $request, User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return $this->respondSuccess(UserResource::make($user));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        // Only validated keys are forwarded — never $request->all() (A01).
        $updated = $this->users->update(
            $user,
            $request->validated(),
            $request->user()?->getKey(),
        );

        return $this->respondSuccess(UserResource::make($updated), 'User updated.');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        Gate::authorize('delete', $user);

        $this->users->delete($user, $request->user()?->getKey());

        return $this->respondSuccess(null, 'User deleted.');
    }
}
