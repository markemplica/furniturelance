<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ReservationController as AdminReservationController;
use App\Jobs\AnalyzeRoomImage;
use App\Jobs\GenerateRoomPreview;
use App\Models\FurnitureProduct;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\ReservationTransaction;
use App\Models\RoomImage;
use App\Models\RoomPreviewGeneration;
use App\Models\User;
use App\Services\AiTelemetry;
use App\Services\GeminiService;
use App\Services\PrivacyAudit;
use App\Services\RecommendationService;
use App\Services\RoomPhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkflowRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_popup_submits_and_reports_errors_without_redirecting(): void
    {
        $customer = User::factory()->create();
        $product = $this->product();
        $this->actingAs($customer)->get(route('catalog.show', $product))
            ->assertOk()->assertSee('data-reservation-open', false)->assertSee('<dialog', false);
        $payload = ['furniture_product_id' => $product->id, 'branch' => config('furnicraft.branch')];
        $this->postJson(route('reservations.store'), array_merge($payload, ['branch' => 'invalid']))
            ->assertUnprocessable()->assertJsonValidationErrors('branch');
        $this->assertDatabaseCount('reservations', 0);
        $response = $this->postJson(route('reservations.store'), ['furniture_product_id' => $product->id])
            ->assertCreated()->assertJsonStructure(['message', 'reference']);
        $this->assertDatabaseHas('reservations', ['reference' => $response->json('reference'), 'user_id' => $customer->id]);
        $this->assertDatabaseHas('reservations', ['reference' => $response->json('reference'), 'branch' => config('furnicraft.branch')]);
        $this->postJson(route('reservations.store'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('furniture_product_id');
        $this->assertDatabaseCount('reservations', 1);
    }

    private function product(): FurnitureProduct
    {
        return FurnitureProduct::create([
            'name' => 'Regression Table', 'slug' => 'regression-table',
            'category' => 'Dining Table', 'description' => 'Table',
            'wood_type' => 'Gmelina', 'varnish_finish' => 'Narra Look / Black',
            'style' => 'Modern', 'price' => 1000, 'status' => 'active',
        ]);
    }

    private function reservation(User $user, string $status = 'confirmed'): Reservation
    {
        return Reservation::create([
            'reference' => 'FC-REGRESSION', 'user_id' => $user->id,
            'furniture_product_id' => $this->product()->id,
            'branch' => 'Lingayen', 'status' => $status,
        ]);
    }

    private function room(User $user): RoomImage
    {
        return RoomImage::create([
            'user_id' => $user->id, 'image_path' => 'rooms/regression.png',
            'analysis_status' => 'queued', 'analysis_requested_at' => now(),
            'gemini_consent_at' => now(), 'gemini_consent_version' => 'room-ai-v1',
            'analysis' => ['generated_previews' => ['1' => ['path' => 'rooms/old.png']]],
        ]);
    }

    private function analyze(RoomImage $room, GeminiService $gemini, RoomPhotoService $photos): void
    {
        (new AnalyzeRoomImage($room->id, 'Table'))->handle(
            $gemini, $photos, app(PrivacyAudit::class), app(AiTelemetry::class),
        );
    }

    public function test_material_rollback_rejects_lossy_values_before_any_ddl(): void
    {
        $product = $this->product();
        $manager = DB::getFacadeRoot();
        $connection = DB::connection();
        DB::shouldReceive('getDriverName')->andReturn('mysql');
        DB::shouldReceive('table')->andReturnUsing(fn ($table) => $connection->table($table));
        DB::shouldReceive('statement')->never();
        $migration = require database_path('migrations/2026_10_04_000001_expand_catalog_material_fields.php');
        try {
            $migration->down();
            $this->fail('Lossy rollback should be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Rollback stopped', $exception->getMessage());
        } finally {
            DB::swap($manager);
        }
        $this->assertSame('Narra Look / Black', $product->fresh()->varnish_finish);
    }

    public function test_closeout_is_terminal_and_cannot_be_cancelled_or_reopened(): void
    {
        Notification::fake();
        $customer = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $reservation = $this->reservation($customer);
        $this->actingAs($admin)->patch(route('admin.reservations.update', $reservation), [
            'status' => 'confirmed', 'outcome' => 'completed',
        ])->assertRedirect();
        $this->assertSame('completed', $reservation->fresh()->status);
        $this->assertNotNull($reservation->fresh()->completed_at);
        $this->actingAs($customer)->patch(route('reservations.cancel', $reservation))->assertStatus(422);
        $this->actingAs($admin)->patch(route('admin.reservations.update', $reservation), [
            'status' => 'confirmed',
        ])->assertStatus(422);
        $this->assertSame(1, $reservation->transactions()->count());
        $this->actingAs($customer)->post(route('reservations.store'), [
            'furniture_product_id' => $reservation->furniture_product_id, 'branch' => config('furnicraft.branch'),
        ])->assertRedirect(route('reservations.index'));
        $this->assertDatabaseCount('reservations', 2);
    }

    public function test_pending_reservation_cannot_be_closed_out(): void
    {
        $customer = User::factory()->create();
        $reservation = $this->reservation($customer, 'pending');
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patch(route('admin.reservations.update', $reservation), [
                'status' => 'confirmed', 'outcome' => 'no_show',
            ])->assertStatus(422);
        $this->assertSame('pending', $reservation->fresh()->status);
        $this->assertSame(0, $reservation->transactions()->count());
    }

    public function test_admin_rereads_stale_route_model_before_changing_status(): void
    {
        $customer = User::factory()->create();
        $reservation = $this->reservation($customer);
        Reservation::whereKey($reservation->id)->update(['status' => 'cancelled']);
        $request = Request::create('/', 'PATCH', ['status' => 'confirmed', 'outcome' => 'completed']);
        $admin = User::factory()->create(['role' => 'admin']);
        $request->setUserResolver(fn () => $admin);
        try {
            app(AdminReservationController::class)->update($request, $reservation);
            $this->fail('The current cancelled state must win over a stale route model.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertSame('cancelled', $reservation->fresh()->status);
        $this->assertSame(0, $reservation->transactions()->count());
    }

    public function test_status_change_rolls_back_when_history_cannot_be_written(): void
    {
        Notification::fake();
        $customer = User::factory()->create();
        $reservation = $this->reservation($customer, 'pending');
        ReservationTransaction::creating(function () {
            throw new \RuntimeException('History write unavailable');
        });
        try {
            $this->actingAs(User::factory()->create(['role' => 'admin']))
                ->patch(route('admin.reservations.update', $reservation), ['status' => 'confirmed'])
                ->assertStatus(500);
            $this->assertSame('pending', $reservation->fresh()->status);
            $this->assertDatabaseCount('notification_logs', 0);
            Notification::assertNothingSent();
        } finally {
            ReservationTransaction::flushEventListeners();
        }
    }

    public function test_customer_cancellation_rolls_back_when_history_cannot_be_written(): void
    {
        $customer = User::factory()->create();
        $reservation = $this->reservation($customer);
        ReservationTransaction::creating(function () {
            throw new \RuntimeException('History write unavailable');
        });
        try {
            $this->actingAs($customer)->patch(route('reservations.cancel', $reservation))->assertStatus(500);
            $this->assertSame('confirmed', $reservation->fresh()->status);
        } finally {
            ReservationTransaction::flushEventListeners();
        }
    }

    public function test_selected_room_clears_measurements_from_previous_room(): void
    {
        $customer = User::factory()->create();
        $preference = $customer->preference()->create([
            'furniture_type' => 'Table', 'budget_min' => 0, 'budget_max' => 2000,
            'room_width_m' => 9, 'room_depth_m' => 8,
        ]);
        $room = $this->room($customer);
        $room->update(['analysis_status' => 'fallback', 'room_width_m' => 3, 'room_depth_m' => null]);
        $this->mock(RecommendationService::class)->shouldReceive('generate')->once()
            ->andReturnUsing(function ($user, $current, $selected, $type) use ($room) {
                $this->assertSame($room->id, $selected->id);
                $this->assertEquals(3, $current->room_width_m);
                $this->assertNull($current->room_depth_m);
                return Recommendation::create([
                    'user_id' => $user->id, 'customer_preference_id' => $current->id,
                    'room_image_id' => $selected->id, 'furniture_type' => $type, 'summary' => 'Regression',
                ]);
            });
        $this->actingAs($customer)->post(route('recommendations.store'), ['room_image_id' => $room->id])->assertRedirect();
        $this->assertNull($preference->fresh()->room_depth_m);
    }

    public function test_reanalysis_preserves_previews_committed_during_the_ai_request(): void
    {
        $room = $this->room(User::factory()->create());
        $photos = $this->mock(RoomPhotoService::class);
        $photos->shouldReceive('exists')->once()->andReturnTrue();
        $gemini = $this->partialMock(GeminiService::class);
        $gemini->shouldReceive('isConfigured')->andReturnTrue();
        $gemini->shouldReceive('analyzeRoom')->once()->andReturnUsing(function () use ($room) {
            $room->update(['analysis' => [
                'generated_previews' => ['2' => ['path' => 'rooms/new.png']],
                'generated_preview_attempts' => ['2' => '2026-10-04T00:00:00Z'],
            ]]);
            return ['summary' => 'New analysis', 'estimated_width_m' => 4, 'gemini_used' => true];
        });
        $this->analyze($room, $gemini, $photos);
        $analysis = $room->fresh()->analysis;
        $this->assertSame('New analysis', $analysis['summary']);
        $this->assertSame('rooms/new.png', $analysis['generated_previews'][2]['path']);
        $this->assertArrayHasKey(2, $analysis['generated_preview_attempts']);
        $this->assertSame('completed', $room->fresh()->analysis_status);
    }

    public function test_revocation_during_analysis_discards_the_ai_response(): void
    {
        $customer = User::factory()->create();
        $room = $this->room($customer);
        $photos = $this->mock(RoomPhotoService::class);
        $photos->shouldReceive('exists')->andReturnTrue();
        $photos->shouldReceive('deleteGeneratedPreviews')->once();
        $gemini = $this->partialMock(GeminiService::class);
        $gemini->shouldReceive('isConfigured')->andReturnTrue();
        $gemini->shouldReceive('analyzeRoom')->once()->andReturnUsing(function () use ($customer, $room) {
            $this->actingAs($customer)->delete(route('room.ai-consent.revoke', $room))->assertRedirect();
            return ['summary' => 'Stale AI response', 'estimated_width_m' => 10, 'gemini_used' => true];
        });
        $this->analyze($room, $gemini, $photos);
        $current = $room->fresh();
        $this->assertNull($current->gemini_consent_at);
        $this->assertNull($current->room_width_m);
        $this->assertSame('fallback', $current->analysis_status);
        $this->assertFalse($current->analysis['gemini_used']);
        $this->assertArrayNotHasKey('generated_previews', $current->analysis);
        (new AnalyzeRoomImage($room->id))->failed(new \RuntimeException('Late failure'));
        $this->assertSame('fallback', $room->fresh()->analysis_status);
    }

    public function test_revocation_during_failed_analysis_does_not_restore_retrying_state(): void
    {
        $customer = User::factory()->create();
        $room = $this->room($customer);
        $photos = $this->mock(RoomPhotoService::class);
        $photos->shouldReceive('exists')->andReturnTrue();
        $photos->shouldReceive('deleteGeneratedPreviews')->once();
        $gemini = $this->partialMock(GeminiService::class);
        $gemini->shouldReceive('isConfigured')->andReturnTrue();
        $gemini->shouldReceive('analyzeRoom')->once()->andReturnUsing(function () use ($customer, $room) {
            $this->actingAs($customer)->delete(route('room.ai-consent.revoke', $room))->assertRedirect();
            throw new \RuntimeException('Provider unavailable');
        });
        $this->analyze($room, $gemini, $photos);
        $this->assertSame('fallback', $room->fresh()->analysis_status);
    }

    public function test_preview_save_preserves_analysis_committed_during_generation(): void
    {
        $room = $this->room(User::factory()->create());
        $product = $this->product();
        $generation = RoomPreviewGeneration::create([
            'room_image_id' => $room->id, 'furniture_product_id' => $product->id, 'status' => 'queued',
        ]);
        $photos = $this->mock(RoomPhotoService::class);
        $photos->shouldReceive('storeGenerated')->once();
        $gemini = $this->mock(GeminiService::class);
        $gemini->shouldReceive('furnishRoom')->once()->andReturnUsing(function () use ($room) {
            $room->update(['analysis' => ['summary' => 'Latest analysis', 'generated_previews' => [
                '99' => ['path' => 'rooms/another.png'],
            ]]]);
            return ['used' => true, 'bytes' => 'mock image', 'mime_type' => 'image/png'];
        });
        (new GenerateRoomPreview($room->id, $product->id))->handle(
            $gemini, $photos, app(PrivacyAudit::class), app(AiTelemetry::class),
        );
        $this->assertSame('Latest analysis', $room->fresh()->analysis['summary']);
        $this->assertCount(2, $room->fresh()->analysis['generated_previews']);
        $this->assertSame('completed', $generation->fresh()->status);
    }

    public function test_expiry_does_not_overwrite_an_approval_after_candidate_selection(): void
    {
        $reservation = $this->reservation(User::factory()->create(), 'pending');
        $reservation->update(['expires_at' => now()->subDay()]);
        Reservation::retrieved(function ($candidate) {
            if ($candidate->status === 'pending') {
                Reservation::whereKey($candidate->id)->update(['status' => 'confirmed']);
            }
        });
        try {
            $this->artisan('reservations:expire')->assertSuccessful();
            $this->assertSame('confirmed', $reservation->fresh()->status);
            $this->assertSame(0, $reservation->transactions()->count());
        } finally {
            Reservation::flushEventListeners();
        }
    }

    public function test_revocation_during_preview_generation_prevents_image_storage(): void
    {
        $customer = User::factory()->create();
        $room = $this->room($customer);
        $product = $this->product();
        $generation = RoomPreviewGeneration::create([
            'room_image_id' => $room->id, 'furniture_product_id' => $product->id, 'status' => 'queued',
        ]);
        $photos = $this->mock(RoomPhotoService::class);
        $photos->shouldReceive('deleteGeneratedPreviews')->once();
        $photos->shouldNotReceive('storeGenerated');
        $gemini = $this->partialMock(GeminiService::class);
        $gemini->shouldReceive('furnishRoom')->once()->andReturnUsing(function () use ($customer, $room) {
            $this->actingAs($customer)->delete(route('room.ai-consent.revoke', $room))->assertRedirect();
            return ['used' => true, 'bytes' => 'stale mock image', 'mime_type' => 'image/png'];
        });
        (new GenerateRoomPreview($room->id, $product->id))->handle(
            $gemini, $photos, app(PrivacyAudit::class), app(AiTelemetry::class),
        );
        $this->assertSame('cancelled', $generation->fresh()->status);
        $this->assertArrayNotHasKey('generated_previews', $room->fresh()->analysis);
    }
}
