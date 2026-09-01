<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the Blade item pages.
 *
 * The mirror of Tests\Feature\V1\ItemTest. Both suites assert the same
 * properties against the same policies and the same service, because the
 * front door is the only thing that differs — and a rule that holds over JSON
 * but not over HTML is a rule that does not hold.
 *
 * Every access-control test uses two users. A fixture with a single user
 * cannot fail an ownership check, which is exactly why single-user fixtures
 * let IDOR bugs through code review (OWASP A01).
 */
final class ItemPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_listing_shows_only_the_visitors_items(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        Item::factory()->ownedBy($owner)->create(['name' => 'Belongs to the visitor']);
        Item::factory()->ownedBy($stranger)->create(['name' => 'Belongs to someone else']);

        $this->actingAs($owner)
            ->get('/items')
            ->assertOk()
            ->assertSee('Belongs to the visitor')
            ->assertDontSee('Belongs to someone else');
    }

    /**
     * OWASP A01 (IDOR): the item is resolved from the URL and ItemPolicy is
     * asked about that instance. Guessing a neighbouring id must not work.
     */
    public function test_a_stranger_cannot_open_or_edit_someone_elses_item(): void
    {
        $stranger = User::factory()->create();
        $item = Item::factory()->ownedBy(User::factory()->create())->create();

        $this->actingAs($stranger)->get('/items/'.$item->getKey())->assertForbidden();
        $this->actingAs($stranger)->get('/items/'.$item->getKey().'/edit')->assertForbidden();
        $this->actingAs($stranger)->put('/items/'.$item->getKey(), [
            'name' => 'Taken over',
            'price' => '1.00',
            'status' => 'draft',
        ])->assertForbidden();
        $this->actingAs($stranger)->delete('/items/'.$item->getKey())->assertForbidden();

        $this->assertDatabaseHas('items', ['id' => $item->getKey(), 'name' => $item->name]);
    }

    /**
     * OWASP A01 (mass assignment): user_id is not fillable and the service
     * sets ownership from the authenticated visitor, so a hidden field added
     * to the form changes nothing.
     */
    public function test_a_form_supplied_owner_id_is_ignored(): void
    {
        $owner = User::factory()->create();
        $victim = User::factory()->create();

        $this->actingAs($owner)->post('/items', [
            'name' => 'Planted',
            'description' => null,
            'price' => '5.00',
            'status' => 'draft',
            'user_id' => $victim->getKey(),
        ])->assertRedirect();

        $this->assertDatabaseHas('items', ['name' => 'Planted', 'user_id' => $owner->getKey()]);
        $this->assertDatabaseMissing('items', ['name' => 'Planted', 'user_id' => $victim->getKey()]);
    }

    /**
     * The decimal the form posts and the integer minor units the column
     * stores are one conversion, in App\Support\Money. 1.15 * 100 is
     * 114.99999999999999 in binary floating point, which is why it is parsed
     * from the string.
     */
    public function test_a_decimal_price_is_stored_as_exact_minor_units(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post('/items', [
            'name' => 'Priced',
            'price' => '1.15',
            'status' => 'published',
        ])->assertRedirect();

        $this->assertDatabaseHas('items', ['name' => 'Priced', 'price_cents' => 115]);
    }

    public function test_an_unparseable_price_is_rejected_rather_than_stored_as_zero(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->from('/items/create')
            ->post('/items', ['name' => 'Junk price', 'price' => '1,999.00', 'status' => 'draft'])
            ->assertRedirect('/items/create')
            ->assertSessionHasErrors(['price_cents']);

        $this->assertDatabaseMissing('items', ['name' => 'Junk price']);
    }

    /**
     * OWASP A06: the domain rule lives in ItemService and is enforced whether
     * the request arrived as JSON or as a form post. On the web the refusal
     * comes back on the form instead of as a 422 body.
     */
    public function test_an_archived_item_cannot_be_returned_to_draft(): void
    {
        $owner = User::factory()->create();
        $item = Item::factory()->ownedBy($owner)->create(['status' => 'archived']);

        // ItemPolicy::update closes archived items outright, so the request
        // never even reaches the transition rule — defence in depth, and the
        // reason both checks exist.
        $this->actingAs($owner)
            ->put('/items/'.$item->getKey(), ['name' => $item->name, 'price' => '1.00', 'status' => 'draft'])
            ->assertForbidden();

        $this->assertDatabaseHas('items', ['id' => $item->getKey(), 'status' => 'archived']);
    }

    /**
     * The transition rule reaching the form.
     *
     * ItemService refuses published -> draft with a 422, which is right for
     * the API. ItemController translates that one status into an error bag on
     * the field that caused it, so the visitor lands back on the form with
     * their input intact instead of on a bare error page.
     */
    public function test_an_illegal_transition_comes_back_on_the_form(): void
    {
        $owner = User::factory()->create();
        $item = Item::factory()->ownedBy($owner)->create(['status' => 'published']);

        $this->actingAs($owner)
            ->from('/items/'.$item->getKey().'/edit')
            ->put('/items/'.$item->getKey(), [
                'name' => $item->name,
                'price' => '1.00',
                'status' => 'draft',
            ])
            ->assertRedirect('/items/'.$item->getKey().'/edit')
            ->assertSessionHasErrors(['status']);

        $this->assertDatabaseHas('items', ['id' => $item->getKey(), 'status' => 'published']);
    }

    /**
     * OWASP A05 (XSS): the whole reason every interpolation in this scaffold
     * is {{ }} and never {!! !!}. A stored payload must arrive as text.
     */
    public function test_item_content_is_escaped_in_the_rendered_page(): void
    {
        $owner = User::factory()->create();
        $item = Item::factory()->ownedBy($owner)->create([
            'name' => '<script>alert(1)</script>',
            'description' => '<img src=x onerror=alert(2)>',
        ]);

        $response = $this->actingAs($owner)->get('/items/'.$item->getKey())->assertOk();

        $html = $response->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(2)>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    /**
     * OWASP A04: a page rendered for a signed-in visitor must not be written
     * to a shared or on-disk cache — it is still there after they sign out.
     */
    public function test_pages_rendered_for_a_signed_in_visitor_are_not_stored(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_a_guest_reaches_no_item_page_at_all(): void
    {
        $item = Item::factory()->ownedBy(User::factory()->create())->create();

        $this->get('/items')->assertRedirect('/login');
        $this->get('/items/create')->assertRedirect('/login');
        $this->get('/items/'.$item->getKey())->assertRedirect('/login');
        $this->post('/items', ['name' => 'x'])->assertRedirect('/login');
    }
}
