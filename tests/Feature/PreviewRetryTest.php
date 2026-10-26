<?php

namespace Tests\Feature;

use App\Jobs\GenerateRoomPreviewV2;
use App\Models\{User, RoomImage, FurnitureProduct, RoomPreviewGeneration};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Bus, Storage};
use Tests\TestCase;

class PreviewRetryTest extends TestCase
{
    use RefreshDatabase;

    private function preview(string $status = 'fallback', bool $consented = true): array
    {
        Bus::fake();
        Storage::fake('local');
        config(['roompreview.use_v2_pipeline' => true, 'roompreview.rollout_percent' => 100]);
        $user = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $room = RoomImage::create(['user_id' => $user->id, 'image_path' => 'rooms/retry.png', 'image_sha256' => str_repeat('b', 64), 'gemini_consent_at' => $consented ? now() : null]);
        Storage::disk('local')->put($room->image_path, 'fixture');
        $product = FurnitureProduct::create(['name' => 'Retry chair', 'slug' => 'retry-chair', 'description' => 'Chair', 'category' => 'Chair', 'wood_type' => 'Wood', 'varnish_finish' => 'Natural', 'style' => 'Modern', 'price' => 100, 'status' => 'active']);
        $product->refresh();
        $key = hash('sha256', implode('|', [$room->image_sha256, $product->id, $product->updated_at->toISOString(), config('roompreview.pipeline_version')]));
        $generation = RoomPreviewGeneration::create(['room_image_id' => $room->id, 'furniture_product_id' => $product->id, 'status' => $status, 'cache_key' => $key]);
        $this->actingAs($user);
        return [$room, $product, $generation];
    }

    public function test_refresh_reuses_fallback_but_explicit_retry_queues_once(): void
    {
        [$room, $product, $generation] = $this->preview();
        $url = route('room.preview-v2.store', $room);
        $this->postJson($url, ['furniture_id' => $product->id])->assertOk()->assertJsonPath('status', 'fallback');
        Bus::assertNotDispatched(GenerateRoomPreviewV2::class);
        $this->postJson($url, ['furniture_id' => $product->id, 'retry' => true])->assertStatus(202)->assertJsonPath('status', 'queued');
        $this->postJson($url, ['furniture_id' => $product->id, 'retry' => true])->assertStatus(202);
        Bus::assertDispatchedTimes(GenerateRoomPreviewV2::class, 1);
        $this->assertSame('queued', $generation->fresh()->status);
    }

    public function test_retry_cannot_bypass_consent(): void
    {
        [$room, $product] = $this->preview('fallback', false);
        $this->postJson(route('room.preview-v2.store', $room), ['furniture_id' => $product->id, 'retry' => true])->assertForbidden();
        Bus::assertNotDispatched(GenerateRoomPreviewV2::class);
    }

    public function test_retry_does_not_regenerate_verified_preview(): void
    {
        [$room, $product] = $this->preview('completed');
        $this->postJson(route('room.preview-v2.store', $room), ['furniture_id' => $product->id, 'retry' => true])->assertOk()->assertJsonPath('status', 'completed');
        Bus::assertNotDispatched(GenerateRoomPreviewV2::class);
    }
}
