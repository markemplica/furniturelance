@extends('layouts.app')
@section('title', 'My favorites')
@section('content')
<header class="page-heading">
    <div><p class="section-kicker">Your collection</p><h1>My favorites</h1><p>Find the furniture you saved, all in one place.</p></div>
    <a class="btn btn-outline-dark" href="{{ route('catalog.index') }}">Browse catalog</a>
</header>
@if($products->isNotEmpty())
    <p class="text-muted">{{ $products->total() }} saved {{ Str::plural('item', $products->total()) }}</p>
    <div class="product-grid">
        @foreach($products as $product)
            <div>
                @if($product->status === 'active')
                    <x-product-card :product="$product" :saved="true" />
                @else
                    <article class="product-card">
                        <img src="{{ $product->image_url }}" alt="{{ $product->name }}">
                        <div class="product-card-body"><h3>{{ $product->name }}</h3><p class="text-muted">This item is currently unavailable.</p></div>
                    </article>
                @endif
                <form method="POST" action="{{ route('catalog.favorite', $product) }}" class="mt-2">
                    @csrf
                    <button class="btn btn-outline-dark w-100" type="submit">Remove from favorites</button>
                </form>
            </div>
        @endforeach
    </div>
    <div class="mt-4">{{ $products->links() }}</div>
@else
    <div class="empty-state"><h2>No favorites yet.</h2><p>Open a product in the catalog and select Save to favorites.</p><a class="btn btn-primary" href="{{ route('catalog.index') }}">Explore furniture</a></div>
@endif
@endsection
