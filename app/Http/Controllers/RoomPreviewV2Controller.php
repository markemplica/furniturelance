<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateRoomPreviewV2;
use App\Models\FurnitureProduct;
use App\Models\RoomImage;
use App\Models\RoomPreviewGeneration;
use App\Services\RoomPhotoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class RoomPreviewV2Controller extends Controller
{
    public function store(Request $request, RoomImage $roomImage, RoomPhotoService $photos)
    {
        abort_unless($roomImage->user_id === $request->user()->id, 404);
        abort_unless($this->enabledFor($request->user()->id), 404, 'This preview pipeline is not enabled.');

        $unexpected = array_diff(array_keys($request->except('_token')), ['furniture_id', 'retry']);
        abort_if($unexpected !== [], 422, 'Only furniture_id and retry are accepted.');
        $data = $request->validate(['furniture_id' => ['required', 'integer', 'min:1'], 'retry' => ['sometimes', 'boolean']]);
        $product = FurnitureProduct::query()->findOrFail((int) $data['furniture_id']);
        abort_unless($product->status === 'active', 422, 'This catalog item is inactive.');
        abort_unless($photos->exists($roomImage->image_path), 422, 'The room photo is unavailable.');

        if ($request->boolean('retry')) {
            abort_unless($roomImage->gemini_consent_at, 403, 'Enable AI processing for this photo before retrying.');
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $roomImage, $photos, $product) {
            RoomImage::whereKey($roomImage->id)->lockForUpdate()->firstOrFail();
            $hash = $roomImage->image_sha256 ?: $photos->metadata($roomImage->image_path)['sha256'];
            $version = (string) config('roompreview.pipeline_version', 'v2');
            $cacheKey = hash('sha256', implode('|', [$hash, $product->id, $product->updated_at?->toISOString(), $version]));
            $generation = RoomPreviewGeneration::firstOrNew([
                'room_image_id' => $roomImage->id,
                'furniture_product_id' => $product->id,
            ]);
            if ($generation->exists && hash_equals((string) $generation->cache_key, $cacheKey)
                && in_array($generation->status, ['queued', 'processing', 'retrying', 'completed', 'fallback'], true)
                && ! ($request->boolean('retry') && $generation->status === 'fallback')) {
                return response()->json($this->payload($generation, $roomImage), in_array($generation->status, ['completed', 'fallback'], true) ? 200 : 202);
            }

            $generation->fill([
                'status' => 'queued', 'attempts' => 0, 'message' => 'Preview queued.',
                'placement_json' => null, 'cache_key' => $cacheKey, 'failure_code' => null,
                'confidence' => null, 'confidence_label' => null, 'metrics_json' => null, 'completed_at' => null,
            ]);
            $generation->save();
            GenerateRoomPreviewV2::dispatch($generation->id, $cacheKey)->afterCommit();

            return response()->json($this->payload($generation, $roomImage), 202);
        });
    }

    public function status(Request $request, RoomImage $roomImage, RoomPreviewGeneration $generation)
    {
        abort_unless($roomImage->user_id === $request->user()->id, 404);
        abort_unless((int) $generation->room_image_id === (int) $roomImage->id, 404);
        return response()->json($this->payload($generation, $roomImage));
    }

    public function artifact(Request $request, RoomPreviewGeneration $generation)
    {
        $room = RoomImage::findOrFail($generation->room_image_id);
        abort_unless($room->user_id === $request->user()->id, 404);
        $path = data_get($generation->placement_json, 'artifact_path');
        abort_unless(filled($path) && app(RoomPhotoService::class)->exists($path), 404);
        $photos = app(RoomPhotoService::class);
        $mime = $photos->mimeType($path);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);
        return response($photos->get($path), 200, [
            'Content-Type' => $mime, 'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff', 'Vary' => 'Cookie, Authorization',
        ]);
    }

    private function payload(RoomPreviewGeneration $generation, RoomImage $room): array
    {
        $data = $generation->placement_json ?? [];
        $path = data_get($data, 'artifact_path');
        return [
            'status' => $generation->status,
            'message' => $generation->message,
            'failure_code' => $generation->failure_code,
            'confidence' => $generation->confidence,
            'confidence_label' => $generation->confidence_label,
            'placement' => data_get($data, 'placement'),
            'disclaimer' => data_get($data, 'disclaimer'),
            'preview_url' => $path ? URL::temporarySignedRoute('room.preview-v2.artifact', now()->addMinutes((int) config('roompreview.signed_url_minutes', 5)), ['generation' => $generation->id]) : null,
            'status_url' => route('room.preview-v2.status', [$room, $generation]),
            'metrics' => $generation->metrics_json,
        ];
    }

    private function enabledFor(int $userId): bool
    {
        if (! config('roompreview.use_v2_pipeline')) return false;
        $rollout = (int) config('roompreview.rollout_percent', 0);
        if ($rollout >= 100) return true;
        if ($rollout <= 0) return false;
        return (hexdec(substr(hash('sha256', (string) $userId), 0, 7)) % 100) < $rollout;
    }
}
