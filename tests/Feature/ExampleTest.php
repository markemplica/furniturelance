<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeRoomImage;
use App\Models\FurnitureProduct;
use App\Models\NotificationLog;
use App\Models\PasswordResetCode;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\RoomImage;
use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use App\Notifications\ReservationConfirmedNotification;
use App\Services\FurniturePlacementService;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_strict_preferences_and_chat_update_ranked_matches(): void
    {
        $user = User::factory()->create();
        $cheap = $this->product(['price' => 15000]);
        $this->product(['slug' => 'expensive', 'price' => 25000]);
        $this->product(['slug' => 'wrong-wood', 'wood_type' => 'Mahogany', 'price' => 14000]);
        $this->product(['slug' => 'wrong-style', 'style' => 'Traditional', 'price' => 14000]);
        $this->product(['slug' => 'wrong-finish', 'varnish_finish' => 'Black', 'price' => 14000]);
        $room = $this->room($user);
        $preference = $user->preference()->create(['furniture_type' => 'Table', 'budget_min' => 0, 'budget_max' => 30000, 'wood_type' => 'Gmelina', 'style' => 'Modern', 'varnish_finish' => 'Natural']);
        $recommendation = app(\App\Services\RecommendationService::class)->generate($user, $preference, $room, 'Table');
        $this->assertCount(2, $recommendation->items);
        $this->actingAs($user)->post(route('recommendations.chat', $recommendation), ['message' => 'below 20'])->assertRedirect(route('recommendations.show', $recommendation));
        $this->assertSame([$cheap->id], $recommendation->items()->pluck('furniture_product_id')->all());
        $this->assertSame('19999.99', $preference->fresh()->budget_max);
        $this->assertSame('Gmelina', $preference->fresh()->wood_type);
        $this->post(route('recommendations.chat', $recommendation), ['message' => 'below 10000'])->assertRedirect();
        $this->assertSame(0, $recommendation->items()->count());
        $this->get(route('recommendations.show', $recommendation))->assertOk()->assertSee('No exact matches')->assertSee('Ask FurniCraft')
            ->assertSee('Closest suggestions')->assertSee('above your maximum budget');
        $alternatives = app(\App\Services\RecommendationService::class)->closestSuggestions($preference->fresh(), 'Table');
        $this->assertCount(3, $alternatives);
        $this->assertSame($cheap->id, $alternatives->first()['product']->id);
        $this->assertTrue($alternatives->every(fn ($row) => $row['product']->recommendationType() === 'Table' && count($row['differences']) > 0));
        $this->assertSame('9999.99', $preference->fresh()->budget_max);
        $this->assertStringContainsString('Closest alternatives', $recommendation->messages()->where('role', 'assistant')->latest('id')->first()->message);
        $this->post(route('recommendations.chat', $recommendation), ['message' => 'budget ko 20k'])->assertRedirect();
        $this->assertSame([$cheap->id], $recommendation->items()->pluck('furniture_product_id')->all());
        $this->post(route('recommendations.chat', $recommendation), ['message' => 'gusto ko Mahogany'])->assertRedirect();
        $this->assertSame('Mahogany', $preference->fresh()->wood_type);
        $this->assertSame('Natural', $preference->fresh()->varnish_finish);
        $this->assertSame(1, $recommendation->items()->count());
        $this->actingAs(User::factory()->create())->post(route('recommendations.chat', $recommendation), ['message' => 'below 1'])->assertForbidden();
        $this->assertSame('20000.00', $preference->fresh()->budget_max);
    }

    public function test_chat_filter_does_not_treat_material_questions_or_negations_as_selections(): void
    {
        $this->product();
        $preference = new \App\Models\CustomerPreference(['budget_min' => 5000]);
        $parser = app(\App\Services\ChatPreferenceFilter::class);
        $this->assertSame([], $parser->parse('What is Gmelina?', $preference));
        $this->assertSame([], $parser->parse('not Gmelina', $preference));
        $this->assertSame(19.99, $parser->parse('below PHP 20', $preference)['updates']['budget_max']);
        $this->assertSame(19999.99, $parser->parse('below 20k', $preference)['updates']['budget_max']);
        $this->assertSame(['wood_type' => null], $parser->parse('any wood', $preference)['updates']);
        $this->assertSame('Gmelina', $parser->parse('gusto ko ng Gmelina below 20k', $preference)['updates']['wood_type']);
        $this->assertSame('Modern', $parser->parse('Modern style', $preference)['updates']['style']);
        $this->assertSame('Natural', $parser->parse('finish: Natural', $preference)['updates']['varnish_finish']);
        $this->assertSame('Table', $parser->parse('show me Table', $preference)['updates']['furniture_type']);
        $this->assertSame([], $parser->parse('why would I choose Gmelina?', $preference));
    }

    public function test_home_is_public_and_login_is_required_for_customer_features(): void
    {
        $product = $this->product();

        foreach (['/catalog', '/catalog/'.$product->slug, '/profile', '/preferences', '/room', '/reservations', '/admin'] as $path) {
            $this->get($path)->assertRedirectContains('/login');
        }

        $this->get('/login')->assertOk();
        $this->get('/register')->assertOk();
        $this->get('/')->assertOk()->assertSee('Furniture that feels right at home.')->assertSee('How it works')->assertSee('Log in')->assertSee('Create account');
        $this->get('/how-it-works')->assertOk()->assertSee('A clearer path to the right furniture.')->assertSee('Log in');
    }

    public function test_customer_can_update_profile_information(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Customer Name',
            'email' => 'old-profile@example.com',
            'phone' => '09000000000',
        ]);

        $this->actingAs($user)->get('/profile')
            ->assertOk()
            ->assertSee('Profile settings')
            ->assertSee($user->email);

        $this->actingAs($user)->patch('/profile', [
            'name' => 'Updated Customer Name',
            'email' => 'updated-profile@example.com',
            'phone' => '09171234567',
        ])->assertRedirect(route('profile.edit'))->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Customer Name',
            'email' => 'updated-profile@example.com',
            'phone' => '09171234567',
        ]);
    }

    public function test_customer_must_confirm_current_password_before_changing_it(): void
    {
        $user = User::factory()->create();
        $originalPassword = $user->password;

        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'incorrect-password',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertSessionHasErrors('current_password');

        $this->assertSame($originalPassword, $user->fresh()->password);

        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'password',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertRedirect(route('profile.edit'))->assertSessionHas('success');

        $this->assertTrue(Hash::check('new-password-456', $user->fresh()->password));
    }

    public function test_seeded_catalog_contains_all_supplied_catalog_images(): void
    {
        $this->seed();

        $this->assertSame(25, FurnitureProduct::where('image_path', 'like', 'catalog:%')->count());
        $filenames = array_merge(array_map(fn ($number) => sprintf('drive-%02d.png', $number), range(1, 9)), ['catalog-console.png', 'catalog-3-pad.png', 'catalog-piano-with-s.png', 'catalog-605.png', 'catalog-channel.png']);
        foreach ($filenames as $filename) {
            $product = FurnitureProduct::where('image_path', 'catalog:'.$filename)->firstOrFail();
            $this->assertStringEndsWith('/images/catalog/'.$filename, $product->image_url);
        }
        foreach (FurnitureProduct::all() as $product) {
            $this->assertFileExists(public_path('images/catalog/'.substr($product->image_path, strlen('catalog:'))));
        }
    }

    public function test_customer_can_register_and_admin_area_rejects_them(): void
    {
        Notification::fake();
        $this->post('/register', ['first_name' => 'Ana', 'last_name' => 'Customer', 'birthdate' => '2000-01-01', 'gender' => 'female', 'email' => 'ana@example.com', 'phone' => '09170000001', 'password' => 'secret123', 'password_confirmation' => 'secret123'])->assertRedirect(route('verification.notice'));
        $user = User::where('email', 'ana@example.com')->firstOrFail();
        $this->assertSame('customer', $user->role);
        $this->assertGuest();
        $this->assertFalse($user->is_active);
        $this->assertNull($user->email_verified_at);
        $notification = Notification::sent($user, \App\Notifications\EmailVerificationCodeNotification::class)->sole();
        $this->post(route('verification.verify'), ['email' => $user->email, 'code' => $notification->code])->assertRedirect('/catalog');
        $this->assertTrue($user->fresh()->is_active);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $user->refresh();
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_login_returns_a_guest_to_the_featured_product_they_opened(): void
    {
        $user = User::factory()->create(['email' => 'return@example.com']);
        $product = $this->product();
        $target = route('catalog.show', $product);
        $this->get($target)->assertRedirect();
        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'redirect' => $target])->assertRedirect($target);
    }

    public function test_customer_login_goes_to_the_home_page_by_default(): void
    {
        $user = User::factory()->create(['email' => 'home@example.com']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_logout_returns_the_user_to_the_public_home_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect(route('home'))
            ->assertSessionHas('success', 'You have been signed out.');

        $this->assertGuest();
    }

    public function test_customer_can_update_preferences_without_reloading_the_page(): void
    {
        $user = User::factory()->create();
        $this->product();

        $this->actingAs($user)->get(route('preferences.edit'))
            ->assertOk()
            ->assertSee('data-preferences-form', false)
            ->assertSee('data-preferences-status', false)
            ->assertSee('data-preferences-submit', false);

        $this->actingAs($user)->postJson(route('preferences.update'), [
            'furniture_type' => 'Table',
            'budget_min' => 5000,
            'budget_max' => 40000,
            'style' => 'Modern',
            'wood_type' => 'Gmelina',
            'varnish_finish' => 'Natural',
            'notes' => 'Compact family dining area.',
        ])->assertOk()
            ->assertJsonPath('message', 'Preferences updated successfully.')
            ->assertJsonPath('preference.furniture_type', 'Table')
            ->assertJsonPath('preference.style', 'Modern');

        $this->assertDatabaseHas('customer_preferences', [
            'user_id' => $user->id,
            'furniture_type' => 'Table',
            'budget_min' => 5000,
            'budget_max' => 40000,
        ]);
    }

    public function test_partial_catalog_search_returns_ranked_matches(): void
    {
        $user = User::factory()->create();
        $product = $this->product(['name' => 'Family Dining Set', 'slug' => 'family-dining-set', 'category' => 'Dining Set']);
        $this->product(['name' => 'Bedroom Cabinet', 'slug' => 'bedroom-cabinet', 'category' => 'Cabinet', 'tags' => ['storage', 'bedroom']]);
        $response = $this->actingAs($user)->getJson('/catalog/search?q=dinn');
        $response->assertOk()->assertJsonCount(1)->assertJsonFragment(['name' => $product->name]);
    }

    public function test_customer_can_generate_and_revisit_hybrid_recommendations(): void
    {
        config(['services.gemini.key' => null]);
        $user = User::factory()->create();
        $this->product();
        $this->product(['name' => 'Modern Cabinet', 'slug' => 'modern-cabinet', 'category' => 'Cabinet', 'style' => 'Modern', 'price' => 18000]);
        $room = $this->room($user);

        $this->actingAs($user)->post('/preferences', [
            'furniture_type' => 'Table',
            'budget_min' => 5000,
            'budget_max' => 40000,
            'style' => 'Modern',
            'wood_type' => 'Gmelina',
            'varnish_finish' => 'Natural',
        ])->assertRedirect(route('preferences.edit').'#room-preview');

        $response = $this->actingAs($user)->post('/recommendations', ['room_image_id' => $room->id]);
        $recommendation = Recommendation::firstOrFail();
        $response->assertRedirect(route('recommendations.show', $recommendation));
        $this->assertGreaterThan(0, $recommendation->items()->count());
        $this->actingAs($user)->get(route('recommendations.show', $recommendation))->assertOk()->assertSee('Table options selected for your room.');
    }

    public function test_recommendations_and_room_preview_share_one_customer_workflow(): void
    {
        config(['services.gemini.key' => null]);
        $user = User::factory()->create();
        $product = $this->product();
        $room = $this->room($user);

        $this->actingAs($user)->post('/preferences', [
            'furniture_type' => 'Table',
            'budget_min' => 5000,
            'budget_max' => 40000,
            'style' => 'Modern',
        ])->assertRedirect(route('preferences.edit').'#room-preview');

        $this->actingAs($user)->get('/room')->assertRedirect(route('preferences.edit').'#room-preview');
        $this->actingAs($user)->get(route('preferences.edit', ['room' => $room->id]))
            ->assertOk()
            ->assertSee('Plan your room.')
            ->assertSee('Browse and set your preferences')
            ->assertSee('Choose a room for automatic AI fitting.')
            ->assertSee('Generate <span data-saved-furniture-type>Table</span> recommendations', false)
            ->assertDontSee('Step 3 · AI-powered')
            ->assertSee('Selected')
            ->assertSee('data-room-option', false)
            ->assertSee('Click a room to use it for your recommendation.')
            ->assertDontSee('Select this room');

        $this->actingAs($user)->post('/recommendations', ['room_image_id' => $room->id]);

        $recommendation = Recommendation::firstOrFail();
        $this->actingAs($user)->get(route('recommendations.show', $recommendation))
            ->assertOk()
            ->assertSee('Step 3 · Your AI matches')
            ->assertSee('options selected for your room.')
            ->assertSee('Automatic 2D room preview')
            ->assertSee('Download preview (PNG)')
            ->assertSee('data-room-preview', false)
            ->assertSee('role="slider"', false)
            ->assertSee('See', false);
        $this->actingAs($user)->get(route('room.visualize', ['roomImage' => $room, 'recommendation' => $recommendation->id, 'product' => $product->id]))
            ->assertOk()
            ->assertSee('Automatic 2D room preview')
            ->assertSee($product->name)
            ->assertDontSee('Show original')
            ->assertDontSee('Split view')
            ->assertDontSee('Show result')
            ->assertDontSee('role="slider"', false)
            ->assertSee('Download preview (PNG)')
            ->assertDontSee('data-fit-room', false)
            ->assertDontSee('data-product-scale', false)
            ->assertDontSee('data-product-rotation', false)
            ->assertDontSee('data-product-picker', false)
            ->assertSee('Preview is an estimate, not a measurement.')
            ->assertSee('Verify actual dimensions with Lance Furniture Shop before reserving.');
    }

    public function test_chatbot_reports_catalog_availability_and_unavailable_requests(): void
    {
        config(['services.gemini.key' => null]);
        $user = User::factory()->create();
        $available = $this->product(['name' => 'Gmelina Natural Table', 'slug' => 'gmelina-natural-table']);
        $this->product([
            'name' => 'Inactive Narra Red Table',
            'slug' => 'inactive-narra-red-table',
            'wood_type' => 'Mahogany',
            'varnish_finish' => 'Chocolate Brown',
            'status' => 'inactive',
        ]);
        $room = $this->room($user);

        $this->actingAs($user)->post('/preferences', [
            'furniture_type' => 'Table',
            'budget_min' => 5000,
            'budget_max' => 40000,
            'style' => 'Modern',
            'wood_type' => 'Gmelina',
            'varnish_finish' => 'Natural',
        ]);
        $this->actingAs($user)->post('/recommendations', ['room_image_id' => $room->id]);
        $recommendation = Recommendation::firstOrFail();

        $this->actingAs($user)->post(route('recommendations.chat', $recommendation), [
            'message' => 'May available bang Gmelina na Natural finish?',
        ])->assertRedirect();

        $availableAnswer = $recommendation->messages()->where('role', 'assistant')->latest('id')->firstOrFail()->message;
        $this->assertStringContainsString('Available in our current Table catalog', $availableAnswer);
        $this->assertStringContainsString($available->name, $availableAnswer);
        $this->assertStringContainsString('physical stock', $availableAnswer);

        $this->actingAs($user)->post(route('recommendations.chat', $recommendation), [
            'message' => 'Gusto ko ng Narra wood na Red finish.',
        ])->assertRedirect();

        $unavailableAnswer = $recommendation->messages()->where('role', 'assistant')->latest('id')->firstOrFail()->message;
        $this->assertStringContainsString('Not available in our current Table catalog', $unavailableAnswer);
        $this->assertStringNotContainsString('Inactive Narra Red Table', $unavailableAnswer);
        $this->assertStringContainsString($available->name, $unavailableAnswer);
    }

    public function test_recommendations_only_include_the_selected_furniture_type(): void
    {
        config(['services.gemini.key' => null]);
        $user = User::factory()->create();
        $woodenDoor = $this->product(['name' => 'Wooden Panel Door', 'slug' => 'wooden-panel-door', 'category' => 'Wooden Door']);
        $flushDoor = $this->product(['name' => 'Modern Flush Door', 'slug' => 'modern-flush-door', 'category' => 'Flush Door']);
        $table = $this->product(['name' => 'Dining Table', 'slug' => 'dining-table', 'category' => 'Dining Table']);
        $room = $this->room($user);

        $this->actingAs($user)->post('/preferences', [
            'furniture_type' => 'Door',
            'budget_min' => 5000,
            'budget_max' => 40000,
            'style' => 'Modern',
        ]);

        $this->actingAs($user)->get(route('preferences.edit', ['room' => $room->id]))
            ->assertOk()
            ->assertSee('value="Door"', false)
            ->assertSee('Door')
            ->assertSeeInOrder(['Step 1 · Required', 'name="furniture_type"', 'Step 2 · Required', 'id="recommendation-form"'], false)
            ->assertDontSee('Step 3 · AI-powered');

        $this->assertSame('Door', $user->preference()->firstOrFail()->furniture_type);

        $this->actingAs($user)->post('/recommendations', [
            'room_image_id' => $room->id,
        ])->assertRedirect();

        $recommendation = Recommendation::with('items.product')->firstOrFail();
        $recommendedIds = $recommendation->items->pluck('furniture_product_id');

        $this->assertSame('Door', $recommendation->furniture_type);
        $this->assertTrue($recommendedIds->contains($woodenDoor->id));
        $this->assertTrue($recommendedIds->contains($flushDoor->id));
        $this->assertFalse($recommendedIds->contains($table->id));
        $this->assertTrue($recommendation->items->every(
            fn ($item) => str_contains(strtolower($item->product->category), 'door')
        ));
    }

    public function test_sala_set_recommendations_only_rank_available_sala_sets_by_customer_preferences(): void
    {
        config(['services.gemini.key' => null]);
        $user = User::factory()->create();
        $closest = $this->product([
            'name' => 'Closest Modern Sala Set',
            'slug' => 'closest-modern-sala-set',
            'category' => 'Sala Set',
            'style' => 'Modern',
            'wood_type' => 'Gmelina',
            'varnish_finish' => 'Natural',
            'price' => 28000,
        ]);
        $otherSalaSet = $this->product([
            'name' => 'Traditional Sala Set',
            'slug' => 'traditional-sala-set',
            'category' => 'Sala Set',
            'style' => 'Traditional',
            'wood_type' => 'Mahogany',
            'varnish_finish' => 'Chocolate Brown',
            'price' => 58000,
        ]);
        $inactiveSalaSet = $this->product([
            'name' => 'Unavailable Modern Sala Set',
            'slug' => 'unavailable-modern-sala-set',
            'category' => 'Sala Set',
            'status' => 'inactive',
        ]);
        $matchingTable = $this->product([
            'name' => 'Matching Modern Table',
            'slug' => 'matching-modern-table',
            'category' => 'Table',
            'style' => 'Modern',
            'wood_type' => 'Gmelina',
            'varnish_finish' => 'Natural',
            'price' => 26000,
        ]);
        $room = $this->room($user);

        $this->actingAs($user)->post('/preferences', [
            'furniture_type' => 'Sala Set',
            'budget_min' => 20000,
            'budget_max' => 35000,
            'style' => 'Modern',
            'wood_type' => 'Gmelina',
            'varnish_finish' => 'Natural',
        ])->assertRedirect();

        $this->actingAs($user)->post('/recommendations', [
            'room_image_id' => $room->id,
        ])->assertRedirect();

        $recommendation = Recommendation::with('items.product')->firstOrFail();
        $recommendedIds = $recommendation->items->pluck('furniture_product_id');

        $this->assertSame('Sala Set', $recommendation->furniture_type);
        $this->assertSame($closest->id, $recommendation->items->first()->furniture_product_id);
        $this->assertFalse($recommendedIds->contains($otherSalaSet->id));
        $this->assertFalse($recommendedIds->contains($inactiveSalaSet->id));
        $this->assertFalse($recommendedIds->contains($matchingTable->id));
        $this->assertTrue($recommendation->items->every(
            fn ($item) => $item->product->status === 'active' && $item->product->category === 'Sala Set'
        ));
    }

    public function test_room_preview_automatically_fits_only_the_selected_active_catalog_product(): void
    {
        $user = User::factory()->create();
        $active = $this->product(['name' => 'Active Lance Chair', 'slug' => 'active-lance-chair']);
        $inactive = $this->product(['name' => 'Retired Lance Chair', 'slug' => 'retired-lance-chair', 'status' => 'inactive']);
        $room = RoomImage::create([
            'user_id' => $user->id,
            'image_path' => 'rooms/catalog-only-room.png',
            'room_width_m' => 4,
            'room_depth_m' => 5,
            'analysis' => ['summary' => 'Room ready.', 'layout' => 'Open floor.', 'lighting' => 'Bright.', 'constraints' => 'Measure first.', 'gemini_used' => false],
            'analyzed_at' => now(),
        ]);

        $this->actingAs($user)->get(route('room.visualize', ['roomImage' => $room, 'product' => $active->id]))
            ->assertOk()
            ->assertSee($active->name)
            ->assertDontSee($inactive->name)
            ->assertDontSee('data-product-picker', false)
            ->assertSee('Download preview (PNG)');

        $this->actingAs($user)->get(route('room.visualize', ['roomImage' => $room, 'product' => $inactive->id]))
            ->assertNotFound();
    }

    public function test_gemini_room_analysis_estimates_dimensions_when_not_provided(): void
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
        Storage::fake('public');
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'summary' => 'Estimated compact living room.',
                            'layout' => 'Open center floor.',
                            'lighting' => 'Bright.',
                            'constraints' => 'Confirm measurements.',
                            'room_type' => 'Living room',
                            'estimated_width_m' => 3.8,
                            'estimated_depth_m' => 4.6,
                            'dimension_confidence' => 'low',
                            'placement_x_percent' => 52,
                            'placement_y_percent' => 88,
                            'placement_width_percent' => 68,
                            'placement_confidence' => 'high',
                            'placement_reason' => 'Use the open floor area centered against the main wall.',
                            'candidate_zones' => [[
                                'surface' => 'floor',
                                'anchor_x_percent' => 72,
                                'anchor_y_percent' => 89,
                                'max_width_percent' => 64,
                                'rotation_deg' => -4,
                                'perspective_deg' => 7,
                                'flip' => false,
                                'quad_percent' => [[64, 31], [83, 29], [85, 90], [63, 92]],
                                'suitable_types' => ['Sala Set'],
                                'clearance_score' => 92,
                                'occlusion_score' => 8,
                                'confidence' => 'high',
                                'reason' => 'Clear living area beside the main wall.',
                            ]],
                            'scene_confidence' => 'high',
                            'vision_geometry' => [
                                'image_quality' => ['usable' => true, 'issues' => []],
                                'floor_polygon' => [[0.05, 0.4], [0.95, 0.4], [0.98, 0.95], [0.02, 0.95]],
                                'walls' => [],
                                'openings' => [],
                                'horizon_y' => 0.38,
                                'blocked_zones' => [],
                                'existing_furniture' => [],
                                'scale_clues' => [['object' => 'door', 'pixel_height' => 250, 'typical_real_cm' => 210, 'base_y' => 0.9, 'reliability' => 0.9]],
                                'lighting' => ['brightness' => 0.7, 'warmth' => 0.5, 'light_from' => 'front'],
                                'confidence' => 0.8,
                            ],
                        ]),
                    ]]],
                ]],
            ]),
        ]);

        $user = User::factory()->create();
        $this->product(['name' => 'Test Sala Set', 'slug' => 'test-sala-set', 'category' => 'Sala Set']);
        $this->actingAs($user)->post('/preferences', ['furniture_type' => 'Sala Set', 'budget_min' => 5000, 'budget_max' => 40000]);
        $png = file_get_contents(base_path('tests/Fixtures/room-600.png'));
        $this->actingAs($user)->post('/room', [
            'room_image' => UploadedFile::fake()->createWithContent('estimated-room.png', $png),
            'gemini_consent' => true,
        ])->assertRedirect();

        $room = $user->roomImages()->firstOrFail();
        $this->assertSame('3.80', $room->room_width_m);
        $this->assertSame('4.60', $room->room_depth_m);
        $this->assertSame('low', $room->analysis['dimension_confidence']);
        $this->assertSame('all catalog furniture', $room->analysis['analyzed_furniture_type']);
        $this->assertEquals(52.0, $room->analysis['placement_x_percent']);
        $this->assertEquals(88.0, $room->analysis['placement_y_percent']);
        $this->assertEquals(68.0, $room->analysis['placement_width_percent']);
        $this->assertSame('high', $room->analysis['placement_confidence']);
        $this->assertEquals(72.0, $room->analysis['candidate_zones'][0]['anchor_x_percent']);
        $this->assertCount(4, $room->analysis['candidate_zones'][0]['quad_percent']);
        $this->assertTrue($room->analysis['gemini_used']);
        $this->actingAs($user)->get(route('room.visualize', $room))
            ->assertOk()
            ->assertSee('data-room-preview', false)
            ->assertSee('Download preview (PNG)')
            ->assertDontSee('data-fit-room', false)
            ->assertDontSee('data-product-scale', false);
        Http::assertSent(function (ClientRequest $request): bool {
            $config = $request->data()['generationConfig'] ?? [];

            return data_get($config, 'responseMimeType') === 'application/json'
                && data_get($config, 'responseJsonSchema.type') === 'object';
        });
        Http::assertSentCount(1);
    }

    public function test_room_analysis_falls_back_to_local_guidance_when_gemini_rejects_the_request(): void
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-test', 'services.gemini.fallback_model' => 'gemini-fallback-test']);
        Queue::fake();
        Storage::fake('public');
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid response format']], 400)]);

        $user = User::factory()->create();
        $this->product();
        $this->actingAs($user)->post('/preferences', ['furniture_type' => 'Table', 'budget_min' => 5000, 'budget_max' => 40000]);
        $png = file_get_contents(base_path('tests/Fixtures/room-600.png'));
        $this->actingAs($user)->post('/room', [
            'room_image' => UploadedFile::fake()->createWithContent('gemini-error-room.png', $png),
            'room_width_m' => 3.5,
            'room_depth_m' => 4.2,
            'gemini_consent' => true,
        ])->assertRedirect();

        $room = $user->roomImages()->firstOrFail();
        (new AnalyzeRoomImage($room->id, 'Table'))->handle(
            app(GeminiService::class),
            app(\App\Services\RoomPhotoService::class),
            app(\App\Services\PrivacyAudit::class),
            app(\App\Services\AiTelemetry::class),
        );

        $room->refresh();
        $this->assertSame('fallback', $room->analysis_status);
        $this->assertFalse($room->analysis['gemini_used']);
        $this->assertStringContainsString('Local room guidance is being used', $room->analysis_error);
        Http::assertSentCount(4);
    }
    public function test_placement_service_selects_a_clear_floor_zone_for_a_sala_set(): void
    {
        $product = $this->product([
            'name' => 'Zone Test Sala Set',
            'slug' => 'zone-test-sala-set',
            'category' => 'Sala Set',
            'width_cm' => 210,
            'depth_cm' => 90,
        ]);
        $analysis = ['candidate_zones' => [
            ['surface' => 'opening', 'anchor_x_percent' => 20, 'anchor_y_percent' => 80, 'max_width_percent' => 30, 'suitable_types' => ['Door'], 'clearance_score' => 90, 'occlusion_score' => 5, 'confidence' => 'high'],
            ['surface' => 'floor', 'anchor_x_percent' => 72, 'anchor_y_percent' => 89, 'max_width_percent' => 68, 'rotation_deg' => -5, 'perspective_deg' => 8, 'suitable_types' => ['Sala Set'], 'clearance_score' => 94, 'occlusion_score' => 6, 'confidence' => 'high', 'reason' => 'Clear area near the living-room wall.'],
        ]];

        $placement = app(FurniturePlacementService::class)->forProduct($analysis, $product, 4.5, 5.0);

        $this->assertSame(72.0, $placement['x_percent']);
        $this->assertSame('candidate-zone', $placement['source']);
        $this->assertSame('high', $placement['confidence']);
        $this->assertFalse($placement['needs_confirmation']);
    }

    public function test_placement_service_prefers_an_opening_for_a_door(): void
    {
        $product = $this->product([
            'name' => 'Zone Test Door',
            'slug' => 'zone-test-door',
            'category' => 'Door',
            'width_cm' => 90,
            'depth_cm' => 12,
        ]);
        $analysis = ['candidate_zones' => [
            ['surface' => 'floor', 'anchor_x_percent' => 40, 'anchor_y_percent' => 88, 'max_width_percent' => 60, 'suitable_types' => ['Sala Set'], 'clearance_score' => 95, 'occlusion_score' => 5, 'confidence' => 'high'],
            ['surface' => 'opening', 'anchor_x_percent' => 81, 'anchor_y_percent' => 91, 'max_width_percent' => 28, 'quad_percent' => [[68, 25], [92, 27], [94, 91], [67, 90]], 'suitable_types' => ['Door'], 'clearance_score' => 84, 'occlusion_score' => 10, 'confidence' => 'high'],
        ]];

        $placement = app(FurniturePlacementService::class)->forProduct($analysis, $product, 4.0, 4.0);

        $this->assertSame(81.0, $placement['x_percent']);
        $this->assertSame('opening', $placement['surface']);
        $this->assertFalse($placement['needs_confirmation']);
        $this->assertSame('opening-measurements-required', $placement['measurement_status']);
    }

    public function test_placement_service_derives_an_exact_door_zone_from_an_opening_polygon(): void
    {
        $product = $this->product([
            'name' => 'Opening Test Door',
            'slug' => 'opening-test-door',
            'category' => 'Door',
            'width_cm' => 90,
            'depth_cm' => 12,
        ]);
        $analysis = [
            'scene_confidence' => 'high',
            'openings' => [[
                'label' => 'right doorway',
                'polygon' => [[76, 28], [91, 30], [92, 91], [75, 90]],
            ]],
        ];

        $placement = app(FurniturePlacementService::class)->forProduct($analysis, $product, 4.0, 4.0);

        $this->assertSame(83.5, $placement['x_percent']);
        $this->assertSame(91.0, $placement['y_percent']);
        $this->assertSame(17.0, $placement['max_width_percent']);
        $this->assertSame('scene-opening', $placement['source']);
        $this->assertSame([[76.0, 28.0], [91.0, 30.0], [92.0, 91.0], [75.0, 90.0]], $placement['quad_percent']);
        $this->assertStringContainsString('right doorway', $placement['reason']);
    }

    public function test_door_placement_uses_a_doorway_and_ignores_its_existing_door_leaf(): void
    {
        $door = $this->product([
            'name' => 'Doorway Replacement Test',
            'slug' => 'doorway-replacement-test',
            'category' => 'Door',
            'width_cm' => 90,
            'depth_cm' => 12,
        ]);
        $analysis = [
            'candidate_zones' => [[
                'surface' => 'opening',
                'anchor_x_percent' => 87.5,
                'anchor_y_percent' => 69.5,
                'max_width_percent' => 15,
                'quad_percent' => [[80.5, 22.5], [94.5, 20.2], [94.5, 71.2], [80.5, 67.8]],
                'suitable_types' => ['Door'],
                'clearance_score' => 95,
                'occlusion_score' => 5,
                'confidence' => 'high',
                'reason' => 'Existing framed opening into walk-in closet.',
            ]],
            'openings' => [
                ['label' => 'window_left', 'polygon' => [[16, 24], [29, 28], [29, 52], [16, 52]]],
                ['label' => 'closet_doorway', 'polygon' => [[80.5, 22.5], [94.5, 20.2], [94.5, 71.2], [80.5, 67.8]]],
            ],
            'obstacles' => [[
                'label' => 'open_closet_door_leaf',
                'polygon' => [[80.5, 25.5], [82.5, 26.5], [82.5, 67.5], [80.5, 67.5]],
            ]],
        ];

        $placement = app(FurniturePlacementService::class)->forProduct($analysis, $door, 20.0, 10.0);

        $this->assertTrue($placement['renderable']);
        $this->assertSame('candidate-zone', $placement['source']);
        $this->assertSame(87.5, $placement['x_percent']);
        $this->assertStringContainsString('walk-in closet', $placement['reason']);
        $this->assertStringNotContainsString('window', $placement['reason']);
    }
    public function test_door_placement_rejects_window_candidates_and_window_regions(): void
    {
        $door = $this->product([
            'name' => 'Window Guard Test Door',
            'slug' => 'window-guard-test-door',
            'category' => 'Door',
            'width_cm' => 90,
            'depth_cm' => 12,
        ]);
        $analysis = [
            'candidate_zones' => [[
                'surface' => 'opening',
                'anchor_x_percent' => 22,
                'anchor_y_percent' => 52,
                'max_width_percent' => 18,
                'quad_percent' => [[16, 24], [29, 28], [29, 52], [16, 52]],
                'suitable_types' => ['Door'],
                'clearance_score' => 95,
                'occlusion_score' => 0,
                'confidence' => 'high',
                'reason' => 'Aligned to the detected window_left frame.',
            ]],
            'openings' => [[
                'label' => 'window_left',
                'polygon' => [[16, 24], [29, 28], [29, 52], [16, 52]],
            ]],
        ];

        $placement = app(FurniturePlacementService::class)->forProduct($analysis, $door, 4.0, 5.0);

        $this->assertFalse($placement['renderable']);
        $this->assertSame('no-opening', $placement['validation_status']);
        $this->assertSame('placement-blocked', $placement['source']);
    }
    public function test_placement_service_avoids_obstructions_and_keeps_visual_fit_advisory_for_oversized_furniture(): void
    {
        $product = $this->product([
            'name' => 'Validated Sala Set',
            'slug' => 'validated-sala-set',
            'category' => 'Sala Set',
            'width_cm' => 180,
            'depth_cm' => 90,
        ]);
        $analysis = [
            'floor_polygon' => [[5, 55], [95, 55], [100, 100], [0, 100]],
            'obstacles' => [['label' => 'existing table', 'polygon' => [[38, 68], [67, 68], [67, 96], [38, 96]]]],
            'candidate_zones' => [
                ['surface' => 'floor', 'anchor_x_percent' => 52, 'anchor_y_percent' => 92, 'max_width_percent' => 55, 'suitable_types' => ['Sala Set'], 'clearance_score' => 95, 'occlusion_score' => 4, 'confidence' => 'high'],
                ['surface' => 'floor', 'anchor_x_percent' => 20, 'anchor_y_percent' => 90, 'max_width_percent' => 35, 'suitable_types' => ['Sala Set'], 'clearance_score' => 82, 'occlusion_score' => 8, 'confidence' => 'high'],
            ],
        ];

        $placement = app(FurniturePlacementService::class)->forProduct($analysis, $product, 5.0, 5.0);
        $this->assertSame(20.0, $placement['x_percent']);
        $this->assertTrue($placement['renderable']);
        $this->assertSame('supported', $placement['validation_status']);

        $oversized = $this->product([
            'name' => 'Oversized Sala Set',
            'slug' => 'oversized-sala-set',
            'category' => 'Sala Set',
            'width_cm' => 500,
            'depth_cm' => 400,
        ]);
        $blocked = app(FurniturePlacementService::class)->forProduct($analysis, $oversized, 3.0, 3.0);
        $this->assertTrue($blocked['renderable']);
        $this->assertSame('advisory', $blocked['validation_status']);
        $this->assertSame('does-not-fit', $blocked['measurement_status']);
        $this->assertNotEmpty($blocked['validation_messages']);
    }

    public function test_visualizing_a_legacy_room_reanalyzes_it_for_door_openings(): void
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
        Storage::fake('public');
        $png = file_get_contents(base_path('tests/Fixtures/room-600.png'));
        Storage::disk('public')->put('rooms/legacy-door-room.png', $png);
        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode([
                    'summary' => 'Room with a doorway on the right.',
                    'vision_geometry' => [
                        'image_quality' => ['usable' => true, 'issues' => []],
                        'floor_polygon' => [[0, 0.55], [1, 0.55], [1, 1], [0, 1]],
                        'walls' => [], 'openings' => [], 'horizon_y' => 0.38,
                        'blocked_zones' => [], 'existing_furniture' => [], 'scale_clues' => [],
                        'lighting' => ['brightness' => 0.7, 'warmth' => 0.5, 'light_from' => 'front'],
                        'confidence' => 0.8,
                    ],
                    'layout' => 'Clear floor with a right doorway.',
                    'lighting' => 'Bright.',
                    'constraints' => 'Keep the opening clear.',
                    'room_type' => 'Bedroom',
                    'estimated_width_m' => 4,
                    'estimated_depth_m' => 4,
                    'dimension_confidence' => 'medium',
                    'placement_x_percent' => 84,
                    'placement_y_percent' => 91,
                    'placement_width_percent' => 17,
                    'placement_confidence' => 'high',
                    'placement_reason' => 'Detected right doorway.',
                    'openings' => [['label' => 'right doorway', 'polygon' => [[76, 28], [91, 30], [92, 91], [75, 90]]]],
                    'candidate_zones' => [],
                    'scene_confidence' => 'high',
                ])]]],
            ]],
        ])]);
        $user = User::factory()->create();
        $door = $this->product(['name' => 'Auto-fit Door', 'slug' => 'auto-fit-door', 'category' => 'Door', 'width_cm' => 90, 'depth_cm' => 12]);
        $this->actingAs($user)->post('/preferences', ['furniture_type' => 'Door', 'budget_min' => 5000, 'budget_max' => 40000]);
        $room = $this->room($user, ['image_path' => 'rooms/legacy-door-room.png', 'gemini_consent_at' => now()]);

        $this->actingAs($user)->get(route('room.visualize', ['roomImage' => $room, 'product' => $door->id]))
            ->assertOk()
            ->assertSee('data-placement-source="scene-opening"', false)
            ->assertSee('data-initial-x="83.5"', false)
            ->assertSee('data-initial-width="17"', false);

        $room->refresh();
        $this->assertSame('all catalog furniture', $room->analysis['analyzed_furniture_type']);
        $this->assertNotEmpty($room->analysis['openings']);
        $this->assertArrayHasKey('zone_analysis_attempted_at', $room->analysis);
        Http::assertSentCount(1);
    }

    public function test_placement_service_uses_advisory_automatic_fit_for_legacy_room_analysis(): void
    {
        $product = $this->product([
            'name' => 'Legacy Test Cabinet',
            'slug' => 'legacy-test-cabinet',
            'category' => 'Cabinet',
        ]);

        $placement = app(FurniturePlacementService::class)->forProduct([], $product);

        $this->assertEquals(70.0, $placement['x_percent']);
        $this->assertSame('type-fallback', $placement['source']);
        $this->assertFalse($placement['needs_confirmation']);
        $this->assertSame('advisory', $placement['validation_status']);
    }

    public function test_door_without_a_detected_opening_is_not_shown_at_an_invented_position(): void
    {
        $door = $this->product([
            'name' => 'Fallback Test Door',
            'slug' => 'fallback-test-door',
            'category' => 'Door',
            'width_cm' => 90,
            'height_cm' => 210,
        ]);

        $placement = app(FurniturePlacementService::class)->forProduct([], $door);

        $this->assertSame(50.0, $placement['x_percent']);
        $this->assertNull($placement['quad_percent']);
        $this->assertFalse($placement['renderable']);
        $this->assertSame('no-opening', $placement['validation_status']);
        $this->assertTrue($placement['needs_confirmation']);
    }

    public function test_gemini_uses_the_stable_fallback_when_the_primary_model_is_busy(): void
    {
        config([
            'services.gemini.key' => 'test-key',
            'services.gemini.model' => 'gemini-3.8-flash',
            'services.gemini.fallback_model' => 'gemini-3.6-flash',
        ]);
        Http::fake(function (ClientRequest $request) {
            if (str_contains($request->url(), 'gemini-3.8-flash')) {
                return Http::response(['error' => ['message' => 'High demand']], 503);
            }

            return Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Fallback recommendation ready.']]],
                ]],
            ]);
        });

        $result = app(GeminiService::class)->recommendationSummary(
            ['furniture_type' => 'Door'],
            [['name' => 'Test Door', 'score' => 90]]
        );

        $this->assertTrue($result['used']);
        $this->assertSame('Fallback recommendation ready.', $result['text']);
        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'gemini-3.6-flash'));
    }

    public function test_gemini_generates_a_furnished_room_from_the_room_and_selected_product(): void
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.image_model' => 'gemini-3.1-flash-image']);
        Storage::fake('public');
        $image = file_get_contents(base_path('tests/Fixtures/room-600.png'));
        Storage::disk('public')->put('rooms/input.png', $image);
        Storage::disk('public')->put('products/selected.png', $image);
        $product = $this->product(['image_path' => 'products/selected.png']);

        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($image)],
                    ]]],
                ]],
            ]),
        ]);

        $result = app(GeminiService::class)->furnishRoom('rooms/input.png', $product);

        $this->assertTrue($result['used']);
        $this->assertSame('image/png', $result['mime_type']);
        $this->assertSame($image, $result['bytes']);
        Http::assertSent(function (ClientRequest $request) use ($product): bool {
            $parts = $request->data()['contents'][0]['parts'];

            return str_contains($request->url(), 'gemini-3.1-flash-image:generateContent')
                && count($parts) === 3
                && str_contains($parts[0]['text'], $product->name)
                && isset($parts[1]['inline_data']['data'], $parts[2]['inline_data']['data'])
                && $request->data()['generationConfig']['responseModalities'] === ['IMAGE'];
        });
    }

    public function test_room_image_upload_has_a_safe_local_analysis_fallback(): void
    {
        config(['services.gemini.key' => null]);
        Storage::fake('local');
        Storage::fake('public');
        $user = User::factory()->create();
        $this->product();
        $this->actingAs($user)->post('/preferences', ['furniture_type' => 'Table', 'budget_min' => 5000, 'budget_max' => 40000]);
        $png = file_get_contents(base_path('tests/Fixtures/room-600.png'));
        $response = $this->actingAs($user)->post('/room', ['room_image' => UploadedFile::fake()->createWithContent('room.png', $png)]);
        $room = $user->roomImages()->firstOrFail();
        $response->assertRedirect(route('room.visualize', $room));
        $this->assertTrue(Storage::disk('local')->exists($room->image_path));
        $this->assertFalse(Storage::disk('public')->exists($room->image_path));
        $this->assertFalse($room->analysis['gemini_used']);
        $this->assertSame('unavailable', $room->analysis['dimension_confidence']);
        $this->assertNull($room->room_width_m);
        $this->assertNull($room->room_depth_m);
    }

    public function test_invalid_ai_geometry_is_reported_as_ready_local_fallback(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Storage::fake('local');
        Storage::fake('public');
        Http::fake(['*' => Http::response(['candidates' => [[
            'content' => ['parts' => [['text' => json_encode(['summary' => 'Missing geometry'])]]],
        ]]])]);
        $this->product();
        $user = User::factory()->create();
        $this->actingAs($user)->post('/preferences', ['furniture_type' => 'Table', 'budget_min' => 5000, 'budget_max' => 40000])->assertSessionHasNoErrors();
        $this->post('/room', [
            'room_image' => UploadedFile::fake()->createWithContent('invalid-geometry.png', file_get_contents(base_path('tests/Fixtures/room-600.png'))),
            'gemini_consent' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $room = $user->roomImages()->firstOrFail();
        $this->assertSame('fallback', $room->analysis_status);
        $this->assertFalse($room->analysis['gemini_used']);
        $this->assertStringContainsString('did not return usable room geometry', $room->analysis_error);
        $this->getJson(route('room.analysis-status', $room))->assertOk()->assertJson(['status' => 'fallback', 'ready' => true]);
        Http::assertSentCount(2);
    }

    public function test_room_analysis_is_queued_reported_and_can_be_retried_without_another_upload(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        $user = User::factory()->create();
        $this->product();
        $this->actingAs($user)->post('/preferences', ['furniture_type' => 'Table', 'budget_min' => 5000, 'budget_max' => 40000]);
        $png = file_get_contents(base_path('tests/Fixtures/room-600.png'));

        $this->actingAs($user)->post('/room', [
            'room_image' => UploadedFile::fake()->createWithContent('queued-room.png', $png),
            'gemini_consent' => true,
            'room_width_m' => 3.5,
            'room_depth_m' => 4.2,
        ])->assertRedirect();

        $room = $user->roomImages()->firstOrFail();
        $this->assertSame('queued', $room->analysis_status);
        Queue::assertPushed(AnalyzeRoomImage::class, fn (AnalyzeRoomImage $job) => $job->roomImageId === $room->id && $job->furnitureType === 'all catalog furniture');
        $this->actingAs($user)->getJson(route('room.analysis-status', $room))
            ->assertOk()
            ->assertJson(['status' => 'queued', 'ready' => false]);
        $this->actingAs($user)->post('/recommendations', ['room_image_id' => $room->id])
            ->assertSessionHasErrors('room_image_id');

        $room->update(['analysis_status' => 'failed', 'analysis_error' => 'Temporary failure.']);
        $this->actingAs($user)->post(route('room.reanalyze', $room))->assertRedirect();
        $this->assertSame('queued', $room->fresh()->analysis_status);
        Queue::assertPushed(AnalyzeRoomImage::class, 2);
    }

    public function test_preferences_are_required_but_room_is_optional_before_recommendations(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $this->product();
        $png = file_get_contents(base_path('tests/Fixtures/room-600.png'));

        $this->actingAs($user)->post('/room', [
            'room_image' => UploadedFile::fake()->createWithContent('room.png', $png),
        ])->assertRedirect(route('preferences.edit').'#preference-form')->assertSessionHasErrors('workflow');
        $this->assertSame(0, $user->roomImages()->count());

        $this->actingAs($user)->post('/recommendations', ['room_image_id' => 999])
            ->assertRedirect(route('preferences.edit').'#preference-form')
            ->assertSessionHasErrors('workflow');

        $this->actingAs($user)->post('/preferences', ['furniture_type' => 'Table', 'budget_min' => 5000, 'budget_max' => 40000]);
        $this->actingAs($user)->post('/recommendations', [])
            ->assertRedirect()->assertSessionHasNoErrors();
        $recommendation = $user->recommendations()->latest('id')->firstOrFail();
        $this->assertNull($recommendation->room_image_id);
        $this->get(route('recommendations.show', $recommendation))->assertOk();
    }

    public function test_admin_approval_sends_one_item_readiness_notification_and_logs_it(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create();
        $product = $this->product();
        $reservation = Reservation::create(['reference' => 'FC-TEST-001', 'user_id' => $customer->id, 'furniture_product_id' => $product->id, 'branch' => 'Lingayen', 'status' => 'pending']);

        $this->actingAs($admin)->patch(route('admin.reservations.update', $reservation), ['status' => 'confirmed'])->assertRedirect();

        $this->assertSame('confirmed', $reservation->fresh()->status);
        Notification::assertSentTo($customer, ReservationConfirmedNotification::class, function (ReservationConfirmedNotification $notification) use ($customer, $product): bool {
            $mail = $notification->toMail($customer);
            $message = implode(' ', $mail->introLines);

            return $mail->subject === ReservationConfirmedNotification::SUBJECT
                && str_contains($message, 'approved your FurniCraft reservation request')
                && str_contains($message, 'FC-TEST-001')
                && str_contains($message, $product->name)
                && str_contains($message, 'Selected shop: Lingayen')
                && str_contains($message, 'does not guarantee that the item is physically in stock')
                && str_contains($message, 'Contact Lance Furniture Shop before visiting');
        });

        $log = NotificationLog::where('type', 'reservation_confirmed')->firstOrFail();
        $this->assertSame(ReservationConfirmedNotification::SUBJECT, $log->subject);
        $this->assertSame('Lingayen', $log->payload['branch']);
        $this->assertSame($product->name, $log->payload['product']);
        $this->assertSame('contact_branch_to_confirm_item_readiness', $log->payload['next_step']);

        $this->actingAs($admin)->patch(route('admin.reservations.update', $reservation), ['status' => 'confirmed'])->assertRedirect();
        Notification::assertSentTo($customer, ReservationConfirmedNotification::class, 1);
        $this->assertSame(1, NotificationLog::where('type', 'reservation_confirmed')->count());
    }

    public function test_password_reset_code_is_hashed_and_rate_limited(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect('/reset-password');
        $record = PasswordResetCode::firstOrFail();
        $this->assertFalse((bool) preg_match('/^\d{6}$/', $record->code_hash));
        Notification::assertSentTo($user, PasswordResetCodeNotification::class, 1);
        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect('/reset-password')->assertSessionHas('warning');
        Notification::assertSentTo($user, PasswordResetCodeNotification::class, 1);
        $this->assertDatabaseCount('password_reset_codes', 1);
    }

    public function test_password_reset_requires_code_verification_before_showing_password_fields(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'staged-reset@example.com']);
        $this->post(route('password.email'), ['email' => $user->email])->assertRedirect(route('password.reset'));
        $code = Notification::sent($user, PasswordResetCodeNotification::class)->first()->code;

        $this->get(route('password.reset'))->assertOk()
            ->assertSee('Verify your reset code')->assertSee('Verify code')
            ->assertDontSee('New password');

        $this->post(route('password.verify'), ['email' => $user->email, 'code' => '000000'])
            ->assertRedirect(route('password.reset'))->assertSessionHasErrors('code');
        $this->get(route('password.reset'))->assertDontSee('New password');

        $this->post(route('password.verify'), ['email' => $user->email, 'code' => $code])
            ->assertRedirect(route('password.reset'))->assertSessionHas('password_reset_verified_email', $user->email);
        $this->get(route('password.reset'))->assertOk()
            ->assertSee('Choose a new password')->assertSee('name="password"', false)
            ->assertDontSee('6-digit code');

        $this->post(route('password.update'), [
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
        $this->assertNotNull(PasswordResetCode::firstOrFail()->used_at);
    }

    public function test_password_reset_cannot_skip_code_verification(): void
    {
        $user = User::factory()->create(['email' => 'skip-reset@example.com']);
        PasswordResetCode::create([
            'email' => $user->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->withSession(['reset_email' => $user->email])->post(route('password.update'), [
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect(route('password.reset'))->assertSessionHasErrors('code');

        $this->assertFalse(Hash::check('new-password-123', $user->fresh()->password));
    }

    private function room(User $user, array $overrides = []): RoomImage
    {
        return RoomImage::create(array_merge([
            'user_id' => $user->id,
            'image_path' => 'rooms/test-room.png',
            'room_width_m' => 3.5,
            'room_depth_m' => 4.2,
            'analysis' => [
                'summary' => 'Room ready.',
                'layout' => 'Open wall.',
                'lighting' => 'Bright.',
                'constraints' => 'Measure first.',
                'estimated_width_m' => 3.5,
                'estimated_depth_m' => 4.2,
                'dimension_confidence' => 'customer-provided',
                'gemini_used' => false,
            ],
            'analyzed_at' => now(),
        ], $overrides));
    }

    private function product(array $overrides = []): FurnitureProduct
    {
        return FurnitureProduct::create(array_merge([
            'name' => 'Compact Dining Table', 'slug' => 'compact-dining-table', 'category' => 'Dining Table', 'description' => 'A compact solid wood table.', 'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural', 'style' => 'Modern', 'price' => 22000,
            'width_cm' => 140, 'depth_cm' => 80, 'height_cm' => 75, 'tags' => ['dining', 'table'], 'status' => 'active',
        ], $overrides));
    }
}

