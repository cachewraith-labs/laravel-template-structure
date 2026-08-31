<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The v1 representation of a user.
 *
 * OWASP A04 (Sensitive Data Exposure) / A07 (XSS in a consuming client): a
 * resource is an allowlist, not a filter. Returning a model directly leaks
 * whatever columns a future migration adds — a remember_token, an internal
 * flag, a soft-deleted marker. Name every field you intend to expose, and never
 * add password, remember_token or raw token values to this array.
 *
 * Open/Closed: this class belongs to v1. When v2 needs a different shape, add
 * App\Http\Resources\Api\V2\UserResource; do not reshape this one — existing
 * clients are relying on it.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->when(
                // Only the account owner (or someone allowed to view them in
                // full) sees the address; listings stay non-enumerable.
                $request->user()?->can('view', $this->resource) ?? false,
                fn () => $this->email,
            ),
            'email_verified' => $this->email_verified_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
