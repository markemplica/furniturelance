@extends('layouts.app')
@section('title','Furniture catalog')
@section('content')
<header class="page-heading"><div><p class="section-kicker">The Lance collection</p><h1>Furniture made to live with.</h1><p>Explore by name, budget, style, wood, and finish—or let FurniCraft find the closest fit for your room.</p></div><a class="btn btn-primary" href="{{ route('preferences.edit') }}">Find my match</a></header>
<p class="alert alert-light fc-alert">These are active catalog listings. FurniCraft does not track inventory; confirm item availability and readiness directly with Lance Furniture Shop.</p>
<form method="GET" action="{{ route('catalog.index') }}" class="filter-panel catalog-filters">
    <div class="search-wrap mb-2"><label class="form-label" for="catalog-search">Search</label><input id="catalog-search" class="form-control" name="q" value="{{ request('q') }}" placeholder="Search Furniture" autocomplete="off" data-live-search data-endpoint="{{ route('catalog.search') }}" aria-describedby="search-help"><div id="search-help" class="form-text">Results appear as you type. Partial words are supported.</div><div class="search-results" data-search-results hidden></div></div>
    <div class="row g-2 align-items-end">
        <div class="col-6 col-lg-2"><label class="form-label" for="category">Category</label><select class="form-select" id="category" name="category"><option value="">All</option>@foreach($options['categories'] as $value)<option @selected(request('category')===$value)>{{ $value }}</option>@endforeach</select></div>
        <div class="col-6 col-lg-2"><label class="form-label" for="style">Style</label><select class="form-select" id="style" name="style"><option value="">All</option>@foreach($options['styles'] as $value)<option @selected(request('style')===$value)>{{ $value }}</option>@endforeach</select></div>
        <div class="col-6 col-lg-2"><label class="form-label" for="wood">Wood</label><select class="form-select" id="wood" name="wood_type"><option value="">Any</option>@foreach($options['woods'] as $value)<option @selected(request('wood_type')===$value)>{{ $value }}</option>@endforeach</select></div>
        <div class="col-6 col-lg-2"><label class="form-label" for="finish">Finish</label><select class="form-select" id="finish" name="varnish_finish"><option value="">Any</option>@foreach($options['finishes'] as $value)<option @selected(request('varnish_finish')===$value)>{{ $value }}</option>@endforeach</select></div>
        <div class="col-6 col-lg-1"><label class="form-label" for="min-price">Min PHP</label><input class="form-control" id="min-price" type="number" min="0" name="min_price" value="{{ request('min_price') }}"></div>
        <div class="col-6 col-lg-1"><label class="form-label" for="max-price">Max PHP</label><input class="form-control" id="max-price" type="number" min="0" name="max_price" value="{{ request('max_price') }}"></div>
        <div class="col-lg-2 d-flex gap-2"><button class="btn btn-dark flex-fill">Apply</button><a class="btn btn-outline-dark" href="{{ route('catalog.index') }}">Clear</a></div>
    </div>
</form>
<div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0" data-product-count>{{ $products->total() }} {{ Str::plural('piece', $products->total()) }}</h2><span class="text-muted small">Prices are catalog estimates; confirm final specifications with the shop.</span></div>
<div class="product-grid" data-product-grid @if($products->isEmpty()) hidden @endif>@foreach($products as $product)<x-product-card :product="$product" :saved="$favoriteIds->contains($product->id)" />@endforeach</div>
<div class="empty-state" data-live-search-empty hidden><h2>No furniture matches your search.</h2><p>Try a different name, category, or style.</p></div>
<div class="empty-state" data-server-empty @if($products->isNotEmpty()) hidden @endif><h2>No furniture matches these filters.</h2><p>Try a wider budget or remove one material preference.</p><a class="btn btn-outline-primary" href="{{ route('catalog.index') }}">Reset filters</a></div>
<div data-catalog-pagination>{{ $products->links() }}</div>
@endsection

