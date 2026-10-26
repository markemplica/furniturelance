<?php

namespace App\Services;

use App\Models\CustomerPreference;
use App\Models\Favorite;
use App\Models\FurnitureProduct;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\RoomImage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecommendationService
{
    public function __construct(private GeminiService $gemini) {}

    public function generate(User $user, CustomerPreference $preference, ?RoomImage $roomImage, string $furnitureType, ?Recommendation $existing = null): Recommendation
    {
        $products = FurnitureProduct::query()->where('status', 'active')->get()
            ->filter(fn (FurnitureProduct $product) => $product->recommendationType() === $furnitureType)
            ->filter(fn (FurnitureProduct $product) => $this->matchesPreferences($product, $preference))
            ->values();
        $hasPriorSignals = Favorite::where('user_id', $user->id)->exists()
            || Reservation::where('user_id', $user->id)->exists();
        $collaborative = $this->collaborativeScores($user, $products);

        $ranked = $products->map(function (FurnitureProduct $product) use ($preference, $collaborative) {
            $content = $this->contentScore($product, $preference);
            $collab = $collaborative[$product->id] ?? 0;
            $total = round(($content * .82) + ($collab * .18), 3);

            return ['product' => $product, 'content' => $content, 'collaborative' => $collab, 'total' => $total];
        })->sortByDesc('total')->take(8)->values();

        $ai = $this->gemini->localRecommendationSummary(array_merge($preference->toArray(), ['furniture_type' => $furnitureType]));

        return DB::transaction(function () use ($user, $preference, $roomImage, $furnitureType, $ranked, $ai, $hasPriorSignals, $existing) {
            $recommendation = $existing ?? new Recommendation;
            $recommendation->fill([
                'user_id' => $user->id,
                'customer_preference_id' => $preference->id,
                'room_image_id' => $roomImage?->id,
                'furniture_type' => $furnitureType,
                'summary' => $ai['text'],
                'algorithm_version' => 'hybrid-v3-strict-preferences',
                'gemini_used' => $ai['used'],
                'is_cold_start' => ! $hasPriorSignals,
                'content_weight' => 0.82,
                'collaborative_weight' => 0.18,
            ]);
            $recommendation->save();
            $recommendation->items()->delete();

            foreach ($ranked as $index => $row) {
                $product = $row['product'];
                $reasons = collect([
                    "is a {$furnitureType} from your selected furniture type",
                    $this->matches($preference->style, $product->style) ? "matches your {$product->style} style" : null,
                    $this->matches($preference->wood_type, $product->wood_type) ? "uses your preferred {$product->wood_type} wood" : null,
                    $this->matches($preference->varnish_finish, $product->varnish_finish) ? "has the {$product->varnish_finish} finish you selected" : null,
                    ((float) $product->price >= (float) $preference->budget_min && (float) $product->price <= (float) $preference->budget_max) ? 'fits your budget range' : null,
                ])->filter()->values();

                $recommendation->items()->create([
                    'furniture_product_id' => $product->id,
                    'content_score' => $row['content'],
                    'collaborative_score' => $row['collaborative'],
                    'total_score' => $row['total'],
                    'position' => $index + 1,
                    'explanation' => $reasons->isEmpty() ? 'This is the closest active catalog match based on your combined preferences; physical availability must be confirmed with Lance Furniture Shop.' : ucfirst($reasons->implode(', ')).'.',
                ]);
            }

            return $recommendation->load('items.product', 'preference', 'roomImage');
        });
    }

    private function contentScore(FurnitureProduct $product, CustomerPreference $preference): float
    {
        $score = 10.0;
        $price = (float) $product->price;
        $min = (float) $preference->budget_min;
        $max = max((float) $preference->budget_max, 1);
        $score += ($price >= $min && $price <= $max) ? 30 : max(0, 30 - (abs($price - min(max($price, $min), $max)) / $max * 30));
        $score += $this->matches($preference->style, $product->style) ? 20 : 0;
        $score += $this->matches($preference->wood_type, $product->wood_type) ? 15 : 0;
        $score += $this->matches($preference->varnish_finish, $product->varnish_finish) ? 15 : 0;

        if ($preference->room_width_m && $preference->room_depth_m && $product->width_cm && $product->depth_cm) {
            $fits = ((float) $product->width_cm / 100 <= (float) $preference->room_width_m * .85)
                && ((float) $product->depth_cm / 100 <= (float) $preference->room_depth_m * .85);
            $score += $fits ? 10 : 0;
        } else {
            $score += 5;
        }

        return round(min(100, $score), 3);
    }

    public function matchesPreferences(FurnitureProduct $product, CustomerPreference $preference): bool
    {
        return $product->status === 'active'
            && (float) $product->price >= (float) $preference->budget_min
            && (float) $product->price <= (float) $preference->budget_max
            && $this->matches($preference->wood_type, $product->wood_type)
            && $this->matches($preference->varnish_finish, $product->varnish_finish)
            && $this->matches($preference->style, $product->style);
    }

    public function closestSuggestions(CustomerPreference $preference, string $type): Collection
    {
        return FurnitureProduct::where('status', 'active')->get()
            ->filter(fn ($product) => $product->recommendationType() === $type && ! $this->matchesPreferences($product, $preference))
            ->map(function ($product) use ($preference) {
                $differences = [];
                foreach (['wood_type' => 'Wood', 'style' => 'Style', 'varnish_finish' => 'Finish'] as $field => $label) {
                    if (! $this->matches($preference->$field, $product->$field)) {
                        $differences[] = $label.': '.($product->$field ?: 'unspecified').' instead of '.$preference->$field;
                    }
                }
                $price = (float) $product->price;
                $gap = max(0, $price - (float) $preference->budget_max, (float) $preference->budget_min - $price);
                if ($gap > 0) {
                    $differences[] = 'PHP '.number_format($gap, 2).($price > (float) $preference->budget_max ? ' above your maximum budget' : ' below your minimum budget');
                }
                return ['product' => $product, 'differences' => $differences,
                    'distance' => count($differences) + $gap / max(1, (float) $preference->budget_max)];
            })->sortBy(fn ($row) => [$row['distance'], (float) $row['product']->price, $row['product']->id])->take(3)->values();
    }

    private function collaborativeScores(User $user, Collection $products): array
    {
        $own = Favorite::where('user_id', $user->id)->pluck('furniture_product_id')
            ->merge(Reservation::where('user_id', $user->id)->pluck('furniture_product_id'))->unique();

        $similarUserIds = $own->isEmpty() ? collect() : Favorite::whereIn('furniture_product_id', $own)
            ->where('user_id', '!=', $user->id)->pluck('user_id')->unique();

        $signals = collect();
        if ($similarUserIds->isNotEmpty()) {
            $signals = Favorite::whereIn('user_id', $similarUserIds)->select('furniture_product_id', DB::raw('count(*) as favorite_count'))
                ->groupBy('furniture_product_id')->pluck('favorite_count', 'furniture_product_id');
        }

        if ($signals->isEmpty()) {
            $signals = Favorite::select('furniture_product_id', DB::raw('count(*) as favorite_count'))->groupBy('furniture_product_id')->pluck('favorite_count', 'furniture_product_id');
        }

        $max = max(1, (int) $signals->max());

        return $products->mapWithKeys(fn ($product) => [$product->id => round(((int) ($signals[$product->id] ?? 0) / $max) * 100, 3)])->all();
    }

    private function matches(?string $expected, ?string $actual): bool
    {
        return blank($expected) || strcasecmp(trim($expected), trim((string) $actual)) === 0;
    }
}

