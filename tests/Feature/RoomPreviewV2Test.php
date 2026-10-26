<?php

namespace Tests\Feature;

use App\Models\RoomImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomPreviewV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_v2_endpoint_stays_hidden_until_feature_flag_is_enabled(): void
    {
        config()->set('roompreview.use_v2_pipeline', false);
        $user = User::factory()->create(['role' => 'customer']);
        $room = RoomImage::create([
            'user_id' => $user->id,
            'image_path' => 'rooms/'.$user->id.'/sample.png',
            'image_sha256' => str_repeat('a', 64),
            'analysis_status' => 'completed',
        ]);

        $this->actingAs($user)
            ->post(route('room.preview-v2.store', $room), ['furniture_id' => 1])
            ->assertNotFound();
    }

    public function test_preview_image_normalizer_rejects_invalid_image_bytes(): void
    {
        $this->expectException(\RuntimeException::class);
        app(\App\Services\RoomPreviewRasterService::class)->normalize('not-an-image');
    }
    public function test_v2_endpoint_rejects_client_prompts_and_placement_values(): void
    {
        config()->set('roompreview.use_v2_pipeline', true);
        config()->set('roompreview.rollout_percent', 100);
        $user = User::factory()->create(['role' => 'customer']);
        $room = RoomImage::create([
            'user_id' => $user->id,
            'image_path' => 'rooms/'.$user->id.'/sample.png',
            'image_sha256' => str_repeat('b', 64),
            'analysis_status' => 'completed',
        ]);

        $this->actingAs($user)
            ->post(route('room.preview-v2.store', $room), [
                'furniture_id' => 1,
                'prompt' => 'put furniture by the window',
                'placement' => ['x' => 0.5, 'y' => 0.8],
            ])
            ->assertUnprocessable();
    }

    public function test_server_verifier_uses_header_auth_and_requires_all_five_scores(): void
    {
        config([
            'services.gemini.key' => 'unit-test-secret',
            'services.gemini.model' => 'gemini-test',
            'services.gemini.fallback_model' => null,
        ]);
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::fake('public');
        $png = file_get_contents(base_path('tests/Fixtures/room-600.png'));
        \Illuminate\Support\Facades\Storage::disk('local')->put('rooms/v2-source.png', $png);
        \Illuminate\Support\Facades\Storage::disk('public')->put('products/v2-item.png', $png);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode([
                'scores' => ['scale' => .8, 'placement' => .9, 'catalog_fidelity' => .85, 'room_preservation' => .9, 'lighting' => .8],
                'single_item_only' => true,
                'catalog_item_only' => true,
            ])]]]]],
        ])]);

        $product = new \App\Models\FurnitureProduct([
            'name' => 'Catalog Item', 'category' => 'Chair', 'width_cm' => 80, 'depth_cm' => 70, 'height_cm' => 90,
            'image_path' => 'products/v2-item.png',
        ]);
        $result = app(\App\Services\GeminiService::class)->verifyGeneratedRoom('rooms/v2-source.png', $png, $product, 1);

        $this->assertTrue($result['passed']);
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) =>
            $request->header('x-goog-api-key') === ['unit-test-secret']
            && ! str_contains($request->url(), 'key=')
        );
    }


    public function test_complex_schema_rejection_retries_json_mode_without_losing_image_input(): void
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'test-model']);
        \Illuminate\Support\Facades\Http::fakeSequence()
            ->push(['error' => ['message' => 'Invalid argument']], 400)
            ->push(['candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]]], 200);
        $method = new \ReflectionMethod(\App\Services\GeminiService::class, 'request');
        $parts = [['text' => 'Analyze room'], ['inline_data' => ['mime_type' => 'image/png', 'data' => 'fixture']]];
        $result = $method->invoke(app(\App\Services\GeminiService::class), $parts, true, ['type' => 'object'], 'room_analysis', 10, 0, 1);
        $this->assertSame('{"ok":true}', $result);
        \Illuminate\Support\Facades\Http::assertSentCount(2);
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) =>
            data_get($request->data(), 'contents.0.parts.1.inline_data.data') === 'fixture'
            && data_get($request->data(), 'generationConfig.responseMimeType') === 'application/json'
            && data_get($request->data(), 'generationConfig.responseJsonSchema') === null
            && str_contains(data_get($request->data(), 'contents.0.parts.2.text', ''), 'schema')
        );
    }

    public function test_preview_accepts_landscape_and_portrait_uploads_by_longest_side(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD required');
        foreach ([[720, 480], [480, 720]] as [$width, $height]) {
            $image = imagecreatetruecolor($width, $height);
            ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
            $normalized = app(\App\Services\RoomPreviewRasterService::class)->normalize($bytes);
            $this->assertSame($width, $normalized['width']);
            $this->assertSame($height, $normalized['height']);
        }
    }

    public function test_preview_rejects_upload_with_longest_side_below_minimum(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD required');
        $image = imagecreatetruecolor(500, 400);
        ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
        $this->expectExceptionMessage('image_too_small');
        app(\App\Services\RoomPreviewRasterService::class)->normalize($bytes);
    }
}
