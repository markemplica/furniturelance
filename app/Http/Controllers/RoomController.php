<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeRoomImage;
use App\Jobs\GenerateRoomPreview;
use App\Models\FurnitureProduct;
use App\Models\Recommendation;
use App\Models\RoomImage;
use App\Models\RoomPreviewGeneration;
use App\Services\FurniturePlacementService;
use App\Services\GeminiService;
use App\Services\PrivacyAudit;
use App\Services\RoomPhotoService;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class RoomController extends Controller
{
    public function index(Request $request)
    {
        return redirect()->to(route('preferences.edit').'#room-preview');
    }

    public function store(Request $request, GeminiService $gemini, RoomPhotoService $photos, PrivacyAudit $audit)
    {
        $data = $request->validate(['room_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'], 'room_width_m' => ['nullable', 'numeric', 'min:1', 'max:50', 'required_with:room_depth_m'], 'room_depth_m' => ['nullable', 'numeric', 'min:1', 'max:50', 'required_with:room_width_m'], 'return_to' => ['nullable', 'in:recommendations'], 'gemini_consent' => ['nullable', 'boolean']]);
        $preference = $request->user()->preference()->first();
        if (! $preference || blank($preference->furniture_type)) {
            return redirect()->to(route('preferences.edit').'#preference-form')->withErrors(['workflow' => 'Save your furniture preferences before uploading a room image.']);
        }

        $limitKey = 'room-analysis-user:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($limitKey, 10)) {
            return back()->withInput()->withErrors(['room_image' => 'You have reached the room analysis limit. Try again in '.RateLimiter::availableIn($limitKey).' seconds.']);
        }
        RateLimiter::hit($limitKey, 3600);
        $path = $photos->storeUpload($request->file('room_image'), $request->user()->id);
        $photoMetadata = $photos->metadata($path);
        if (max($photoMetadata['width'], $photoMetadata['height']) < 600 || max($photoMetadata['width'], $photoMetadata['height']) > 1600) {
            $photos->deleteGenerated($path);
            return back()->withInput()->withErrors(['room_image' => 'The prepared room photo must be between 600 and 1600 pixels on its longest side. Select the photo again.']);
        }
        $consented = $request->boolean('gemini_consent');
        $cachedQuery = RoomImage::where('user_id', $request->user()->id)
            ->where('image_sha256', $photoMetadata['sha256']);
        if ($consented) {
            $cachedQuery->where('analysis_status', 'completed')->whereJsonContains('analysis->gemini_used', true);
        } else {
            $cachedQuery->whereIn('analysis_status', ['completed', 'fallback']);
        }
        $cachedAnalysis = $cachedQuery->latest('analyzed_at')->first();
        $width = isset($data['room_width_m']) ? (float) $data['room_width_m'] : null;
        $depth = isset($data['room_depth_m']) ? (float) $data['room_depth_m'] : null;
        $room = RoomImage::create([
            'user_id' => $request->user()->id,
            'image_path' => $path,
            'image_sha256' => $photoMetadata['sha256'],
            'image_width_px' => $photoMetadata['width'],
            'image_height_px' => $photoMetadata['height'],
            'room_width_m' => $width,
            'room_depth_m' => $depth,
            'analysis' => $gemini->roomFallback($width, $depth, $preference->furniture_type),
            'analysis_status' => 'queued',
            'analysis_requested_at' => now(),
            'gemini_consent_at' => $consented ? now() : null,
            'gemini_consent_version' => $consented ? 'room-ai-v1' : null,
        ]);
        if ($consented) $audit->record('room_photo_gemini_consent_granted', 'room_image', $room->id, ['consent_version' => 'room-ai-v1']);
        if ($cachedAnalysis) {
            $room->update(['analysis' => $cachedAnalysis->analysis, 'analysis_status' => $cachedAnalysis->analysis_status, 'analysis_error' => $cachedAnalysis->analysis_error, 'analyzed_at' => now()]);
        } elseif (! $consented || ! $gemini->isConfigured()) {
            $room->update([
                'analysis_status' => 'fallback',
                'analysis_error' => $consented
                    ? 'Gemini is not configured; safe local planning guidance is being used.'
                    : 'AI image processing was not authorized; local planning guidance is being used.',
                'analyzed_at' => now(),
            ]);
        } else {
            AnalyzeRoomImage::dispatch($room->id, 'all catalog furniture');
        }
        $room->refresh();
        $message = in_array($room->analysis_status, ['completed', 'fallback'], true)
            ? 'Your room is ready and selected for recommendations.'
            : 'Your room was uploaded. FurniCraft is analyzing it in the background.';

        if (($data['return_to'] ?? null) === 'recommendations') {
            return redirect()->to(route('preferences.edit', ['room' => $room->id]).'#room-preview')->with('success', $message);
        }

        return redirect()->route('room.visualize', $room)->with('success', $message);
    }

    public function visualize(Request $request, RoomImage $roomImage, GeminiService $gemini, FurniturePlacementService $placementService, RoomPhotoService $photos, PrivacyAudit $audit)
    {
        abort_unless($roomImage->user_id === $request->user()->id || $request->user()->isAdmin(), 403);

        $recommendation = null;

        if ($request->filled('recommendation')) {
            $recommendation = Recommendation::with('items.product')
                ->where('user_id', $request->user()->id)
                ->findOrFail($request->integer('recommendation'));

            abort_unless($recommendation->room_image_id === $roomImage->id, 403);
        }

        $products = FurnitureProduct::where('status', 'active')->orderBy('name')->get();
        $firstRecommendedProduct = $recommendation?->items->first()?->product;
        $selectedProduct = $request->filled('product')
            ? $products->firstWhere('id', $request->integer('product'))
            : ($firstRecommendedProduct ?? $products->first());

        abort_if($request->filled('product') && ! $selectedProduct, 404);

        $analysis = $roomImage->analysis ?? [];

        $zonesMissing = blank($analysis['candidate_zones'] ?? null)
            || (int) data_get($analysis, 'placement_profile_version', 0) < 2;
        $analysisRunning = in_array($roomImage->analysis_status, ['queued', 'processing', 'retrying'], true);
        $canAnalyzeZones = ! $roomImage->analysis_requested_at || $roomImage->analysis_requested_at->lt(now()->subMinutes(10));

        if (! $request->user()->isAdmin() && $roomImage->gemini_consent_at && $selectedProduct && $zonesMissing && $gemini->isConfigured() && ! $analysisRunning && $canAnalyzeZones) {
            $roomImage->update([
                'analysis_status' => 'queued',
                'analysis_error' => null,
                'analysis_requested_at' => now(),
            ]);
            AnalyzeRoomImage::dispatch($roomImage->id, 'all catalog furniture');
            $roomImage->refresh();
        }

        $selectedPlacement = $selectedProduct
            ? $placementService->forProduct(
                $roomImage->analysis ?? [],
                $selectedProduct,
                $roomImage->room_width_m ? (float) $roomImage->room_width_m : null,
                $roomImage->room_depth_m ? (float) $roomImage->room_depth_m : null,
            )
            : null;

        return view('room.visualize', compact('roomImage', 'selectedProduct', 'recommendation', 'selectedPlacement'));
    }

    public function verifyPreview(Request $request, RoomImage $roomImage, FurnitureProduct $product, GeminiService $gemini)
    {
        abort_unless($roomImage->user_id === $request->user()->id, 403);
        abort_unless($roomImage->gemini_consent_at && $gemini->isConfigured(), 403, 'AI preview checking was not enabled for this photo.');
        abort_unless($product->status === 'active', 404);
        abort_unless($product->width_cm && $product->depth_cm && $product->height_cm, 422, 'Dimensions not available for this item.');

        $data = $request->validate([
            'preview' => ['required', 'string', 'max:2666668'],
            'placement' => ['required', 'array'],
            'placement.mode' => ['required', 'in:floor,wall,door'],
            'placement.x' => ['required', 'numeric', 'between:0,1'],
            'placement.y' => ['required', 'numeric', 'between:0,1'],
            'placement.width' => ['required', 'numeric', 'between:0,1'],
            'placement.height' => ['required', 'numeric', 'between:0,1'],
            'placement.scale' => ['required', 'numeric', 'between:0.6,1.3'],
        ]);
        $result = $gemini->verifyRoomPlacement($roomImage->image_path, $data['preview'], $data['placement']);

        $generation = RoomPreviewGeneration::firstOrNew([
            'room_image_id' => $roomImage->id,
            'furniture_product_id' => $product->id,
        ]);
        $history = $generation->placement_json ?? ['iterations' => []];
        $history['iterations'][] = [
            'placement' => $data['placement'],
            'verifier' => $result,
            'checked_at' => now()->toIso8601String(),
        ];
        $history['iterations'] = array_slice($history['iterations'], -3);
        $generation->fill([
            'status' => 'completed',
            'attempts' => count($history['iterations']),
            'placement_json' => $history,
            'message' => $result['valid'] ? 'Automatic visual check completed.' : 'Safe local placement retained; AI visual check was unavailable.',
        ])->save();

        return response()->json($result);
    }
    public function destroy(Request $request, RoomImage $roomImage, RoomPhotoService $photos, PrivacyAudit $audit)
    {
        abort_unless($roomImage->user_id === $request->user()->id, 403);
        $roomId = $roomImage->id;
        DB::transaction(function () use ($roomImage, $photos) {
            $roomImage = RoomImage::whereKey($roomImage->id)->lockForUpdate()->firstOrFail();
            $photos->deleteRoomFiles($roomImage);
            $roomImage->delete();
        });
        $audit->record('room_photo_deleted', 'room_image', $roomId);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'The room photo and generated previews were deleted.', 'room_id' => $roomId]);
        }

        return redirect()->route('preferences.edit')->with('success', 'The room photo and generated previews were deleted.');
    }

    public function revokeAiConsent(Request $request, RoomImage $roomImage, RoomPhotoService $photos, PrivacyAudit $audit, GeminiService $gemini)
    {
        abort_unless($roomImage->user_id === $request->user()->id, 403);
        DB::transaction(function () use ($request, $roomImage, $photos, $gemini) {
            $roomImage = RoomImage::whereKey($roomImage->id)->lockForUpdate()->firstOrFail();
            $photos->deleteGeneratedPreviews($roomImage);
            RoomPreviewGeneration::where('room_image_id', $roomImage->id)->whereIn('status', ['queued', 'processing', 'retrying'])
                ->update(['status' => 'cancelled', 'message' => 'AI processing is disabled for this photo.']);
            $analysis = $gemini->roomFallback(
                $roomImage->room_width_m ? (float) $roomImage->room_width_m : null,
                $roomImage->room_depth_m ? (float) $roomImage->room_depth_m : null,
                'all catalog furniture',
            );
            $roomImage->update([
                'gemini_consent_at' => null,
                'gemini_consent_version' => null,
                'analysis' => $analysis,
                'analysis_status' => 'fallback',
                'analysis_error' => 'AI processing disabled by you; local planning guidance is being used.',
                'analyzed_at' => now(),
            ]);
        });
        $audit->record('room_photo_gemini_consent_revoked', 'room_image', $roomImage->id);

        return back()->with('success', 'AI processing is disabled for this photo. Future AI requests are stopped and generated previews were deleted.');
    }

    public function reanalyze(Request $request, RoomImage $roomImage)
    {
        abort_unless($roomImage->user_id === $request->user()->id, 403);
        abort_if(in_array($roomImage->analysis_status, ['queued', 'processing', 'retrying'], true), 422, 'Room analysis is already in progress.');

        $roomImage->update([
            'analysis_status' => 'queued',
            'analysis_error' => null,
            'analysis_requested_at' => now(),
        ]);
        AnalyzeRoomImage::dispatch($roomImage->id, 'all catalog furniture');
        $roomImage->refresh();

        $message = $roomImage->analysis_status === 'completed'
            ? 'Room analysis completed successfully.'
            : ($roomImage->analysis_status === 'fallback'
                ? 'Local room guidance is ready; Gemini is not configured.'
                : 'Room re-analysis has been queued.');

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'status' => $roomImage->analysis_status])
            : back()->with('success', $message);
    }

    public function analysisStatus(Request $request, RoomImage $roomImage, GeminiService $gemini)
    {
        abort_unless($roomImage->user_id === $request->user()->id, 403);

        if ($roomImage->analysis_status === 'queued' && (! $roomImage->gemini_consent_at || ! $gemini->isConfigured())) {
            RoomImage::whereKey($roomImage->id)->where('analysis_status', 'queued')->update([
                'analysis_status' => 'fallback',
                'analysis_error' => $roomImage->gemini_consent_at
                    ? 'Gemini is not configured; safe local planning guidance is being used.'
                    : 'AI image processing was not authorized; local planning guidance is being used.',
                'analyzed_at' => now(),
            ]);
            $roomImage->refresh();
        }

        return response()->json([
            'status' => $roomImage->analysis_status,
            'attempts' => $roomImage->analysis_attempts,
            'message' => $roomImage->analysis_error,
            'ready' => in_array($roomImage->analysis_status, ['completed', 'fallback'], true),
            'analyzed_at' => $roomImage->analyzed_at?->toIso8601String(),
        ]);
    }

    public function previewStatus(Request $request, RoomImage $roomImage, int $product)
    {
        abort_unless($roomImage->user_id === $request->user()->id, 403);
        abort_unless($roomImage->gemini_consent_at, 404);
        $generation = RoomPreviewGeneration::where('room_image_id', $roomImage->id)
            ->where('furniture_product_id', $product)->firstOrFail();
        $path = data_get($roomImage->analysis, 'generated_previews.'.$product.'.path');

        return response()->json([
            'status' => $generation->status,
            'message' => $generation->message,
            'preview_url' => $generation->status === 'completed' && $path
                ? route('room-images.preview', [$roomImage, $product])
                : null,
        ]);
    }
}
