<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Item;
use App\Models\User;
use App\Repositories\Contracts\ItemRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Business logic for the Item aggregate.
 *
 * Every method takes the acting User explicitly rather than reaching for
 * auth()->user(). That is what makes the class testable without a request and
 * reusable from a command or a job — and it forces the caller to be honest
 * about whose authority the operation runs under.
 *
 * Authorisation is *not* re-implemented here: the FormRequest and the
 * controller ask ItemPolicy. A rule expressed in two places is a rule that
 * will diverge (OWASP A01).
 */
final class ItemService
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'draft' => ['published', 'archived'],
        'published' => ['archived'],
        'archived' => [],
    ];

    public function __construct(private readonly ItemRepositoryInterface $items)
    {
    }

    /**
     * @return LengthAwarePaginator<int, Item>
     */
    public function paginateForOwner(User $owner, int $perPage = 15, ?string $status = null): LengthAwarePaginator
    {
        return $this->items->paginateForOwner($owner, $perPage, $status);
    }

    /**
     * @param  array<string, mixed>  $attributes  Already validated by a FormRequest.
     */
    public function create(User $owner, array $attributes, ?int $actorId = null): Item
    {
        $item = DB::transaction(fn (): Item => $this->items->createForOwner($owner, $attributes));

        Log::info('item.created', [
            'item_id' => $item->getKey(),
            'owner_id' => $owner->getKey(),
            'actor_id' => $actorId,
            'ip' => request()->ip(),
        ]);

        return $item;
    }

    /**
     * @param  array<string, mixed>  $attributes  Already validated by a FormRequest.
     */
    public function update(Item $item, array $attributes, ?int $actorId = null): Item
    {
        if (isset($attributes['status'])) {
            $this->guardStatusTransition($item, (string) $attributes['status']);
        }

        $updated = DB::transaction(fn (): Item => $this->items->update($item, $attributes));

        Log::info('item.updated', [
            'item_id' => $updated->getKey(),
            'actor_id' => $actorId,
            'changed' => array_keys($attributes),
            'ip' => request()->ip(),
        ]);

        return $updated;
    }

    public function delete(Item $item, ?int $actorId = null): bool
    {
        $itemId = $item->getKey();
        $deleted = $this->items->delete($item);

        Log::warning('item.deleted', [
            'item_id' => $itemId,
            'actor_id' => $actorId,
            'ip' => request()->ip(),
        ]);

        return $deleted;
    }

    /**
     * OWASP A06 (Insecure Design): validation proves the value is a known
     * status; only the domain knows whether *this* item may move to it. A
     * client that resurrects an archived record by PATCHing status=draft is
     * exploiting the gap between the two checks.
     */
    private function guardStatusTransition(Item $item, string $target): void
    {
        $current = (string) $item->status;

        if ($current === $target) {
            return;
        }

        if (! in_array($target, self::TRANSITIONS[$current] ?? [], true)) {
            Log::warning('item.invalid_transition', [
                'item_id' => $item->getKey(),
                'from' => $current,
                'to' => $target,
                'ip' => request()->ip(),
            ]);

            abort(422, "An item cannot move from {$current} to {$target}.");
        }
    }
}
