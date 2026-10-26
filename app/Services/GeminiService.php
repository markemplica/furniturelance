<?php

namespace App\Services;

use App\Models\FurnitureProduct;
use App\Models\Recommendation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GeminiService
{
    private int $providerRequests = 0;
    private ?int $requestCeiling = null;

    public function limitProviderRequests(int $remaining): void
    {
        $this->requestCeiling = $this->providerRequests + max(0, $remaining);
    }

    public function clearProviderRequestLimit(): void
    {
        $this->requestCeiling = null;
    }

    private function countProviderRequest(): void
    {
        if ($this->requestCeiling !== null && $this->providerRequests >= $this->requestCeiling) {
            throw new AiRequestBudgetExceeded;
        }
        $this->providerRequests++;
    }

    public function __construct(private RoomPhotoService $roomPhotos, private AiTelemetry $telemetry) {}

    public function providerCallCount(): int
    {
        return $this->providerRequests;
    }

    public function isConfigured(): bool
    {
        return filled(config('services.gemini.key'));
    }

    public function recommendationSummary(array $preference, array $products): array
    {
        $fallback = 'These suggestions balance your budget, preferred materials and finish, room fit, and signals from customers with similar interests.';
        $prompt = "You are FurniCraft for Lance Furniture Shop. In 2 concise sentences, explain this ranked furniture recommendation. Stay strictly within the supplied data. Do not mention products outside the list, payments, delivery tracking, inventory, AR, or 3D.\nPreferences: ".json_encode($preference)."\nRanked products: ".json_encode($products);

        return $this->generate($prompt, $fallback, 'recommendation_explanation', timeout: 8, retryAttempts: 0, modelLimit: 2);
    }

    public function localRecommendationSummary(array $preference): array
    {
        $type = trim((string) ($preference['furniture_type'] ?? 'furniture'));
        $summary = "These {$type} suggestions are ranked from the active catalog using your budget, preferred style, wood, and finish, along with available customer-interest signals. Confirm physical availability with Lance Furniture Shop before reserving.";

        return ['text' => $summary, 'used' => false, 'source' => 'local-fallback'];
    }
    public function chat(Recommendation $recommendation, string $question): array
    {
        $items = $recommendation->items->map(fn ($item) => [
            'name' => $item->product->name,
            'category' => $item->product->category,
            'wood' => $item->product->wood_type,
            'finish' => $item->product->varnish_finish,
            'style' => $item->product->style,
            'price' => $item->product->price,
            'dimensions_cm' => [$item->product->width_cm, $item->product->depth_cm, $item->product->height_cm],
            'reason' => $item->explanation,
        ])->values()->all();

        $furnitureType = $recommendation->furniture_type ?: 'Furniture';
        $catalog = FurnitureProduct::query()->where('status', 'active')->orderBy('name')->get()
            ->filter(fn (FurnitureProduct $product) => $product->recommendationType() === $furnitureType)
            ->map(fn (FurnitureProduct $product) => [
                'name' => $product->name,
                'category' => $product->category,
                'wood' => $product->wood_type,
                'finish' => $product->varnish_finish,
                'style' => $product->style,
                'price' => $product->price,
                'dimensions_cm' => [$product->width_cm, $product->depth_cm, $product->height_cm],
            ])->values()->all();

        if ($availability = $this->catalogAvailabilityAnswer($catalog, $furnitureType, $question)) {
            return ['text' => $availability, 'used' => false, 'source' => 'catalog-rule'];
        }

        $fallback = $this->fallbackChat($items, $catalog, $furnitureType, $question);
        $prompt = "You are the FurniCraft assistant for Lance Furniture Shop. Answer only from the supplied recommendation and active catalog facts. The active catalog is the complete list of products currently available in the system for the customer's selected furniture type. If the customer asks for or requests a product, material, finish, style, size, color, or budget option, clearly begin with 'Available in our current catalog:' or 'Not available in our current catalog:' based only on an exact or close match in ACTIVE CATALOG. When unavailable, suggest the closest active options. Explain that catalog listings do not guarantee physical availability; customers should confirm item availability and readiness directly with Lance Furniture Shop staff. Never claim confirmed stock, take payment, arrange delivery, or replace shop staff. Keep the answer natural and under 130 words.\nSelected furniture type: {$furnitureType}\nRecommended products: ".json_encode($items)."\nActive catalog: ".json_encode($catalog)."\nCustomer question: {$question}";

        return $this->generate($prompt, $fallback, 'chat_response');
    }

    public function roomFallback(?float $width, ?float $depth, ?string $furnitureType = null): array
    {
        return [
            'summary' => 'The room image is ready for an automatic 2D placement preview. Scale is estimated from visible room clues.',
            'layout' => 'Keep clear walking paths and place larger pieces against an open wall.',
            'lighting' => 'Use the uploaded image as the visual reference; lighting estimates are advisory.',
            'constraints' => 'Image-only measurements are estimates and depend on image quality and perspective.',
            'room_type' => 'Unspecified room',
            'estimated_width_m' => $width,
            'estimated_depth_m' => $depth,
            'dimension_confidence' => ($width && $depth) ? 'customer-provided' : 'unavailable',
            'analyzed_furniture_type' => $furnitureType,
            'placement_profile_version' => 2,
            'placement_x_percent' => 50,
            'placement_y_percent' => 86,
            'placement_width_percent' => 72,
            'placement_confidence' => 'low',
            'placement_reason' => 'Centered on the visible floor area as a conservative starting point.',
            'floor_polygon' => [],
            'wall_regions' => [],
            'openings' => [],
            'obstacles' => [],
            'candidate_zones' => [],
            'horizon_y_percent' => 38,
            'vanishing_point' => ['x_percent' => 50, 'y_percent' => 38],
            'scene_confidence' => 'low',
            'vision_geometry' => null,
            'gemini_used' => false,
        ];
    }

    public function analyzeRoom(string $storagePath, ?float $width, ?float $depth, ?string $furnitureType = null, bool $throwOnFailure = false): array
    {
        $fallback = $this->roomFallback($width, $depth, $furnitureType);

        if (! $this->isConfigured()) {
            $this->telemetry->record('room', 'room_analysis', 'fallback', fallbackUsed: true, failureCode: 'not_configured');
            return $fallback;
        }
        if (! $this->roomPhotos->exists($storagePath)) {
            $this->telemetry->record('room', 'room_analysis', 'failure', failureCode: 'room_photo_missing');
            return $fallback;
        }

        try {
            $bytes = $this->roomPhotos->get($storagePath);
            $mime = $this->roomPhotos->mimeType($storagePath);
            $text = 'Analyze this uploaded room photo for realistic 2D furniture placement. The customer wants this furniture type: '.($furnitureType ?: 'unspecified furniture').'. Identify the visible floor polygon, usable wall regions, door/window openings, existing furniture or obstacles, horizon, vanishing point, and up to 6 valid placement zones. All polygon points and coordinates must use percentages from 0 to 100 in the original image coordinate system. Each candidate zone needs a surface (floor, wall-floor, or opening), floor-contact anchor_x_percent and anchor_y_percent, max_width_percent, rotation_deg, perspective_deg, flip, suitable_types, clearance_score, occlusion_score, confidence, and a concise reason. For every door/opening candidate, quad_percent is required and must contain exactly four image-coordinate points ordered top-left, top-right, bottom-right, bottom-left, following the visible frame perspective even when it is tilted or trapezoidal. Never place furniture across a doorway, window, obstacle, or primary walkway. Door products require a true doorway opening. Cabinets, wardrobes, and beds prefer wall-floor zones. Living-room furniture includes sala sets, sofas, couches, and sectionals; place these in the main living area on a clear floor or wall-floor zone near the usable wall or focal point, orient seating toward the room center or focal point, and preserve walkways. Dining sets belong in a clear dining floor zone with chair clearance. Tables and desks need usable floor space; chairs and benches should go beside their matching table or seating zone when visible, otherwise in clear floor space. Return suitable_types using broad family names as well as catalog names so matching products can share a valid zone. Also return the single best legacy placement_x_percent, placement_y_percent, and placement_width_percent. Width and depth must be 1 to 50 meters or null. Coordinates: x 5-95, y 30-96, displayed width 1-90, rotation -18 to 18, perspective -24 to 24, and scores 0-100. Use supplied room measurements when present; otherwise make conservative visual estimates. State that results are planning estimates, not construction measurements. Known room width: '.($width ?: 'unknown').' m; depth: '.($depth ?: 'unknown').' m.';
            $text .= ' Return vision_geometry with normalized 0-1 coordinates for image_quality, floor_polygon, walls, openings, horizon_y, blocked_zones, existing_furniture, scale_clues, structured lighting, and confidence. For scale clues use typical reference sizes: door 210 cm tall (80-90 cm wide), ceiling 250 cm, outlet about 30 cm above floor, floor tile 30-60 cm, chair seat about 45 cm high. Give each clue a realistic reliability score and base_y at its floor contact. Describe visible facts only; never invent a door, window, or floor; use null and low confidence when unsure.';
            $text .= ' Door placement safety: Never classify a window, skylight, transom, or glass panel as a doorway. A real door opening must be a full-height passage reaching the finished floor. For each true doorway, return two separate quadrilaterals: quad_percent traces the visible opening, and door_fit_quad_percent is the exact destination for the door overlay. Put door_fit_quad_percent on the room-facing installation plane at the actual entrance; trace both near jambs from the header to the floor threshold and span the full usable width. For a recessed closet, use the entrance jambs and trim closest to the camera, not the smaller opening or shelves on the back wall. Never reuse coordinates from a window. Only mark a zone suitable for a door when the full room-facing frame is visible and door_fit_quad_percent has four corners. Otherwise omit the fit quadrilateral and mark that zone unsuitable. Check that the overlay reaches from the doorway header almost to the floor and fills the entry width. Keep windows labeled as windows.';
            $pointSchema = ['type' => 'array', 'minItems' => 2, 'maxItems' => 2, 'items' => ['type' => 'number']];
            $polygonSchema = ['type' => 'array', 'items' => $pointSchema];
            $regionSchema = ['type' => 'object', 'properties' => ['label' => ['type' => 'string'], 'polygon' => $polygonSchema, 'door_fit_quad_percent' => ['type' => 'array', 'minItems' => 4, 'maxItems' => 4, 'items' => $pointSchema]], 'required' => ['label', 'polygon']];
            $zoneSchema = ['type' => 'object', 'properties' => [
                'surface' => ['type' => 'string'], 'anchor_x_percent' => ['type' => 'number'], 'anchor_y_percent' => ['type' => 'number'], 'max_width_percent' => ['type' => 'number'],
                'rotation_deg' => ['type' => 'number'], 'perspective_deg' => ['type' => 'number'], 'flip' => ['type' => 'boolean'], 'suitable_types' => ['type' => 'array', 'items' => ['type' => 'string']],
                'quad_percent' => ['type' => 'array', 'minItems' => 4, 'maxItems' => 4, 'items' => $pointSchema], 'door_fit_quad_percent' => ['type' => 'array', 'minItems' => 4, 'maxItems' => 4, 'items' => $pointSchema],
                'clearance_score' => ['type' => 'number'], 'occlusion_score' => ['type' => 'number'], 'confidence' => ['type' => 'string'], 'reason' => ['type' => 'string'],
            ], 'required' => ['surface', 'anchor_x_percent', 'anchor_y_percent', 'max_width_percent', 'suitable_types', 'clearance_score', 'occlusion_score', 'confidence', 'reason']];
            $normalizedPointSchema = ['type' => 'array', 'minItems' => 2, 'maxItems' => 2, 'items' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1]];
            $normalizedPolygonSchema = ['type' => 'array', 'minItems' => 3, 'maxItems' => 24, 'items' => $normalizedPointSchema];
            $visionGeometrySchema = ['type' => 'object', 'properties' => [
                'image_quality' => ['type' => 'object', 'properties' => ['usable' => ['type' => 'boolean'], 'issues' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['usable', 'issues']],
                'floor_polygon' => ['type' => 'array', 'maxItems' => 24, 'items' => $normalizedPointSchema],
                'walls' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['polygon' => $normalizedPolygonSchema, 'is_back_wall' => ['type' => 'boolean']], 'required' => ['polygon', 'is_back_wall']]],
                'openings' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'bbox' => ['type' => 'array', 'minItems' => 4, 'maxItems' => 4, 'items' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1]], 'base_y' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1], 'is_open' => ['type' => 'boolean']], 'required' => ['type', 'bbox', 'is_open']]],
                'horizon_y' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1],
                'blocked_zones' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['polygon' => $normalizedPolygonSchema, 'reason' => ['type' => 'string']], 'required' => ['polygon', 'reason']]],
                'existing_furniture' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string'], 'bbox' => ['type' => 'array', 'minItems' => 4, 'maxItems' => 4, 'items' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1]], 'estimated_real_width_cm' => ['type' => ['number', 'null']]], 'required' => ['label', 'bbox', 'estimated_real_width_cm']]],
                'scale_clues' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['object' => ['type' => 'string'], 'pixel_height' => ['type' => 'number'], 'typical_real_cm' => ['type' => 'number'], 'base_y' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1], 'reliability' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1]], 'required' => ['object', 'pixel_height', 'typical_real_cm', 'base_y', 'reliability']]],
                'lighting' => ['type' => 'object', 'properties' => ['brightness' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1], 'warmth' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1], 'light_from' => ['type' => 'string', 'enum' => ['left', 'right', 'front', 'top', 'unknown']]], 'required' => ['brightness', 'warmth', 'light_from']],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            ], 'required' => ['image_quality', 'floor_polygon', 'walls', 'openings', 'horizon_y', 'blocked_zones', 'existing_furniture', 'scale_clues', 'lighting', 'confidence']];
            $schema = [
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string'], 'layout' => ['type' => 'string'], 'lighting' => ['type' => 'string'], 'constraints' => ['type' => 'string'], 'room_type' => ['type' => 'string'],
                    'estimated_width_m' => ['type' => ['number', 'null']], 'estimated_depth_m' => ['type' => ['number', 'null']], 'dimension_confidence' => ['type' => 'string'],
                    'placement_x_percent' => ['type' => 'number'], 'placement_y_percent' => ['type' => 'number'], 'placement_width_percent' => ['type' => 'number'], 'placement_confidence' => ['type' => 'string'], 'placement_reason' => ['type' => 'string'],
                    'floor_polygon' => $polygonSchema,
                    'wall_regions' => ['type' => 'array', 'items' => $regionSchema],
                    'openings' => ['type' => 'array', 'items' => $regionSchema],
                    'obstacles' => ['type' => 'array', 'items' => $regionSchema],
                    'candidate_zones' => ['type' => 'array', 'maxItems' => 6, 'items' => $zoneSchema],
                    'horizon_y_percent' => ['type' => 'number'],
                    'vanishing_point' => ['type' => 'object', 'properties' => ['x_percent' => ['type' => 'number'], 'y_percent' => ['type' => 'number']], 'required' => ['x_percent', 'y_percent']],
                    'scene_confidence' => ['type' => 'string'], 'vision_geometry' => $visionGeometrySchema,
                ],
                'required' => ['summary', 'layout', 'constraints', 'room_type', 'placement_x_percent', 'placement_y_percent', 'placement_width_percent', 'placement_confidence', 'placement_reason', 'candidate_zones', 'scene_confidence', 'vision_geometry'],
            ];
            $parts = [
                ['text' => $text],
                ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
            ];
            $decoded = null;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $response = $this->request($parts, true, $schema, 'room_analysis', 25, 0, 2);
                $candidate = json_decode($response, true);
                $geometry = $this->sanitizeVisionGeometry($candidate['vision_geometry'] ?? null);
                if (is_array($candidate) && is_array($geometry)) {
                    $decoded = $candidate;
                    $decoded['vision_geometry'] = $geometry;
                    break;
                }
            }
            if (! is_array($decoded)) {
                $this->telemetry->record('room', 'room_analysis', 'fallback', fallbackUsed: true, failureCode: 'invalid_geometry');
                return $fallback;
            }
            if (is_array($decoded)) {
                foreach (['estimated_width_m', 'estimated_depth_m'] as $key) {
                    $value = isset($decoded[$key]) && is_numeric($decoded[$key]) ? (float) $decoded[$key] : null;
                    $decoded[$key] = $value && $value >= 1 && $value <= 50 ? round($value, 1) : null;
                }
                $decoded['estimated_width_m'] = $width ?: $decoded['estimated_width_m'];
                $decoded['estimated_depth_m'] = $depth ?: $decoded['estimated_depth_m'];
                $decoded['dimension_confidence'] = ($width && $depth)
                    ? 'customer-provided'
                    : ($decoded['dimension_confidence'] ?? 'low');
                foreach ([
                    'placement_x_percent' => [5, 95],
                    'placement_y_percent' => [30, 96],
                    'placement_width_percent' => [15, 90],
                ] as $key => [$minimum, $maximum]) {
                    $value = isset($decoded[$key]) && is_numeric($decoded[$key]) ? (float) $decoded[$key] : $fallback[$key];
                    $decoded[$key] = round(min($maximum, max($minimum, $value)), 1);
                }
                $decoded['placement_confidence'] = in_array($decoded['placement_confidence'] ?? null, ['low', 'medium', 'high'], true)
                    ? $decoded['placement_confidence']
                    : 'low';
                foreach (['summary', 'layout', 'lighting', 'constraints', 'room_type', 'placement_reason'] as $key) {
                    $decoded[$key] = isset($decoded[$key]) && is_string($decoded[$key])
                        ? trim($decoded[$key])
                        : $fallback[$key];
                }
                $decoded['floor_polygon'] = $this->sanitizePolygon($decoded['floor_polygon'] ?? []);
                foreach (['wall_regions', 'openings', 'obstacles'] as $key) {
                    $decoded[$key] = $this->sanitizeRegions($decoded[$key] ?? []);
                }
                $decoded['candidate_zones'] = $this->sanitizeCandidateZones($decoded['candidate_zones'] ?? []);
                $decoded['horizon_y_percent'] = $this->clampPercent($decoded['horizon_y_percent'] ?? 38, 5, 85);
                $decoded['vanishing_point'] = [
                    'x_percent' => $this->clampPercent(data_get($decoded, 'vanishing_point.x_percent', 50), 0, 100),
                    'y_percent' => $this->clampPercent(data_get($decoded, 'vanishing_point.y_percent', 38), 0, 100),
                ];
                $decoded['scene_confidence'] = in_array($decoded['scene_confidence'] ?? null, ['low', 'medium', 'high'], true) ? $decoded['scene_confidence'] : 'low';
                $decoded['vision_geometry'] = $this->sanitizeVisionGeometry($decoded['vision_geometry'] ?? null);
                $decoded['analyzed_furniture_type'] = $furnitureType;
                $decoded['placement_profile_version'] = 2;

                return array_merge($fallback, array_intersect_key($decoded, $fallback), ['gemini_used' => true]);
            }
            $this->telemetry->record('room', 'room_analysis', 'fallback', fallbackUsed: true, failureCode: 'invalid_response');
        } catch (\Throwable $e) {
            Log::warning('Gemini room analysis failed', $this->errorContext($e));
            if ($throwOnFailure) {
                throw $e;
            }
        }

        return $fallback;
    }

    public function verifyRoomPlacement(string $roomStoragePath, string $previewBase64, array $placement): array
    {
        $fallback = ['verdict' => 'good', 'issues' => [], 'corrections' => ['dx' => 0, 'dy' => 0, 'scale_factor' => 1, 'flip' => null], 'realism_score' => 0.35, 'valid' => false];
        if (! $this->isConfigured() || ! $this->roomPhotos->exists($roomStoragePath)) return $fallback;
        $previewBytes = base64_decode($previewBase64, true);
        if (! is_string($previewBytes) || strlen($previewBytes) > 2_000_000) return $fallback;
        $previewMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($previewBytes) ?: '';
        if (! in_array($previewMime, ['image/jpeg', 'image/png', 'image/webp'], true)) return $fallback;

        $schema = [
            'type' => 'object',
            'properties' => [
                'verdict' => ['type' => 'string', 'enum' => ['good', 'needs_adjustment']],
                'issues' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['too_large', 'too_small', 'floating', 'blocking_door', 'wrong_wall', 'overlapping_object', 'out_of_floor', 'unnatural_orientation']]],
                'corrections' => [
                    'type' => 'object',
                    'properties' => [
                        'dx' => ['type' => 'number'],
                        'dy' => ['type' => 'number'],
                        'scale_factor' => ['type' => 'number'],
                        'flip' => ['type' => ['boolean', 'null']],
                    ],
                    'required' => ['dx', 'dy', 'scale_factor', 'flip'],
                ],
                'realism_score' => ['type' => 'number'],
            ],
            'required' => ['verdict', 'issues', 'corrections', 'realism_score'],
        ];
        $prompt = 'Compare image 1 (original customer room) with image 2 (FurniCraft composite). Review only whether the selected furniture fits the visible scene. Do not infer hidden geometry or claim exact measurements. Placement metadata: '.json_encode($placement).'. Return JSON only. If uncertain, verdict needs_adjustment and lower realism_score. Corrections must be small, normalized image offsets and a scale multiplier; never recommend a correction that moves furniture through a door, wall, or outside the floor.';
        try {
            $response = $this->request([
                ['text' => $prompt],
                ['inline_data' => ['mime_type' => $this->roomPhotos->mimeType($roomStoragePath), 'data' => base64_encode($this->roomPhotos->get($roomStoragePath))]],
                ['inline_data' => ['mime_type' => $previewMime, 'data' => base64_encode($previewBytes)]],
            ], true, $schema, 'room_preview_verification', 25, 1);
            $decoded = json_decode($response, true);
            if (! is_array($decoded)) return $fallback;
            $verdict = in_array($decoded['verdict'] ?? null, ['good', 'needs_adjustment'], true) ? $decoded['verdict'] : null;
            $allowedIssues = ['too_large', 'too_small', 'floating', 'blocking_door', 'wrong_wall', 'overlapping_object', 'out_of_floor', 'unnatural_orientation'];
            $issues = collect($decoded['issues'] ?? [])->filter(fn ($issue) => is_string($issue) && in_array($issue, $allowedIssues, true))->values()->all();
            $corrections = is_array($decoded['corrections'] ?? null) ? $decoded['corrections'] : [];
            $flip = $corrections['flip'] ?? null;
            if ($flip !== null && ! is_bool($flip)) $flip = null;
            $score = is_numeric($decoded['realism_score'] ?? null) ? (float) $decoded['realism_score'] : null;
            if (! $verdict || $score === null) return $fallback;

            return [
                'verdict' => $verdict,
                'issues' => $issues,
                'corrections' => [
                    'dx' => is_numeric($corrections['dx'] ?? null) ? max(-0.1, min(0.1, (float) $corrections['dx'])) : 0,
                    'dy' => is_numeric($corrections['dy'] ?? null) ? max(-0.1, min(0.1, (float) $corrections['dy'])) : 0,
                    'scale_factor' => is_numeric($corrections['scale_factor'] ?? null) ? max(0.6, min(1.4, (float) $corrections['scale_factor'])) : 1,
                    'flip' => $flip,
                ],                'realism_score' => max(0, min(1, $score)),
                'valid' => true,
            ];
        } catch (\Throwable $exception) {
            $this->telemetry->record('image', 'room_preview_verification', 'fallback', fallbackUsed: true, failureCode: $this->telemetry->failureCode($exception));
            Log::warning('Gemini placement verification failed', $this->errorContext($exception));
            return $fallback;
        }
    }
    public function furnishRoom(string $roomStoragePath, FurnitureProduct $product, bool $throwOnFailure = false, int $modelLimit = 2, int $retryAttempts = 1): array
    {
        $fallback = ['bytes' => null, 'mime_type' => null, 'used' => false];

        if (! $this->isConfigured()) {
            $this->telemetry->record('image', 'furnished_preview', 'fallback', fallbackUsed: true, failureCode: 'not_configured');
            return $fallback;
        }
        if (! $this->roomPhotos->exists($roomStoragePath)) {
            $this->telemetry->record('image', 'furnished_preview', 'failure', failureCode: 'room_photo_missing');
            return $fallback;
        }

        $productImage = $this->productImage($product);
        if (! $productImage) {
            $this->telemetry->record('image', 'furnished_preview', 'fallback', fallbackUsed: true, failureCode: 'product_image_missing');
            return $fallback;
        }

        try {
            $roomBytes = $this->roomPhotos->get($roomStoragePath);
            $roomMime = $this->roomPhotos->mimeType($roomStoragePath);
            $prompt = 'Create one photorealistic furnished-room preview from the two supplied images. The first image is the customer room and must remain the same camera angle, crop, architecture, walls, windows, doors, floor, ceiling, lighting, and existing permanent fixtures. The second image is the exact selected Lance Furniture Shop product: '.$product->name.' ('.$product->category.'). Treat the room image as the only scale reference. Infer the furniture apparent size and perspective visually from the room; do not rely on numeric measurements. Place only that exact furniture item in a visually plausible, clear area of the room, add natural contact shadows and matching light, and preserve the room photo. The selected catalog image is the source of truth for product identity and design. Preserve its wood color, finish, structure, cushions, and number of pieces. Do not substitute another design. Do not add rugs, plants, paintings, lamps, decorations, other furniture, people, text, logos, borders, or labels. Return only the completed room image.';
            $models = collect([
                config('services.gemini.image_model', 'gemini-3.1-flash-image'),
                config('services.gemini.image_fallback_model', 'gemini-2.5-flash-image'),
            ])->filter()->unique()->take(max(1, min(2, $modelLimit)));
            $lastException = null;

            foreach ($models as $index => $model) {
                $started = hrtime(true);
                try {
                    $response = Http::withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                        ->timeout(120)
                        ->retry(max(0, min(1, $retryAttempts)), 500)
                        ->post("https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent", [
                            'contents' => [[
                                'role' => 'user',
                                'parts' => [
                                    ['text' => $prompt],
                                    ['inline_data' => ['mime_type' => $roomMime, 'data' => base64_encode($roomBytes)]],
                                    ['inline_data' => ['mime_type' => $productImage['mime_type'], 'data' => base64_encode($productImage['bytes'])]],
                                ],
                            ]],
                            'generationConfig' => ['responseModalities' => ['IMAGE']],
                        ]);
                    $httpStatus = $response->status();
                    $response->throw();
                    $response = $response->json();

                    $imagePart = collect(data_get($response, 'candidates.0.content.parts', []))
                        ->first(fn ($part) => filled(data_get($part, 'inlineData.data')) || filled(data_get($part, 'inline_data.data')));
                    $encoded = data_get($imagePart, 'inlineData.data') ?: data_get($imagePart, 'inline_data.data');
                    $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;

                    if (! $bytes) {
                        throw new \RuntimeException('Gemini returned no generated room image.');
                    }

                    $this->telemetry->record('image', 'furnished_preview', 'success', $model, $httpStatus, (int) ((hrtime(true) - $started) / 1_000_000), $index > 0);

                    return [
                        'bytes' => $bytes,
                        'mime_type' => data_get($imagePart, 'inlineData.mimeType') ?: data_get($imagePart, 'inline_data.mime_type') ?: 'image/png',
                        'used' => true,
                    ];
                } catch (\Throwable $e) {
                    $lastException = $e;
                    $this->telemetry->record('image', 'furnished_preview', 'failure', $model, $this->telemetry->statusCode($e), (int) ((hrtime(true) - $started) / 1_000_000), failureCode: $this->telemetry->failureCode($e));
                    Log::warning('Gemini image model attempt failed', array_merge(['model' => $model], $this->errorContext($e)));
                }
            }

            throw $lastException ?? new \RuntimeException('No Gemini image model is configured.');
        } catch (\Throwable $e) {
            if (! $throwOnFailure) {
                $this->telemetry->record('image', 'furnished_preview', 'fallback', fallbackUsed: true, failureCode: $this->telemetry->failureCode($e));
            }
            Log::warning('Gemini furnished-room generation failed', $this->errorContext($e));

            if ($throwOnFailure) throw $e;

            return $fallback;
        }
    }

    /**
     * Server-side quality gate for an AI-generated preview. The client never supplies
     * either image or product facts to this check.
     */
    public function verifyGeneratedRoom(string $roomStoragePath, string $previewBytes, FurnitureProduct $product, int $modelLimit = 2): array
    {
        $fallback = [
            'scores' => ['scale' => 0.0, 'placement' => 0.0, 'catalog_fidelity' => 0.0, 'room_preservation' => 0.0, 'lighting' => 0.0],
            'single_item_only' => false, 'catalog_item_only' => false, 'passed' => false,
        ];
        if (! $this->isConfigured() || ! $this->roomPhotos->exists($roomStoragePath) || strlen($previewBytes) > 8_000_000) return $fallback;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($previewBytes) ?: '';
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return $fallback;
        $productImage = $this->productImage($product);
        if (! $productImage) return $fallback;

        $score = ['type' => 'number', 'minimum' => 0, 'maximum' => 1];
        $schema = ['type' => 'object', 'properties' => [
            'scores' => ['type' => 'object', 'properties' => [
                'scale' => $score, 'placement' => $score, 'catalog_fidelity' => $score,
                'room_preservation' => $score, 'lighting' => $score,
            ], 'required' => ['scale', 'placement', 'catalog_fidelity', 'room_preservation', 'lighting']],
            'single_item_only' => ['type' => 'boolean'], 'catalog_item_only' => ['type' => 'boolean'],
        ], 'required' => ['scores', 'single_item_only', 'catalog_item_only']];
        $prompt = 'Images: original room, exact catalog product, generated result. Score 0..1 for realistic scale, placement fit, identity match to catalog product, preservation of the original room and camera, and lighting/shadow match. Confirm exactly one furniture item was added and it matches the catalog product. Return JSON only.';
        try {
            $response = $this->request([
                ['text' => $prompt],
                ['inline_data' => ['mime_type' => $this->roomPhotos->mimeType($roomStoragePath), 'data' => base64_encode($this->roomPhotos->get($roomStoragePath))]],
                ['inline_data' => ['mime_type' => $productImage['mime_type'], 'data' => base64_encode($productImage['bytes'])]],
                ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($previewBytes)]],
            ], true, $schema, 'room_preview_verification', 25, 0, max(1, min(2, $modelLimit)));
            $decoded = json_decode($response, true);
            if (! is_array($decoded) || ! is_array($decoded['scores'] ?? null)) return $fallback;
            $scores = [];
            foreach (array_keys($fallback['scores']) as $name) {
                $value = $decoded['scores'][$name] ?? null;
                if (! is_numeric($value) || $value < 0 || $value > 1) return $fallback;
                $scores[$name] = (float) $value;
            }
            $single = ($decoded['single_item_only'] ?? false) === true;
            $catalog = ($decoded['catalog_item_only'] ?? false) === true;
            return ['scores' => $scores, 'single_item_only' => $single, 'catalog_item_only' => $catalog,
                'passed' => $single && $catalog && min($scores) >= (float) config('roompreview.verification_min_score', 0.75)];
        } catch (\Throwable $exception) {
            $this->telemetry->record('image', 'room_preview_verification', 'failure', failureCode: $this->telemetry->failureCode($exception));
            Log::warning('Room preview verification failed', $this->errorContext($exception));
            return $fallback;
        }
    }

    private function generate(string $prompt, string $fallback, string $operation, int $timeout = 30, int $retryAttempts = 2, int $modelLimit = 2): array
    {
        if (! $this->isConfigured()) {
            $this->telemetry->record('text', $operation, 'fallback', fallbackUsed: true, failureCode: 'not_configured');
            return ['text' => $fallback, 'used' => false, 'source' => 'local-fallback'];
        }

        try {
            return ['text' => $this->request([['text' => $prompt]], operation: $operation, timeout: $timeout, retryAttempts: $retryAttempts, modelLimit: $modelLimit), 'used' => true, 'source' => 'gemini'];
        } catch (\Throwable $e) {
            $this->telemetry->record('text', $operation, 'fallback', fallbackUsed: true, failureCode: $this->telemetry->failureCode($e));
            Log::warning('Gemini request failed', $this->errorContext($e));

            return ['text' => $fallback, 'used' => false, 'source' => 'local-fallback'];
        }
    }

    private function request(array $parts, bool $json = false, ?array $schema = null, string $operation = 'text_generation', int $timeout = 30, int $retryAttempts = 2, int $modelLimit = 2): string
    {
        $models = collect([
            config('services.gemini.model'),
            config('services.gemini.fallback_model'),
        ])->filter()->unique()->take(max(1, min(2, $modelLimit)));
        $lastException = null;

        foreach ($models as $index => $model) {
            $started = hrtime(true);
            try {
                $response = Http::timeout($timeout)->retry($retryAttempts, 250)
                    ->withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                    ->beforeSending(fn () => $this->countProviderRequest())->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                        'contents' => [['parts' => $parts]],
                        'generationConfig' => $json
                            ? array_filter([
                                'responseMimeType' => 'application/json',
                                'responseJsonSchema' => $schema,
                                'temperature' => 0,
                            ])
                            : ['temperature' => 0.25],
                    ]);
                // Some models reject complex structured-output schemas. Retry JSON mode
                // with the same shape in the prompt; callers still validate the result.
                if ($response->status() === 400 && $json && $schema) {
                    $jsonParts = $parts;
                    $jsonParts[] = ['text' => 'Return JSON only matching this schema: '.json_encode($schema)];
                    $response = Http::timeout($timeout)
                        ->withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                    ->beforeSending(fn () => $this->countProviderRequest())
                        ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                            'contents' => [['parts' => $jsonParts]],
                            'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0],
                        ]);
                }
                $httpStatus = $response->status();
                $response->throw();
                $response = $response->json();
                $text = trim(data_get($response, 'candidates.0.content.parts.0.text', ''));
                if ($text === '') throw new \RuntimeException('Gemini returned an empty response.');
                $this->telemetry->record('text', $operation, 'success', $model, $httpStatus, (int) ((hrtime(true) - $started) / 1_000_000), $index > 0);

                return $text;
            } catch (\Throwable $e) {
                $lastException = $e;
                $this->telemetry->record('text', $operation, 'failure', $model, $this->telemetry->statusCode($e), (int) ((hrtime(true) - $started) / 1_000_000), failureCode: $this->telemetry->failureCode($e));
                Log::warning('Gemini model attempt failed', array_merge(['model' => $model], $this->errorContext($e)));
            }
        }

        throw $lastException ?? new \RuntimeException('No Gemini model is configured.');
    }

    private function sanitizeVisionGeometry(mixed $geometry): ?array
    {
        if (! is_array($geometry)) return null;
        $point = static function (mixed $value): ?array {
            if (! is_array($value) || count($value) !== 2 || ! is_numeric($value[0] ?? null) || ! is_numeric($value[1] ?? null)) return null;
            $x = (float) $value[0]; $y = (float) $value[1];
            return $x >= 0 && $x <= 1 && $y >= 0 && $y <= 1 ? [$x, $y] : null;
        };
        $poly = static function (mixed $value) use ($point): array {
            if (! is_array($value) || count($value) < 3 || count($value) > 24) return [];
            $points = [];
            foreach ($value as $item) { $p = $point($item); if (! $p) return []; $points[] = $p; }
            return $points;
        };
        $simple = static function (array $polygon): bool {
            $n = count($polygon);
            $orient = static fn ($a,$b,$c) => ($b[0]-$a[0])*($c[1]-$a[1])-($b[1]-$a[1])*($c[0]-$a[0]);
            for ($i=0; $i<$n; $i++) {
                $a=$polygon[$i]; $b=$polygon[($i+1)%$n];
                for ($j=$i+1; $j<$n; $j++) {
                    if ($j===$i || ($j+1)%$n===$i || ($i+1)%$n===$j) continue;
                    $c=$polygon[$j]; $d=$polygon[($j+1)%$n];
                    if ($orient($a,$b,$c)*$orient($a,$b,$d)<0 && $orient($c,$d,$a)*$orient($c,$d,$b)<0) return false;
                }
            }
            return true;
        };
        $area = static function (array $polygon): float {
            $sum = 0.0; $count = count($polygon);
            for ($i = 0; $i < $count; $i++) { [$x1,$y1] = $polygon[$i]; [$x2,$y2] = $polygon[($i+1)%$count]; $sum += $x1*$y2-$x2*$y1; }
            return abs($sum/2);
        };
        foreach (['image_quality', 'floor_polygon', 'walls', 'openings', 'horizon_y', 'blocked_zones', 'existing_furniture', 'scale_clues', 'lighting', 'confidence'] as $requiredKey) {
            if (! array_key_exists($requiredKey, $geometry)) return null;
        }
        $quality = is_array($geometry['image_quality'] ?? null) ? $geometry['image_quality'] : [];
        if (! is_bool($quality['usable'] ?? null) || ! is_array($quality['issues'] ?? null)) return null;
        $issues = ['blurry','too_dark','too_close','no_floor_visible','extreme_wide_angle'];
        $floor = $poly($geometry['floor_polygon'] ?? []);
        if ($floor && (! $simple($floor) || $area($floor) < 0.05 || $area($floor) > 0.90 || count(array_filter($floor, fn ($point) => $point[1] >= 0.30)) / count($floor) < 0.70)) $floor = [];
        $horizon = is_numeric($geometry['horizon_y'] ?? null) ? max(0, min(1, (float) $geometry['horizon_y'])) : null;
        if ($floor && ($horizon === null || $horizon >= min(array_column($floor, 1)))) $floor = [];
        $walls = [];
        foreach (array_slice(is_array($geometry['walls'] ?? null) ? $geometry['walls'] : [], 0, 8) as $wall) {
            $shape = $poly($wall['polygon'] ?? []);
            if ($shape && $simple($shape)) $walls[] = ['polygon' => $shape, 'is_back_wall' => (bool) ($wall['is_back_wall'] ?? false)];
        }
        if ($floor && $walls) {
            // Raster sampling gives a simple, bounded estimate of wall-floor overlap.
            $inside = static function (float $x, float $y, array $shape): bool {
                $result = false;
                for ($i = 0, $j = count($shape) - 1; $i < count($shape); $j = $i++) {
                    [$xi, $yi] = $shape[$i]; [$xj, $yj] = $shape[$j];
                    if ((($yi > $y) !== ($yj > $y)) && $x < (($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-8)) + $xi) $result = ! $result;
                }
                return $result;
            };
            foreach ($walls as $wall) {
                $shape = $wall['polygon'];
                $left = min(array_column($shape, 0)); $right = max(array_column($shape, 0));
                $top = min(array_column($shape, 1)); $bottom = max(array_column($shape, 1));
                $samples = 0; $overlap = 0;
                for ($row = 0; $row < 40; $row++) for ($column = 0; $column < 40; $column++) {
                    $x = $left + ($column + 0.5) * ($right - $left) / 40;
                    $y = $top + ($row + 0.5) * ($bottom - $top) / 40;
                    if (! $inside($x, $y, $shape)) continue;
                    $samples++;
                    if ($inside($x, $y, $floor)) $overlap++;
                }
                if ($samples > 0 && ($overlap / 1600) * (($right - $left) * ($bottom - $top)) > 0.05) return null;
            }
        }
        $openings = [];
        foreach (array_slice(is_array($geometry['openings'] ?? null) ? $geometry['openings'] : [], 0, 16) as $opening) {
            $box = $opening['bbox'] ?? null; $type = strtolower((string) ($opening['type'] ?? ''));
            if (! is_array($box) || count($box) !== 4 || ! collect($box)->every(fn ($v) => is_numeric($v) && $v >= 0 && $v <= 1) || ! in_array($type, ['door','window','archway'], true)) continue;
            [$x1,$y1,$x2,$y2] = array_map('floatval', $box);
            if ($x2 <= $x1 || $y2 <= $y1) continue;
            if ($type === 'door' && (($y2-$y1)/max(0.001, $x2-$x1) < 1.8 || ($y2-$y1)/max(0.001, $x2-$x1) > 3.2)) continue;

            $openings[] = ['type'=>$type,'bbox'=>[$x1,$y1,$x2,$y2],'base_y'=>is_numeric($opening['base_y'] ?? null) ? max(0,min(1,(float)$opening['base_y'])) : null,'is_open'=>(bool)($opening['is_open'] ?? false)];
        }
        $blocked = [];
        foreach (array_slice(is_array($geometry['blocked_zones'] ?? null) ? $geometry['blocked_zones'] : [], 0, 24) as $zone) {
            $shape = $poly($zone['polygon'] ?? []);
            if ($shape && $simple($shape)) $blocked[] = ['polygon'=>$shape,'reason'=>in_array($zone['reason'] ?? '', ['door_swing','walkway','existing_furniture'], true) ? $zone['reason'] : 'existing_furniture'];
        }
        $existing = [];
        foreach (array_slice(is_array($geometry['existing_furniture'] ?? null) ? $geometry['existing_furniture'] : [], 0, 16) as $item) {
            $box = $item['bbox'] ?? null;
            if (is_array($box) && count($box) === 4 && collect($box)->every(fn ($v) => is_numeric($v) && $v >= 0 && $v <= 1)) $existing[] = ['label'=>substr((string)($item['label'] ?? 'furniture'),0,60),'bbox'=>array_map('floatval',$box),'estimated_real_width_cm'=>is_numeric($item['estimated_real_width_cm'] ?? null) ? max(1,min(1000,(float)$item['estimated_real_width_cm'])) : null];
        }
        $clues = [];
        foreach (array_slice(is_array($geometry['scale_clues'] ?? null) ? $geometry['scale_clues'] : [], 0, 12) as $clue) {
            if (! is_numeric($clue['pixel_height'] ?? null) || ! is_numeric($clue['typical_real_cm'] ?? null) || ! is_numeric($clue['base_y'] ?? null) || ! is_numeric($clue['reliability'] ?? null)) continue;
            $clues[] = ['object'=>substr((string)($clue['object'] ?? 'object'),0,40),'pixel_height'=>max(1,min(10000,(float)$clue['pixel_height'])),'typical_real_cm'=>max(1,min(1000,(float)$clue['typical_real_cm'])),'base_y'=>max(0,min(1,(float)$clue['base_y'])),'reliability'=>max(0,min(1,(float)$clue['reliability']))];
        }
        $light = is_array($geometry['lighting'] ?? null) ? $geometry['lighting'] : [];
        return [
            'image_quality'=>['usable'=>(bool)($quality['usable'] ?? false),'issues'=>collect($quality['issues'] ?? [])->filter(fn($v)=>is_string($v)&&in_array($v,$issues,true))->values()->all()],
            'floor_polygon'=>$floor,'walls'=>$walls,'openings'=>$openings,'horizon_y'=>$horizon,'blocked_zones'=>$blocked,'existing_furniture'=>$existing,'scale_clues'=>$clues,
            'lighting'=>['brightness'=>max(0,min(1,(float)($light['brightness'] ?? 0.5))),'warmth'=>max(0,min(1,(float)($light['warmth'] ?? 0.5))),'light_from'=>in_array($light['light_from'] ?? null,['left','right','front','top','unknown'],true)?$light['light_from']:'unknown'],
            'confidence'=>max(0,min(1,(float)($geometry['confidence'] ?? 0))),
        ];
    }
    private function sanitizePolygon(mixed $polygon): array
    {
        return collect(is_array($polygon) ? $polygon : [])->take(16)->map(function ($point) {
            if (! is_array($point) || count($point) < 2) {
                return null;
            }

            return [$this->clampPercent($point[0] ?? 0), $this->clampPercent($point[1] ?? 0)];
        })->filter()->values()->all();
    }

    private function sanitizeRegions(mixed $regions): array
    {
        return collect(is_array($regions) ? $regions : [])->take(12)->map(function ($region) {
            if (! is_array($region)) {
                return null;
            }

            return [
                'label' => substr(trim((string) ($region['label'] ?? 'region')), 0, 80),
                'polygon' => $this->sanitizePolygon($region['polygon'] ?? []),
                'door_fit_quad_percent' => count($fitQuad = $this->sanitizePolygon($region['door_fit_quad_percent'] ?? [])) === 4 ? $fitQuad : null,
            ];
        })->filter(fn ($region) => $region && count($region['polygon']) >= 3)->values()->all();
    }

    private function sanitizeCandidateZones(mixed $zones): array
    {
        return collect(is_array($zones) ? $zones : [])->take(6)->map(function ($zone) {
            if (! is_array($zone)) {
                return null;
            }

            $surface = strtolower((string) ($zone['surface'] ?? 'floor'));

            return [
                'surface' => in_array($surface, ['floor', 'wall-floor', 'opening'], true) ? $surface : 'floor',
                'anchor_x_percent' => $this->clampPercent($zone['anchor_x_percent'] ?? 50, 5, 95),
                'anchor_y_percent' => $this->clampPercent($zone['anchor_y_percent'] ?? 86, 30, 96),
                'max_width_percent' => $this->clampPercent($zone['max_width_percent'] ?? 60, 1, 90),
                'rotation_deg' => $this->clampPercent($zone['rotation_deg'] ?? 0, -18, 18),
                'perspective_deg' => $this->clampPercent($zone['perspective_deg'] ?? 0, -24, 24),
                'flip' => filter_var($zone['flip'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'quad_percent' => count($quad = $this->sanitizePolygon($zone['quad_percent'] ?? [])) === 4 ? $quad : null,
                'door_fit_quad_percent' => count($fitQuad = $this->sanitizePolygon($zone['door_fit_quad_percent'] ?? [])) === 4 ? $fitQuad : null,
                'suitable_types' => collect($zone['suitable_types'] ?? [])->take(8)->map(fn ($value) => substr(trim((string) $value), 0, 50))->filter()->values()->all(),
                'clearance_score' => $this->clampPercent($zone['clearance_score'] ?? 50),
                'occlusion_score' => $this->clampPercent($zone['occlusion_score'] ?? 25),
                'confidence' => in_array($zone['confidence'] ?? null, ['low', 'medium', 'high'], true) ? $zone['confidence'] : 'low',
                'reason' => substr(trim((string) ($zone['reason'] ?? 'Detected clear placement zone.')), 0, 280),
            ];
        })->filter()->values()->all();
    }

    private function clampPercent(mixed $value, float $minimum = 0, float $maximum = 100): float
    {
        $number = is_numeric($value) ? (float) $value : $minimum;

        return round(min($maximum, max($minimum, $number)), 1);
    }

    private function errorContext(\Throwable $exception): array
    {
        $context = ['exception' => $exception::class];

        if (property_exists($exception, 'response') && $exception->response) {
            $context['status'] = $exception->response->status();
        }

        return $context;
    }

    private function productImage(FurnitureProduct $product): ?array
    {
        if (! $product->image_path) {
            return null;
        }

        if (str_starts_with($product->image_path, 'demo:')) {
            $path = public_path('images/furniture/'.substr($product->image_path, 5));
        } elseif (str_starts_with($product->image_path, 'catalog:')) {
            $path = public_path('images/catalog/'.substr($product->image_path, 8));
        } elseif (Storage::disk('public')->exists($product->image_path)) {
            return [
                'bytes' => Storage::disk('public')->get($product->image_path),
                'mime_type' => Storage::disk('public')->mimeType($product->image_path) ?: 'image/png',
            ];
        } else {
            return null;
        }

        if (! is_file($path)) {
            return null;
        }

        return ['bytes' => file_get_contents($path), 'mime_type' => mime_content_type($path) ?: 'image/png'];
    }

    private function fallbackChat(array $items, array $catalog, string $furnitureType, string $question): string
    {
        $names = collect($items)->pluck('name')->implode(', ');
        $lower = strtolower($question);
        if (str_contains($lower, 'price') || str_contains($lower, 'budget')) {
            $prices = collect($items)->map(fn ($item) => $item['name'].' (PHP '.number_format((float) $item['price'], 2).')')->implode(', ');

            return "Here are the listed prices: {$prices}. A reservation only asks the shop to hold an item; it is not a payment.";
        }
        if (str_contains($lower, 'wood') || str_contains($lower, 'material') || str_contains($lower, 'finish')) {
            $materials = collect($items)->map(fn ($item) => "{$item['name']}: {$item['wood']} wood, {$item['finish']} finish")->implode('; ');

            return $materials.'.';
        }
        if (str_contains($lower, 'fit') || str_contains($lower, 'room') || str_contains($lower, 'size')) {
            return 'Use the dimensions shown on each recommendation with your saved room width and depth. The 2D room preview is a planning aid; confirm final measurements with Lance Furniture Shop before reserving.';
        }

        $availableNames = collect($catalog)->pluck('name')->implode(', ');

        return "I can help compare {$names} by price, wood, finish, style, dimensions, room suitability, and catalog availability. Available {$furnitureType} options in the system are: {$availableNames}. Physical availability must still be confirmed directly with Lance Furniture Shop staff.";
    }

    private function catalogAvailabilityAnswer(array $catalog, string $furnitureType, string $question): ?string
    {
        $lower = strtolower($question);
        $asksAvailability = (bool) preg_match('/\b(available|availability|stock|meron|mayroon|gusto|nais|want|need|looking|hanap|request|kulay|color|do you have)\b/i', $question);

        if (! $asksAvailability) {
            return null;
        }

        $products = collect($catalog);
        if ($products->isEmpty()) {
            return "Not available in our current catalog: there are no active {$furnitureType} products listed right now. Please ask Lance Furniture Shop staff when new options may become available.";
        }

        $requested = [];
        foreach (['name', 'category', 'wood', 'finish', 'style'] as $field) {
            $values = $products->pluck($field)->filter()->unique(fn ($value) => strtolower((string) $value));
            foreach ($values as $value) {
                if (str_contains($lower, strtolower((string) $value))) {
                    $requested[$field][] = (string) $value;
                }
            }
        }

        $genericAvailabilityQuestion = preg_match('/\b(what|which|ano|anong|list|show)\b/i', $question)
            || preg_match('/\b(available|availability|stock)\b/i', $question) && $requested === [];

        if ($genericAvailabilityQuestion) {
            $list = $products->map(fn ($product) => $this->availabilityProductLabel($product))->implode('; ');

            return "Available in our current {$furnitureType} catalog: {$list}. These are active system listings; please confirm physical stock directly with Lance Furniture Shop staff.";
        }

        $matches = $products->filter(function ($product) use ($requested): bool {
            if ($requested === []) {
                return false;
            }

            foreach ($requested as $field => $values) {
                if (! collect($values)->contains(fn ($value) => strcasecmp((string) $product[$field], $value) === 0)) {
                    return false;
                }
            }

            return true;
        });

        if ($matches->isNotEmpty()) {
            $list = $matches->map(fn ($product) => $this->availabilityProductLabel($product))->implode('; ');

            return "Available in our current {$furnitureType} catalog: {$list}. This confirms an active system listing, but physical stock at the shop must still be verified by shop staff.";
        }

        $alternatives = $products->take(3)->map(fn ($product) => $this->availabilityProductLabel($product))->implode('; ');

        return "Not available in our current {$furnitureType} catalog with that exact request. Closest available options are: {$alternatives}. Please confirm physical availability and item readiness directly with Lance Furniture Shop staff.";
    }

    private function availabilityProductLabel(array $product): string
    {
        return $product['name'].' ('.$product['wood'].' wood, '.$product['finish'].' finish, '.$product['style'].', PHP '.number_format((float) $product['price'], 2).')';
    }
}
