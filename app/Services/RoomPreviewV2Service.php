<?php

namespace App\Services;

use App\Models\FurnitureProduct;
use App\Models\RoomImage;
use App\Models\RoomPreviewAnalysis;
use App\Models\RoomPreviewGeneration;
use App\Services\PrivacyAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class RoomPreviewV2Service
{
    private int $providerCallStart = 0;
    private int $previousProviderCalls = 0;
    public function __construct(
        private GeminiService $gemini,
        private RoomPhotoService $photos,
        private FurniturePlacementService $placement,
        private RoomPreviewRasterService $raster,
        private AiTelemetry $telemetry,
        private PrivacyAudit $audit,
    ) {}

    public function process(int $generationId): void
    {
        $started = hrtime(true);
        $this->providerCallStart = $this->gemini->providerCallCount();
        $generation = RoomPreviewGeneration::find($generationId);
        if (! $generation) return;
        $room = RoomImage::find($generation->room_image_id);
        $product = FurnitureProduct::find($generation->furniture_product_id);
        if (! $room || ! $product || $product->status !== 'active') {
            $this->finish($generation, 'failed', 'catalog_item_unavailable', 'The selected catalog item is inactive or unavailable.');
            return;
        }
        if (! $this->photos->exists($room->image_path)) {
            $this->finish($generation, 'failed', 'room_photo_missing', 'The room photo is no longer available.');
            return;
        }

        $generation->update(['status' => 'processing', 'attempts' => (int) $generation->attempts + 1, 'failure_code' => null, 'message' => null]);
        $this->previousProviderCalls = (int) data_get($generation->metrics_json, 'provider_calls', 0);
        $this->gemini->limitProviderRequests(max(0, (int) config('roompreview.max_provider_calls', 8) - $this->previousProviderCalls));
        $providerCalls = 0;
        $tempPath = null;

        try {
            $source = $this->photos->get($room->image_path);
            if (strlen($source) > (int) config('roompreview.max_upload_bytes')) throw new \RuntimeException('image_too_large');
            $normalized = $this->raster->normalize($source);
            $temporaryExtension = match ($normalized['mime']) { 'image/jpeg', 'image/jpg' => 'jpg', 'image/webp' => 'webp', default => 'png' };
            $tempPath = 'rooms/'.$room->user_id.'/tmp/v2-'.Str::uuid().'.'.$temporaryExtension;
            $this->photos->storeGenerated($tempPath, $normalized['bytes']);

            $analysis = $room->analysis ?? [];
            $analysisCache = null;
            $room->refresh();
            if ($room->gemini_consent_at) {
                $hash = hash('sha256', $normalized['bytes']);
                $version = (string) config('roompreview.pipeline_version', 'v2');
                $analysisCache = RoomPreviewAnalysis::firstOrCreate(
                    ['image_sha256' => $hash, 'pipeline_version' => $version],
                    ['status' => 'queued', 'attempts' => 0],
                );
                if ($analysisCache->status === 'completed' && is_array($analysisCache->analysis)) {
                    $analysis = $analysisCache->analysis;
                } else {
                    $analysisCache->update(['status' => 'processing', 'attempts' => $analysisCache->attempts + 1]);
                    $this->audit->record('room_photo_sent_to_gemini', 'room_image', $room->id, ['purpose' => 'room_preview_v2_analysis'], $room->user_id);
                    // Shared geometry must not depend on one customer's measurements or selected item.
                    try {
                        $analysis = $this->gemini->analyzeRoom($tempPath, null, null, 'all catalog furniture', true);
                    } catch (Throwable $exception) {
                        Log::warning('Preview geometry unavailable; trying photo-based generation', ['exception' => get_class($exception)]);
                        $analysis = $this->gemini->roomFallback(null, null, 'all catalog furniture');
                    }
                    $wasAnalyzedByGemini = (bool) ($analysis['gemini_used'] ?? false);
                    if ($wasAnalyzedByGemini && $this->hasConsent($room->id)) {
                        $providerCalls += 2;
                        $analysisCache->update(['status' => 'completed', 'analysis' => $analysis, 'analyzed_at' => now(), 'failure_code' => null]);
                    } else {
                        if ($wasAnalyzedByGemini) {
                            $analysis = $this->gemini->roomFallback($room->room_width_m ? (float) $room->room_width_m : null, $room->room_depth_m ? (float) $room->room_depth_m : null, 'all catalog furniture');
                        }
                        $analysisCache->update(['status' => 'fallback', 'analysis' => null, 'failure_code' => $wasAnalyzedByGemini ? 'consent_revoked' : 'analysis_unavailable']);
                    }
                }
            }

            // Keep catalog dimensions authoritative; AI geometry is a placement aid only.
            $roomWidth = null;
            $roomDepth = null;
            $placement = $this->placement->forProduct($analysis, $product, $roomWidth, $roomDepth);
            $placement = $this->calibratePerspectiveScale($placement, $analysis, $product, $normalized['width']);
            $confidence = max(0.0, min(1.0, ((float) ($placement['score'] ?? 0)) / 100));
            $label = $confidence >= 0.85 ? 'high' : ($confidence >= 0.65 ? 'medium' : 'rough_estimate');
            $quality = $normalized['quality'];

            if (is_array($quality) && ! ($quality['usable'] ?? false)) {
                $this->savePlacementFallback($generation, $placement, $confidence, $label, 'photo_quality_low', $started, $providerCalls);
                return;
            }

            $artifact = null;
            $verifiedByAi = false;
            $aiFailureCode = null;
            $verification = null;
            $guard = null;
            if ($room->gemini_consent_at && $this->gemini->isConfigured()) {
                for ($attempt = 0; $attempt < min(3, (int) config('roompreview.max_generation_attempts', 3)); $attempt++) {
                    if (! $this->hasConsent($room->id)) break;
                    $this->audit->record('room_photo_sent_to_gemini', 'room_image', $room->id, ['purpose' => 'room_preview_v2_generation'], $room->user_id);
                    try {
                        $generated = $this->gemini->furnishRoom($tempPath, $product, true, 1, 0);
                    } catch (AiRequestBudgetExceeded | \Illuminate\Http\Client\ConnectionException | \Illuminate\Http\Client\RequestException $exception) {
                        $httpStatus = $exception instanceof \Illuminate\Http\Client\RequestException ? $exception->response->status() : null;
                        $aiFailureCode = $exception instanceof AiRequestBudgetExceeded || $httpStatus === 429
                            ? 'ai_request_limit_reached'
                            : ($httpStatus === 403 ? 'ai_access_denied' : 'ai_generation_unavailable');
                        Log::warning('AI preview generation unavailable; using local estimate', [
                            'generation_id' => $generation->id,
                            'exception' => get_class($exception),
                            'http_status' => $exception instanceof \Illuminate\Http\Client\RequestException ? $exception->response->status() : null,
                        ]);
                        break;
                    }
                    $providerCalls += 1;
                    if (! is_string($generated['bytes'] ?? null) || $generated['bytes'] === '') continue;
                    if (! $this->hasConsent($room->id)) continue;
                    $this->audit->record('room_photo_sent_to_gemini', 'room_image', $room->id, ['purpose' => 'room_preview_v2_verification'], $room->user_id);
                    try {
                        $verification = $this->gemini->verifyGeneratedRoom($tempPath, $generated['bytes'], $product, 1);
                    } catch (AiRequestBudgetExceeded | \Illuminate\Http\Client\ConnectionException | \Illuminate\Http\Client\RequestException $exception) {
                        $httpStatus = $exception instanceof \Illuminate\Http\Client\RequestException ? $exception->response->status() : null;
                        $aiFailureCode = $exception instanceof AiRequestBudgetExceeded || $httpStatus === 429
                            ? 'ai_request_limit_reached'
                            : ($httpStatus === 403 ? 'ai_access_denied' : 'ai_verification_unavailable');
                        Log::warning('AI preview verification unavailable; using local estimate', [
                            'generation_id' => $generation->id,
                            'exception' => get_class($exception),
                            'http_status' => $exception instanceof \Illuminate\Http\Client\RequestException ? $exception->response->status() : null,
                        ]);
                        break;
                    }
                    $providerCalls += 1;
                    $bounds = $this->expectedBounds($placement, $product, $normalized['width'], $normalized['height']);
                    $guard = $this->raster->pixelGuard($normalized['bytes'], $generated['bytes'], $bounds);
                    if (($verification['passed'] ?? false) && ($guard['supported'] ?? false) && ($guard['passed'] ?? false)) {
                        $artifact = ['bytes' => $generated['bytes'], 'mime' => $generated['mime_type'] ?? 'image/png'];
                        $verifiedByAi = true;
                        break;
                    }
                    Log::info('Room preview rejected by server quality gates', [
                        'generation_id' => $generation->id,
                        'attempt' => $attempt + 1,
                        'verification_passed' => $verification['passed'] ?? false,
                        'pixel_guard' => $guard,
                    ]);
                }
            }

            if (! $artifact) {
                $artifact = $this->raster->composite($normalized['bytes'], $product, $placement);
                // Rejected AI checks do not describe the saved local composite.
                $verification = null;
                $guard = null;
            }
            if (! $artifact) {
                $this->savePlacementFallback($generation, $placement, $confidence, $label, $this->raster->available() ? 'preview_composite_unavailable' : 'gd_extension_missing', $started, $providerCalls);
                return;
            }

            $artifactExtension = match ($artifact['mime'] ?? 'image/png') { 'image/jpeg', 'image/jpg' => 'jpg', 'image/webp' => 'webp', default => 'png' };
            $path = 'rooms/'.$room->user_id.'/previews/v2-'.$generation->id.'-'.$generation->cache_key.'.'.$artifactExtension;
            $placementData = [
                'pipeline' => 'v2',
                'placement' => $placement,
                'confidence' => $confidence,
                'confidence_label' => $label,
                'disclaimer' => 'This preview is a visual planning estimate only. It does not confirm exact dimensions, fit, or installation clearance. Measure the room and product before purchase.',
                'artifact_path' => $path,
                'verification' => $verification,
                'pixel_guard' => $guard,
                'image_quality' => $quality,
            ];
            DB::transaction(function () use ($room, $generation, $path, $artifact, $placementData, $verifiedByAi, $aiFailureCode, $confidence, $label, $started, $providerCalls, $quality, $verification, $guard): void {
                $currentRoom = RoomImage::whereKey($room->id)->lockForUpdate()->firstOrFail();
                if ($verifiedByAi && ! $currentRoom->gemini_consent_at) throw new \RuntimeException('consent_revoked');
                $this->photos->storeGenerated($path, $artifact['bytes']);
                $fallbackMessage = match ($aiFailureCode) {
                    'ai_access_denied' => 'Gemini access was denied. A local 2D planning preview is shown; ask the system administrator to check the API key and project permissions before retrying.',
                    'ai_request_limit_reached' => 'Gemini has reached its request limit. A local 2D planning preview is shown; wait a few minutes before retrying.',
                    default => 'A local 2D planning preview is ready; verify real measurements.',
                };
                $this->finish($generation, $verifiedByAi ? 'completed' : 'fallback', $aiFailureCode,
                    $verifiedByAi ? 'Verified room preview is ready.' : $fallbackMessage,
                    $placementData, $confidence, $label, $this->metrics($started, $providerCalls, $quality, $verification, $guard));
            });
        } catch (Throwable $exception) {
            if ($exception->getMessage() === 'consent_revoked') {
                $this->finish($generation, 'cancelled', 'consent_revoked', 'AI processing was disabled before the preview could be saved.');
                return;
            }
            $code = preg_match('/^[a-z0-9_]{1,100}$/', $exception->getMessage()) ? $exception->getMessage() : 'preview_pipeline_error';
            Log::warning('Room preview v2 pipeline failed', ['generation_id' => $generation->id, 'failure_code' => $code, 'exception' => $exception::class]);
            $this->finish($generation, 'failed', $code, 'The room preview could not be completed. Please check the photo and try again.', metrics: $this->metrics($started, $providerCalls, null, null, null));
            throw $exception;
        } finally {
            $this->gemini->clearProviderRequestLimit();
            if ($tempPath) $this->photos->deleteGenerated($tempPath);
        }
    }

    /** Fit catalog width to the image perspective using reliable known-size clues: s(y)=k*(y-horizon_y). */
    private function hasConsent(int $roomImageId): bool
    {
        return RoomImage::whereKey($roomImageId)->whereNotNull('gemini_consent_at')->exists();
    }

    private function calibratePerspectiveScale(array $placement, array $analysis, FurnitureProduct $product, int $imageWidth): array
    {
        if (str_contains(strtolower($product->recommendationType()), 'door') || ! $product->width_cm || $imageWidth < 1) return $placement;
        $geometry = $analysis['vision_geometry'] ?? [];
        $horizon = is_numeric($geometry['horizon_y'] ?? null) ? (float) $geometry['horizon_y'] : null;
        if ($horizon === null || $horizon < 0 || $horizon >= 0.9) return $placement;
        $baseY = max(0.0, min(1.0, (float) ($placement['y_percent'] ?? 86) / 100));
        if ($baseY <= $horizon) return $placement;

        $measurements = [];
        foreach (array_slice(is_array($geometry['scale_clues'] ?? null) ? $geometry['scale_clues'] : [], 0, 12) as $clue) {
            $pixelHeight = (float) ($clue['pixel_height'] ?? 0);
            $realCm = (float) ($clue['typical_real_cm'] ?? 0);
            $clueBase = (float) ($clue['base_y'] ?? 0);
            $reliability = max(0.0, min(1.0, (float) ($clue['reliability'] ?? 0)));
            if ($pixelHeight <= 0 || $realCm <= 0 || $reliability < 0.55 || $clueBase <= $horizon || $clueBase > 1) continue;

            $k = $pixelHeight / ($realCm * ($clueBase - $horizon));
            $predictedPixels = (float) $product->width_cm * $k * ($baseY - $horizon);
            $percent = 100 * $predictedPixels / $imageWidth;
            $capacity = max(1.0, min(90.0, (float) ($placement['max_width_percent'] ?? 65)));
            if ($percent >= 1 && $percent <= $capacity) $measurements[] = [$percent, $reliability];
        }
        if ($measurements === []) return $placement;

        $weightedPixels = 0.0; $weight = 0.0;
        foreach ($measurements as [$percent, $reliability]) {
            $weightedPixels += $percent * $reliability;
            $weight += $reliability;
        }
        $capacity = max(1.0, min(90.0, (float) ($placement['max_width_percent'] ?? 65)));
        $placement['max_width_percent'] = round(max(1.0, min($capacity, $weightedPixels / max(0.01, $weight))), 1);
        $placement['scale_basis'] = 'reliability-weighted-perspective-clues';
        return $placement;
    }

    private function savePlacementFallback(RoomPreviewGeneration $generation, array $placement, float $confidence, string $label, string $failureCode, int $started, int $calls): void
    {
        $data = [
            'pipeline' => 'v2', 'placement' => $placement, 'confidence' => $confidence, 'confidence_label' => $label,
            'disclaimer' => 'This preview is a visual planning estimate only. It does not confirm exact dimensions, fit, or installation clearance. Measure the room and product before purchase.',
            'artifact_path' => null, 'image_quality' => null,
        ];
        $this->finish($generation, 'fallback', $failureCode, 'Only a placement estimate is available; image rendering is not available on this server.', $data, $confidence, $label, $this->metrics($started, $calls, null, null, null));
    }

    private function finish(RoomPreviewGeneration $generation, string $status, ?string $failureCode, ?string $message, ?array $data = null, ?float $confidence = null, ?string $label = null, ?array $metrics = null): void
    {
        $generation->update([
            'status' => $status, 'failure_code' => $failureCode, 'message' => $message,
            'placement_json' => $data, 'confidence' => $confidence, 'confidence_label' => $label,
            'metrics_json' => $metrics, 'completed_at' => now(),
        ]);
        $this->telemetry->record('image', 'room_preview_v2', $status === 'completed' ? 'success' : $status, fallbackUsed: $status === 'fallback', failureCode: $failureCode);
    }

    private function metrics(int $started, int $calls, ?array $quality, ?array $verification, ?array $guard): array
    {
        return [
            'elapsed_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'provider_calls' => $this->previousProviderCalls + max(0, $this->gemini->providerCallCount() - $this->providerCallStart),
            'gd_available' => $this->raster->available(),
            'quality_usable' => $quality['usable'] ?? null,
            'verification_passed' => $verification['passed'] ?? null,
            'pixel_guard_passed' => $guard['passed'] ?? null,
        ];
    }

    private function expectedBounds(array $placement, FurnitureProduct $product, int $imageWidth, int $imageHeight): array
    {
        $width = max(1, min(90, (float) ($placement['max_width_percent'] ?? 35))) / 100 * $imageWidth;
        $ratio = (float) $product->height_cm > 0 && (float) $product->width_cm > 0 ? (float) $product->height_cm / (float) $product->width_cm : 1.0;
        $height = min($imageHeight * 0.92, $width * max(0.35, min(3.2, $ratio)));
        $x = max(0, min($imageWidth, $imageWidth * ((float) ($placement['x_percent'] ?? 50) / 100)));
        $bottom = max(0, min($imageHeight, $imageHeight * ((float) ($placement['y_percent'] ?? 86) / 100)));
        return [(int) ($x - $width / 2), (int) ($bottom - $height), (int) ($x + $width / 2), (int) $bottom];
    }
}
