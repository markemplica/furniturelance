<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederAccountSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseeding_preserves_renamed_admin_and_existing_customer_security_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'lancefurniture15@gmail.com', 'password' => 'OwnerCustomPassword!', 'is_active' => false]);
        $customer = User::factory()->create(['role' => 'customer', 'email' => 'demo@furnicraft.local', 'password' => 'CustomerCustomPassword!', 'is_active' => false, 'email_verified_at' => null]);
        $adminBefore = $admin->fresh()->getAttributes();
        $customerBefore = $customer->fresh()->getAttributes();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($adminBefore, $admin->fresh()->getAttributes());
        $this->assertSame($customerBefore, $customer->fresh()->getAttributes());
        $this->assertDatabaseMissing('users', ['email' => 'admin@furnicraft.local']);
        $this->assertSame(1, User::where('role', 'admin')->count());
    }

    public function test_fresh_demo_accounts_are_created_once_and_verified(): void
    {
        $this->seed(DatabaseSeeder::class);
        $before = User::orderBy('id')->get()->map->getAttributes()->all();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($before, User::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame(3, User::count());
        $this->assertSame(3, User::whereNotNull('email_verified_at')->where('is_active', true)->count());
    }

    public function test_existing_customer_at_demo_admin_email_is_not_promoted(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'email' => 'admin@furnicraft.local']);
        $before = $customer->fresh()->getAttributes();
        try {
            $this->seed(DatabaseSeeder::class);
            $this->fail('Expected the admin email conflict to stop seeding.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('No account was promoted', $exception->getMessage());
        }
        $this->assertSame($before, $customer->fresh()->getAttributes());
        $this->assertSame(1, User::count());
    }
}
