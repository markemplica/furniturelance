<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountDeactivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_customer_session_is_rejected_after_admin_deactivation(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($customer)->get('/profile')->assertOk();
        $this->actingAs($admin)->patch(route('admin.customers.update', $customer), ['is_active' => false])->assertRedirect();
        $this->actingAs($customer)->withSession(['private_marker' => 'old-session'])
            ->get('/profile')->assertRedirect(route('login'))->assertSessionMissing('private_marker');
        $this->assertGuest();
    }

    public function test_deactivated_customer_cannot_mutate_profile_through_json_request(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => false]);
        $oldHash = $customer->password;
        $this->actingAs($customer)->putJson('/profile/password', [
            'current_password' => 'password', 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
        ])->assertForbidden();
        $this->assertSame($oldHash, $customer->fresh()->password);
        $this->assertGuest();
    }

    public function test_active_customer_and_guest_login_page_remain_accessible(): void
    {
        $this->get('/login')->assertOk();
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $this->actingAs($customer)->get('/profile')->assertOk();
        $this->assertAuthenticatedAs($customer);
    }
}
