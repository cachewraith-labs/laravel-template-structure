<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Item;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Persistence contract for the Item aggregate root.
 *
 * Note what the read methods take: paginateForOwner() requires a User rather
 * than offering an optional filter. The scope is part of the contract, so
 * there is no unscoped listing for a caller to reach for by accident (OWASP
 * A01 — an "all items" method is the one an incautious controller will call).
 */
interface ItemRepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, Item>
     */
    public function paginateForOwner(User $owner, int $perPage = 15, ?string $status = null): LengthAwarePaginator;

    public function findById(int|string $id): ?Item;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createForOwner(User $owner, array $attributes): Item;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Item $item, array $attributes): Item;

    public function delete(Item $item): bool;
}
