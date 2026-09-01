<?php

declare(strict_types=1);

namespace Tests\Feature\V1;

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for the v1 item endpoints.
 *
 * Every access-control test uses two users. A fixture with a single user
 * cannot fail an ownership check, which is exactly why single-user fixtures
 * let IDOR bugs through code review (OWASP A01).
 */
final class ItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_listing_returns_only_the_callers_items(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        Item::factory()->count(3)->ownedBy($owner)->create();
        Item::factory()->count(4)->ownedBy($stranger)->create();

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/items')->assertOk();

        $this->assertCount(3, $response->json('data'));
        $this->assertSame(3, $response->json('pagination.total'));
    }

    public function test_an_item_is_created_for_the_authenticated_caller(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/items', [
            'name' => 'Reference item',
            'description' => 'Created by a feature test.',
            'price_cents' => 1999,
        ])->assertCreated()->assertJsonPath('data.price', '19.99');

        $this->assertDatabaseHas('items', [
            'name' => 'Reference item',
            'user_id' => $owner->getKey(),
        ]);
    }

    /**
     * OWASP A01: ownership comes from the token, never the payload. A
     * client-supplied user_id must be ignored, not honoured.
     */
    public function test_a_client_supplied_owner_id_is_ignored(): void
    {
        $owner = User::factory()->create();
        $victim = User::factory()->create();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/items', [
            'name' => 'Planted item',
            'price_cents' => 100,
            'user_id' => $victim->getKey(),
        ])->assertCreated();

        $this->assertDatabaseHas('items', [
            'name' => 'Planted item',
            'user_id' => $owner->getKey(),
        ]);
        $this->assertDatabaseMissing('items', [
            'name' => 'Planted item',
            'user_id' => $victim->getKey(),
        ]);
    }

    /**
     * OWASP A01: the canonical IDOR check. Guessing another user's item id
     * must not read, edit or delete it.
     */
    public function test_a_stranger_cannot_reach_someone_elses_item(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $item = Item::factory()->ownedBy($owner)->create();

        Sanctum::actingAs($stranger);

        $this->getJson("/api/v1/items/{$item->getKey()}")->assertForbidden();
        $this->patchJson("/api/v1/items/{$item->getKey()}", ['name' => 'Hijacked'])->assertForbidden();
        $this->deleteJson("/api/v1/items/{$item->getKey()}")->assertForbidden();

        $this->assertDatabaseHas('items', ['id' => $item->getKey(), 'name' => $item->name]);
    }

    public function test_validation_rejects_an_out_of_range_price(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/items', [
            'name' => 'Negative',
            'price_cents' => -1,
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'errors', 'code']);
    }

    /**
     * OWASP A06: validation knows the vocabulary of statuses, the service
     * knows which transitions the domain allows.
     */
    public function test_an_archived_item_cannot_be_returned_to_draft(): void
    {
        $owner = User::factory()->create();
        $item = Item::factory()->ownedBy($owner)->create(['status' => 'archived']);

        Sanctum::actingAs($owner);

        // ItemPolicy::update closes archived records outright.
        $this->patchJson("/api/v1/items/{$item->getKey()}", ['status' => 'draft'])
            ->assertForbidden();
    }

    public function test_per_page_is_clamped_to_the_configured_maximum(): void
    {
        $owner = User::factory()->create();
        Item::factory()->count(3)->ownedBy($owner)->create();

        Sanctum::actingAs($owner);

        $max = (int) config('cachewraith-template.pagination.max_per_page', 100);

        $this->getJson('/api/v1/items?per_page=1000000')
            ->assertOk()
            ->assertJsonPath('pagination.per_page', $max);
    }

    /**
     * OWASP A02: the hardening headers are applied globally, so an endpoint
     * added later inherits them. This test is what keeps that true.
     */
    public function test_responses_carry_the_security_headers(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/items')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }
}
