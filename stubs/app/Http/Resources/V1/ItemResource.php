<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The v1 representation of an item.
 *
 * v1 exposes price as a decimal string of major units — a shape that turned
 * out to be ambiguous without a currency. V2\ItemResource fixes it, and this
 * class stays exactly as it is for as long as v1 is supported. That is the
 * whole reason both files exist.
 *
 * OWASP A04: an allowlist. user_id is deliberately not exposed — an
 * incrementing owner id is an enumeration aid and tells a client nothing it
 * cannot get from /users/me.
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
            'price' => number_format($this->price_cents / 100, 2, '.', ''),
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
