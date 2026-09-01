<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Item;
use App\Models\User;
use App\Repositories\Contracts\ItemRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eloquent-backed implementation of {@see ItemRepositoryInterface}.
 *
 * OWASP A05: values are bound by the query builder, never concatenated. The
 * $status filter is checked against an allowlist before it reaches where(),
 * because an unbounded string column filter is both an injection surface in
 * any future raw rewrite and a way to probe the schema.
 *
 * Performance: the listing eager-loads owner. Without it, a page of 15 items
 * whose resource touches $item->owner issues 16 queries (N+1).
 */
final class ItemRepository implements ItemRepositoryInterface
{
    /** @var list<string> */
    private const STATUSES = ['draft', 'published', 'archived'];

    public function __construct(private readonly Item $model)
    {
    }

    /**
     * @return LengthAwarePaginator<int, Item>
     */
    public function paginateForOwner(User $owner, int $perPage = 15, ?string $status = null): LengthAwarePaginator
    {
        $query = $this->model
            ->newQuery()
            ->with('owner')
            ->where('user_id', $owner->getKey());

        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $query->where('status', $status);
        }

        return $query->latest('id')->paginate($perPage);
    }

    public function findById(int|string $id): ?Item
    {
        return $this->model->newQuery()->with('owner')->find($id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createForOwner(User $owner, array $attributes): Item
    {
        // Ownership is applied here, from the User the service resolved — the
        // attributes array can never carry a user_id, because Item::$fillable
        // does not list one (OWASP A01).
        $item = $this->model->newInstance($attributes);
        $item->owner()->associate($owner);
        $item->save();

        return $item->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Item $item, array $attributes): Item
    {
        $item->fill($attributes)->save();

        return $item->refresh();
    }

    public function delete(Item $item): bool
    {
        return (bool) $item->delete();
    }
}
