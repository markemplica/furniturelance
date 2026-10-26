<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_search_partial_customer_names_and_emails_and_clear_search(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['name' => 'Kristine Customer', 'email' => 'unique-customer@example.com']);
        User::factory()->create(['name' => 'Different Customer']);
        $this->actingAs($admin);
        foreach (['krist', 'unique-customer', '  krist  '] as $term) {
            $this->get(route('admin.customers.index', ['q' => $term]))->assertOk()
                ->assertSee('Kristine Customer')->assertDontSee('Different Customer')
                ->assertSee('data-report-results', false)->assertSee('data-report-search-error', false);
        }
        $this->get(route('admin.customers.index', ['q' => 'nomatch']))->assertOk()->assertSee('No customers found.');
        $this->get(route('admin.customers.index', ['q' => '']))->assertOk()->assertSee('Kristine Customer')->assertSee('Different Customer');
        $this->actingAs(User::factory()->create())->get(route('admin.customers.index'))->assertForbidden();
    }
}
