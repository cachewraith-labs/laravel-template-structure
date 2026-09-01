<?php

declare(strict_types=1);

namespace App\Http\Resources\V2;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The v2 representation of an item — a deliberate breaking change.
 *
 * What changed from v1, and why it needed a new version rather than an edit:
 *
 *  - "price" is now an object {amount_minor, currency} instead of a decimal
 *    string. Any v1 client doing parseFloat(item.price) breaks on an object.
 *  - the timestamps moved under "timestamps". A v1 client reading
 *    item.created_at gets undefined.
 *  - "owner" was added. Additive on its own, but it ships with the rest.
 *
 * Open/Closed in practice: V1\ItemResource was not touched to produce this
 * file. v1 clients keep the contract they integrated against, and migrate when
 * they choose.
 *
 * @mixin Item
 */
final class ItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => [
                'amount_minor' => (int) $this->price_cents,
                'currency' => (string) config('cachewraith-template.currency', 'USD'),
            ],
            'status' => $this->status,
            // whenLoaded, not ->owner: without it a page of items issues one
            // query per row (N+1). ItemRepository eager-loads the relation.
            'owner' => $this->whenLoaded('owner', fn (): array => [
                'id' => $this->owner->getKey(),
                'name' => $this->owner->name,
            ]),
            'timestamps' => [
                'created_at' => $this->created_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
            ],
        ];
    }
}
