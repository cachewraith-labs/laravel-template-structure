<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreItemRequest;
use App\Http\Requests\V1\UpdateItemRequest;
use App\Http\Resources\V2\ItemResource;
use App\Models\Item;
use App\Services\ItemService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * v2 item endpoints — only the parts whose contract actually changed.
 *
 * This class exists because V2\ItemResource reshapes the response. It still
 * uses the *v1* FormRequests: the accepted input did not change, and copying
 * them would create a second place to fix the next validation bug. Copy a
 * class into V2 only when its contract genuinely differs — a V2 directory that
 * mirrors V1 file for file is duplication, not versioning.
 *
 * The service, repository, model and policy are shared outright. Versioning is
 * a property of the HTTP boundary; the domain underneath has one version.
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

        return $this->respondCreated(ItemResource::make($this->withOwner($item)), 'Item created.');
    }

    public function show(Request $request, Item $item): JsonResponse
    {
        Gate::authorize('view', $item);

        return $this->respondSuccess(ItemResource::make($this->withOwner($item)));
    }

    public function update(UpdateItemRequest $request, Item $item): JsonResponse
    {
        $updated = $this->items->update(
            $item,
            $request->validated(),
            $request->user()->getKey(),
        );

        return $this->respondSuccess(ItemResource::make($this->withOwner($updated)), 'Item updated.');
    }

    public function destroy(Request $request, Item $item): JsonResponse
    {
        Gate::authorize('delete', $item);

        $this->items->delete($item, $request->user()->getKey());

        return $this->respondSuccess(null, 'Item deleted.');
    }

    /**
     * v2 exposes an "owner" block, and V2\ItemResource guards it with
     * whenLoaded so a listing cannot trigger N+1. Route-model binding does not
     * eager-load anything, though, so a single-record response would silently
     * omit the field — a missing key is a worse bug than a slow one, because
     * nothing fails. loadMissing() is a no-op when the relation is already
     * there, so the listing path stays a single extra query.
     */
    private function withOwner(Item $item): Item
    {
        return $item->loadMissing('owner');
    }
}
