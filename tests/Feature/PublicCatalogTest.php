<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_browse_active_products_but_account_actions_require_login(): void
    {
        $product = FurnitureProduct::create(['name' => 'Public Chair', 'slug' => 'public-chair', 'description' => 'Wooden chair', 'category' => 'Chair', 'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural', 'style' => 'Modern', 'price' => 1000, 'status' => 'active']);
        $hidden = FurnitureProduct::create(['name' => 'Hidden Chair', 'slug' => 'hidden-chair', 'description' => 'Wooden chair', 'category' => 'Chair', 'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural', 'style' => 'Modern', 'price' => 1000, 'status' => 'inactive']);
        $this->get(route('catalog.index'))->assertOk()->assertSee('Public Chair')->assertDontSee('Hidden Chair')->assertSee('Log in');
        $this->getJson(route('catalog.search', ['q' => 'Chair']))->assertOk()->assertJsonCount(1)->assertJsonPath('0.saved', false);
        $this->get(route('catalog.show', $product))->assertOk()->assertSee('Public Chair')->assertSee('Log in to request reservation')->assertDontSee('data-reservation-form', false);
        $this->get(route('catalog.show', $hidden))->assertNotFound();
        $this->postJson(route('catalog.favorite', $product))->assertUnauthorized();
        $this->postJson(route('reservations.store'), ['furniture_product_id' => $product->id])->assertUnauthorized();
        $this->getJson(route('favorites.index'))->assertUnauthorized();
        $this->assertDatabaseCount('favorites', 0);
        $this->assertDatabaseCount('reservations', 0);
    }
}
