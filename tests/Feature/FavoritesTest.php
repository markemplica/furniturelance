<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoritesTest extends TestCase
{
    use RefreshDatabase;

    public function test_favorites_are_private_and_can_be_removed(): void
    {
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $saved = FurnitureProduct::create(['name' => 'Saved Chair', 'slug' => 'saved-chair', 'category' => 'Chair', 'description' => 'Wooden chair', 'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural', 'style' => 'Modern', 'price' => 1000, 'status' => 'active']);
        $private = FurnitureProduct::create(['name' => 'Other Customer Chair', 'slug' => 'other-chair', 'category' => 'Chair', 'description' => 'Wooden chair', 'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural', 'style' => 'Modern', 'price' => 1000, 'status' => 'active']);
        $customer->favorites()->attach($saved);
        $other->favorites()->attach($private);

        $this->get(route('favorites.index'))->assertRedirect(route('login', ['redirect' => route('favorites.index')]));
        $this->actingAs($customer)->get(route('favorites.index'))->assertOk()
            ->assertSee('Saved Chair')->assertDontSee('Other Customer Chair');
        $this->from(route('favorites.index'))->post(route('catalog.favorite', $saved))
            ->assertRedirect(route('favorites.index'));
        $this->get(route('favorites.index'))->assertOk()->assertSee('No favorites yet.');
        $this->assertDatabaseHas('favorites', ['user_id' => $other->id, 'furniture_product_id' => $private->id]);
    }
}


