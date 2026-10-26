<?php

namespace App\Http\Controllers;

use App\Models\Favorite;
use App\Models\FurnitureProduct;
use App\Models\FurnitureViewEvent;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function favorites(Request $request)
    {
        $products = $request->user()->favorites()
            ->orderByPivot('created_at', 'desc')->paginate(12);

        return view('catalog.favorites', compact('products'));
    }

    public function index(Request $request)
    {
        $query = FurnitureProduct::where('status', 'active')->withCount(['favorites', 'views', 'reservations']);
        foreach (['category', 'style', 'wood_type', 'varnish_finish'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        if ($request->filled('min_price')) {
            $query->where('price', '>=', $request->input('min_price'));
        }
        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->input('max_price'));
        }
        if ($request->filled('q')) {
            $search = trim((string) $request->input('q'));
            $term = '%'.$search.'%';
            $fuzzyTerm = '%'.preg_replace('/(.)\1+/u', '$1', $search).'%';
            $query->where(function ($q) use ($term, $fuzzyTerm) {
                $q->where('name', 'like', $term)->orWhere('category', 'like', $term)->orWhere('style', 'like', $term)->orWhere('description', 'like', $term)->orWhere('tags', 'like', $term);
                if ($fuzzyTerm !== $term) {
                    $q->orWhere('name', 'like', $fuzzyTerm)->orWhere('category', 'like', $fuzzyTerm)->orWhere('style', 'like', $fuzzyTerm)->orWhere('description', 'like', $fuzzyTerm)->orWhere('tags', 'like', $fuzzyTerm);
                }
            });
        }
        $products = $query->orderBy('name')->paginate(12)->withQueryString();
        $options = ['categories' => FurnitureProduct::where('status', 'active')->distinct()->orderBy('category')->pluck('category'), 'styles' => FurnitureProduct::where('status', 'active')->distinct()->orderBy('style')->pluck('style'), 'woods' => FurnitureProduct::where('status', 'active')->distinct()->orderBy('wood_type')->pluck('wood_type'), 'finishes' => FurnitureProduct::where('status', 'active')->distinct()->orderBy('varnish_finish')->pluck('varnish_finish')];
        $favoriteIds = $request->user()?->favorites()->pluck('furniture_products.id') ?? collect();

        return view('catalog.index', compact('products', 'options', 'favoriteIds'));
    }

    public function search(Request $request)
    {
        $term = trim((string) $request->query('q'));
        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }
        $like = '%'.$term.'%';
        $simplified = preg_replace('/(.)\1+/u', '$1', $term);
        $fuzzyLike = '%'.$simplified.'%';
        $products = FurnitureProduct::where('status', 'active')->where(function ($q) use ($like, $fuzzyLike) {
            $q->where('name', 'like', $like)->orWhere('category', 'like', $like)->orWhere('style', 'like', $like)->orWhere('tags', 'like', $like);
            if ($fuzzyLike !== $like) {
                $q->orWhere('name', 'like', $fuzzyLike)->orWhere('category', 'like', $fuzzyLike)->orWhere('style', 'like', $fuzzyLike)->orWhere('tags', 'like', $fuzzyLike);
            }
        })->withCount(['views', 'favorites'])->limit(30)->get()->sortByDesc(fn ($p) => (str_starts_with(strtolower($p->name), strtolower($simplified)) ? 1000 : 0) + $p->views_count + ($p->favorites_count * 3))->take(24)->values();

        $favoriteIds = $request->user()?->favorites()->pluck('furniture_products.id') ?? collect();

        return response()->json($products->map(fn ($p) => [
            'name' => $p->name,
            'category' => $p->category,
            'price' => (float) $p->price,
            'image' => $p->image_url,
            'url' => route('catalog.show', $p),
            'wood_type' => $p->wood_type,
            'varnish_finish' => $p->varnish_finish,
            'style' => $p->style,
            'saved' => $favoriteIds->contains($p->id),
        ]));
    }

    public function show(Request $request, FurnitureProduct $furnitureProduct)
    {
        abort_if($furnitureProduct->status !== 'active', 404);
        if ($request->user()) {
            FurnitureViewEvent::create(['user_id' => $request->user()->id, 'furniture_product_id' => $furnitureProduct->id, 'session_key' => $request->session()->getId()]);
        }
        $isFavorite = $request->user() && Favorite::where('user_id', $request->user()->id)->where('furniture_product_id', $furnitureProduct->id)->exists();

        return view('catalog.show', ['product' => $furnitureProduct, 'isFavorite' => $isFavorite]);
    }

    public function favorite(Request $request, FurnitureProduct $furnitureProduct)
    {
        $favorite = Favorite::where('user_id', $request->user()->id)->where('furniture_product_id', $furnitureProduct->id)->first();
        if ($favorite) {
            $favorite->delete();
            $saved = false;
        } else {
            Favorite::create(['user_id' => $request->user()->id, 'furniture_product_id' => $furnitureProduct->id]);
            $saved = true;
        }

        return $request->expectsJson() ? response()->json(['saved' => $saved]) : back()->with('success', $saved ? 'Saved to your favorites.' : 'Removed from your favorites.');
    }
}
