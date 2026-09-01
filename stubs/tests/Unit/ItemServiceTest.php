<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Item;
use App\Models\User;
use App\Repositories\Contracts\ItemRepositoryInterface;
use App\Services\ItemService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Unit tests for ItemService, against a hand-written fake repository.
 *
 * This is the payoff for depending on ItemRepositoryInterface instead of
 * Eloquent: the service's rules are tested with no database, no migrations and
 * no HTTP. A test that needed RefreshDatabase to assert a status transition
 * would be testing the schema, not the rule.
 */
final class ItemServiceTest extends TestCase
{
    public function test_a_draft_may_be_published(): void
    {
        $item = $this->item('draft');
        $service = new ItemService($this->repository());

        $updated = $service->update($item, ['status' => 'published']);

        $this->assertSame('published', $updated->status);
    }

    public function test_an_archived_item_cannot_return_to_draft(): void
    {
        $item = $this->item('archived');
        $service = new ItemService($this->repository());

        $this->expectException(HttpException::class);

        $service->update($item, ['status' => 'draft']);
    }

    public function test_a_no_op_transition_is_allowed(): void
    {
        $item = $this->item('published');
        $service = new ItemService($this->repository());

        $updated = $service->update($item, ['status' => 'published', 'name' => 'Renamed']);

        $this->assertSame('published', $updated->status);
        $this->assertSame('Renamed', $updated->name);
    }

    public function test_creation_associates_the_given_owner(): void
    {
        $owner = new User(['name' => 'Owner']);
        $owner->id = 7;
        $owner->exists = true;

        $service = new ItemService($this->repository());

        $item = $service->create($owner, ['name' => 'New', 'price_cents' => 500]);

        $this->assertSame(7, $item->user_id);
    }

    private function item(string $status): Item
    {
        $item = new Item(['name' => 'Fixture', 'price_cents' => 100, 'status' => $status]);
        $item->id = 1;
        $item->user_id = 7;
        $item->exists = true;

        return $item;
    }

    /**
     * An in-memory stand-in. Written by hand rather than mocked: the fake
     * documents the contract, and it fails to compile the day the interface
     * changes — which a mock quietly would not.
     */
    private function repository(): ItemRepositoryInterface
    {
        return new class implements ItemRepositoryInterface
        {
            /**
             * @return LengthAwarePaginator<int, Item>
             */
            public function paginateForOwner(User $owner, int $perPage = 15, ?string $status = null): LengthAwarePaginator
            {
                throw new \LogicException('Not used by these tests.');
            }

            public function findById(int|string $id): ?Item
            {
                return null;
            }

            /**
             * @param  array<string, mixed>  $attributes
             */
            public function createForOwner(User $owner, array $attributes): Item
            {
                $item = new Item($attributes);
                $item->id = 1;
                $item->user_id = $owner->getKey();
                $item->exists = true;

                return $item;
            }

            /**
             * @param  array<string, mixed>  $attributes
             */
            public function update(Item $item, array $attributes): Item
            {
                $item->forceFill($attributes);

                return $item;
            }

            public function delete(Item $item): bool
            {
                return true;
            }
        };
    }
}
