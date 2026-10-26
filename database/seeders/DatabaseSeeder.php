<?php

namespace Database\Seeders;

use App\Models\Favorite;
use App\Models\FurnitureProduct;
use App\Models\FurnitureViewEvent;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Reuse the owner even after their login email has been changed.
        $admin = User::where('role', 'admin')->orderBy('id')->first();
        if (! $admin) {
            if (User::where('email', 'admin@furnicraft.local')->exists()) {
                throw new \RuntimeException('The demo admin email belongs to another account. No account was promoted.');
            }
            $admin = User::create(['email' => 'admin@furnicraft.local', 'name' => 'Lance Furniture Owner', 'password' => Hash::make('password'), 'role' => 'admin', 'branch' => config('furnicraft.branch'), 'is_active' => true, 'email_verified_at' => now()]);
        }
        // Defaults apply only to new demo accounts; preserve existing security settings.
        $demo = User::firstOrCreate(['email' => 'demo@furnicraft.local'], ['name' => 'Demo Customer', 'password' => Hash::make('password'), 'role' => 'customer', 'phone' => '0917 000 0000', 'is_active' => true, 'email_verified_at' => now()]);
        $neighbor = User::firstOrCreate(['email' => 'neighbor@furnicraft.local'], ['name' => 'Sample Customer', 'password' => Hash::make('password'), 'role' => 'customer', 'is_active' => true, 'email_verified_at' => now()]);

        $products = [
            ['Heritage Dining Set', 'Dining Set', 'A six-seat solid wood dining set designed for everyday family meals and gatherings.', 'Mahogany', 'Natural', 'Traditional', 48900, 180, 95, 76, 'dining.svg', ['dining', 'family', 'six seat']],
            ['Luna Dining Table', 'Dining Table', 'A clean-lined dining table with a compact footprint for modern homes.', 'Gmelina', 'Narra Look', 'Modern', 22800, 150, 85, 75, 'table.svg', ['dining', 'compact', 'table']],
            ['Amihan Bed Frame', 'Bed Frame', 'A queen-size platform bed with a sturdy slatted base and warm wood character.', 'Mahogany', 'Chocolate Brown', 'Contemporary', 32500, 160, 205, 105, 'bed.svg', ['bedroom', 'queen', 'platform']],
            ['Sierra Double Deck', 'Double-Deck Bed', 'A space-saving double-deck bed with guard rails and an integrated ladder.', 'Gmelina', 'Natural', 'Practical', 37900, 105, 205, 170, 'bed.svg', ['bedroom', 'bunk', 'space saving']],
            ['Balay Wardrobe', 'Cabinet', 'A three-door wardrobe with hanging space, shelves, and deep lower drawers.', 'Mahogany', 'Mahogany', 'Traditional', 41500, 150, 58, 195, 'cabinet.svg', ['storage', 'wardrobe', 'bedroom']],
            ['Narra Look Display Cabinet', 'Cabinet', 'A glass-front display cabinet for dining ware, keepsakes, and books.', 'Gmelina', 'Narra Look', 'Classic', 28900, 95, 42, 180, 'cabinet.svg', ['storage', 'display', 'living room']],
            ['Duyan Rocking Chair', 'Rocking Chair', 'A balanced wooden rocking chair with shaped armrests and a supportive back.', 'Mahogany', 'Natural', 'Classic', 15900, 68, 95, 110, 'chair.svg', ['chair', 'rocker', 'living room']],
            ['Habi Coffee Table', 'Table', 'A low rectangular table with an open shelf for compact living rooms.', 'Gmelina', 'Chocolate Brown', 'Modern', 12800, 105, 55, 45, 'table.svg', ['living room', 'coffee', 'compact']],
            ['Sinta Accent Chair', 'Chair', 'A versatile solid-wood accent chair for a bedroom, study, or receiving area.', 'Gmelina', 'Mahogany', 'Contemporary', 8900, 52, 58, 88, 'chair.svg', ['chair', 'accent', 'study']],
            ['Lance Panel Door', 'Wooden Door', 'A durable raised-panel interior door, ready for professional installation.', 'Mahogany', 'Mahogany', 'Traditional', 18500, 90, 4, 210, 'door.svg', ['door', 'panel', 'interior']],
            ['Gmelina Flush Door', 'Flush Door', 'A simple flush door with a smooth face suited to clean contemporary interiors.', 'Gmelina', 'Natural', 'Minimal', 9800, 90, 4, 210, 'door.svg', ['door', 'flush', 'minimal']],
            ['Tahanan Study Desk', 'Table', 'A focused work desk with two drawers and a cable opening.', 'Mahogany', 'Narra Look', 'Modern', 17600, 120, 60, 76, 'table.svg', ['office', 'study', 'desk']],
            ['Aurelia Accent Door', 'Wooden Door', 'A dark contemporary panel door detailed with slim metallic lines and geometric accents.', 'Mahogany', 'Chocolate Brown', 'Contemporary', 22500, 90, 4, 210, 'catalog:drive-01.png', ['door', 'panel', 'geometric']],
            ['Balay Two-Tone Sala Set', 'Sala Set', 'A coordinated sofa, two lounge chairs, and coffee table with contrasting wood slats.', 'Gmelina', 'Chocolate Brown', 'Contemporary', 58900, 220, 88, 92, 'catalog:drive-02.png', ['living room', 'sala set', 'four piece']],
            ['Haraya Panel Door', 'Wooden Door', 'A warm striped panel door with clean black inlays for a distinctive modern entrance.', 'Mahogany', 'Natural', 'Modern', 21800, 90, 4, 210, 'catalog:drive-03.png', ['door', 'panel', 'striped']],
            ['Alon Inlay Door', 'Wooden Door', 'A rich wood door shaped with flowing curved inlays and a refined handcrafted finish.', 'Mahogany', 'Mahogany', 'Modern', 23900, 90, 4, 210, 'catalog:drive-04.png', ['door', 'inlay', 'curved']],
            ['Sinag Accent Door', 'Wooden Door', 'A natural-tone panel door with alternating wood bands and understated diamond accents.', 'Gmelina', 'Natural', 'Contemporary', 20500, 90, 4, 210, 'catalog:drive-05.png', ['door', 'panel', 'accent']],
            ['Amihan Blue-Cushion Sala Set', 'Sala Set', 'A complete wood living-room set with blue cushions, two chairs, and a matching table.', 'Mahogany', 'Mahogany', 'Contemporary', 62500, 225, 90, 92, 'catalog:drive-06.png', ['living room', 'sala set', 'blue cushion']],
            ['Tahanan Geometric Door', 'Wooden Door', 'A bold dark panel door with repeating geometric accents and horizontal wood sections.', 'Mahogany', 'Narra Look', 'Contemporary', 23200, 90, 4, 210, 'catalog:drive-07.png', ['door', 'panel', 'geometric']],
            ['Luntian Classic Sala Set', 'Sala Set', 'A warm two-tone sala set with comfortable neutral cushions and a generous coffee table.', 'Gmelina', 'Chocolate Brown', 'Classic', 59800, 225, 90, 92, 'catalog:drive-08.png', ['living room', 'sala set', 'neutral cushion']],
            ['Habing Green Sala Set', 'Sala Set', 'A handcrafted receiving set paired with green patterned cushions and coordinated wood pieces.', 'Mahogany', 'Natural', 'Traditional', 64800, 225, 90, 92, 'catalog:drive-09.png', ['living room', 'sala set', 'green cushion']],
        ];

        // Keep placeholder demo SVG listings out of customer-facing catalog seeds.
        $products = array_values(array_filter($products, fn ($row) => ! str_ends_with($row[10], '.svg')));

        foreach ($products as $row) {
            [$name, $category, $description, $wood, $finish, $style, $price, $width, $depth, $height, $image, $tags] = $row;
            $imagePath = str_starts_with($image, 'catalog:') ? $image : 'demo:'.$image;
            FurnitureProduct::updateOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'category' => $category, 'description' => $description, 'wood_type' => $wood, 'varnish_finish' => $finish, 'style' => $style, 'price' => $price, 'width_cm' => $width, 'depth_cm' => $depth, 'height_cm' => $height, 'image_path' => $imagePath, 'tags' => $tags, 'status' => 'active', 'created_by' => $admin->id]);
        }

        $this->call(CustomerCatalogProductsSeeder::class);

        $all = FurnitureProduct::orderBy('id')->get();
        foreach ($all->take(5) as $product) {
            Favorite::firstOrCreate(['user_id' => $neighbor->id, 'furniture_product_id' => $product->id]);
        }
        foreach ($all->take(3) as $product) {
            Favorite::firstOrCreate(['user_id' => $demo->id, 'furniture_product_id' => $product->id]);
        }
        if (FurnitureViewEvent::count() === 0) {
            foreach ($all->take(6) as $index => $product) {
                for ($i = 0; $i < 12 - $index; $i++) {
                    FurnitureViewEvent::create(['user_id' => $i % 2 ? $demo->id : $neighbor->id, 'furniture_product_id' => $product->id, 'session_key' => 'seed']);
                }
            }
        }
        if (Reservation::count() === 0) {
            Reservation::create(['reference' => 'FC-DEMO-001', 'user_id' => $neighbor->id, 'furniture_product_id' => $all->first()->id, 'branch' => config('furnicraft.branch'), 'status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by' => $admin->id]);
        }
    }
}
