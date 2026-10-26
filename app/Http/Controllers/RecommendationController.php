<?php

namespace App\Http\Controllers;

use App\Models\Recommendation;
use App\Services\FurniturePlacementService;
use App\Services\RecommendationService;
use Illuminate\Http\Request;

class RecommendationController extends Controller
{
    public function index(Request $request)
    {
        $recommendations = $request->user()->recommendations()->withCount('items')->latest()->paginate(10);

        return view('recommendations.index', compact('recommendations'));
    }

    public function store(Request $request, RecommendationService $service)
    {
        $preference = $request->user()->preference()->first();
        if (! $preference || blank($preference->furniture_type)) {
            return redirect()->to(route('preferences.edit').'#preference-form')->withErrors(['workflow' => 'Save your furniture preferences before generating recommendations.']);
        }

        $data = $request->validate([
            'room_image_id' => ['nullable', 'integer'],
        ]);

        $room = !empty($data['room_image_id']) ? $request->user()->roomImages()->findOrFail($data['room_image_id']) : null;
        if ($room && in_array($room->analysis_status, ['queued', 'processing', 'retrying'], true)) {
            return redirect()->to(route('preferences.edit', ['room' => $room->id]).'#room-preview')
                ->withErrors(['room_image_id' => 'Room analysis is still running. Recommendations will be available as soon as it finishes.']);
        }
        $preference->update([
            'room_width_m' => $room?->room_width_m,
            'room_depth_m' => $room?->room_depth_m,
        ]);
        $recommendation = $service->generate($request->user(), $preference, $room, $preference->furniture_type);

        return redirect()->route('recommendations.show', $recommendation)->with('success', $room ? 'Your recommendations and room preview are ready.' : 'Your recommendations are ready.');
    }

    public function show(Request $request, Recommendation $recommendation, FurniturePlacementService $placementService, RecommendationService $ranking)
    {
        abort_unless($recommendation->user_id === $request->user()->id, 403);
        $recommendation->load('items.product', 'messages', 'preference', 'roomImage');
        if ($recommendation->algorithm_version !== 'hybrid-v3-strict-preferences') {
            $ranking->generate($request->user(), $recommendation->preference, $recommendation->roomImage, $recommendation->furniture_type, $recommendation);
        }
        $recommendation->setRelation('items', $recommendation->items->filter(
            fn ($item) => $item->product && $ranking->matchesPreferences($item->product, $recommendation->preference)
        )->values());
        $room = $recommendation->roomImage;
        $placements = $room
            ? $recommendation->items->mapWithKeys(fn ($item) => [
                $item->product->id => $placementService->forProduct(
                    $room->analysis ?? [],
                    $item->product,
                    $room->room_width_m ? (float) $room->room_width_m : null,
                    $room->room_depth_m ? (float) $room->room_depth_m : null,
                ),
            ])
            : collect();

        $suggestions = $recommendation->items->isEmpty()
            ? $ranking->closestSuggestions($recommendation->preference, $recommendation->furniture_type)
            : collect();
        return view('recommendations.show', compact('recommendation', 'placements', 'suggestions'));
    }
}
