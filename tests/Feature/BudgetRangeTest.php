<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetRangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_budget_range_saves_both_bounds_and_rejects_invalid_ranges(): void
    {
        $customer = User::factory()->create();
        \App\Models\FurnitureProduct::create(['name' => 'Budget Chair', 'slug' => 'budget-chair', 'description' => 'Chair', 'category' => 'Chair', 'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural', 'style' => 'Modern', 'price' => 1000, 'status' => 'active']);
        $this->actingAs($customer)->get(route('preferences.edit'))->assertOk()->assertSee('Budget range')->assertDontSee('Minimum budget PHP');
        foreach (['1000-10000', '10001-20000', '20001-30000'] as $range) {
            $this->postJson(route('preferences.update'), ['furniture_type' => \App\Models\FurnitureProduct::recommendationTypes()->first(), 'budget_range' => $range])
                ->assertOk();
            [$minimum, $maximum] = explode('-', $range);
            $this->assertDatabaseHas('customer_preferences', ['user_id' => $customer->id, 'budget_min' => $minimum, 'budget_max' => $maximum]);
        }
        $this->get(route('preferences.edit'))->assertOk()->assertSee('value="20001-30000" selected', false);
        foreach (['invalid', '20000-1000', ''] as $range) {
            $this->postJson(route('preferences.update'), ['furniture_type' => \App\Models\FurnitureProduct::recommendationTypes()->first(), 'budget_range' => $range])->assertUnprocessable();
        }
    }
}

