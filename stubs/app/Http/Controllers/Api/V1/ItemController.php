<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreItemRequest;
use App\Http\Requests\V1\UpdateItemRequest;
use App\Http\Resources\V1\ItemResource;
use App\Models\Item;
use App\Services\ItemService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * v1 item endpoints — the reference vertical slice.
 *
 * OWASP A01: index() never accepts an owner parameter. The listing is scoped
 * to $request->user() by the service, so there is no "?user_id=" for a client
 * to walk. show/update/destroy resolve the Item from the route binding and
 * authorise against that instance.
 *
 * Open/Closed: frozen at v1. The V2 controller is a sibling, not an edit.
 */
final class ItemController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ItemService $items)
    {
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Item::class);

        $items = $this->items->paginateForOwner(
            $request->user(),
            $this->perPage($request->integer('per_page') ?: null),
            $request->string('status')->toString() ?: null,
        );

        return $this->respondPaginated(ItemResource::collection($items), $items);
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $item = $this->items->create(
            $request->user(),
            $request->validated(),
            $request->user()->getKey(),
        );

        return $this->respondCreated(ItemResource::make($item), 'Item created.');
    }

    public function show(Request $request, Item $item): JsonResponse
    {
        Gate::authorize('view', $item);

        return $this->respondSuccess(ItemResource::make($item));
    }

    public function update(UpdateItemRequest $request, Item $item): JsonResponse
    {
        $updated = $this->items->update(
            $item,
            $request->validated(),
            $request->user()->getKey(),
        );

        return $this->respondSuccess(ItemResource::make($updated), 'Item updated.');
    }

    public function destroy(Request $request, Item $item): JsonResponse
    {
        Gate::authorize('delete', $item);

        $this->items->delete($item, $request->user()->getKey());

        return $this->respondSuccess(null, 'Item deleted.');
    }
}
