@extends('layouts.app')
@section('title','Home')
@section('content')
<section class="home-hero">
    <div class="hero-copy"><p class="section-kicker">Intelligent furniture planning</p><h1>Furniture that feels right at home.</h1><p class="lead">Bring together your room, budget, material preferences, and style. FurniCraft turns them into clear recommendations from Lance Furniture Shop.</p><div class="d-flex flex-wrap gap-2"><a class="btn btn-primary btn-lg" href="{{ route('preferences.edit') }}">Start planning</a><a class="btn btn-outline-dark btn-lg" href="{{ route('catalog.index') }}">Explore the collection</a></div><dl class="hero-facts"><div><dt>2</dt><dd>curated wood options</dd></div><div><dt>1</dt><dd>shop location</dd></div><div><dt>2D</dt><dd>room comparison</dd></div></dl></div>
    <div class="hero-showcase">
        <div id="topProducts" class="carousel slide" data-bs-ride="carousel" data-bs-interval="4800">
            <div class="carousel-indicators">@foreach($topProducts as $index => $product)<button type="button" data-bs-target="#topProducts" data-bs-slide-to="{{ $index }}" class="{{ $index === 0 ? 'active' : '' }}" aria-label="Show {{ $product->name }}"></button>@endforeach</div>
            <div class="carousel-inner">
                @forelse($topProducts as $index => $product)
                    <article class="carousel-item {{ $index === 0 ? 'active' : '' }}">
                        <img src="{{ $product->image_url }}" class="d-block w-100" alt="{{ $product->name }}">
                        <div class="carousel-caption">
                            <div><span>{{ $product->category }}</span><h2>{{ $product->name }}</h2><p>PHP {{ number_format($product->price, 2) }}</p></div>
                            <a class="featured-view-more" href="{{ route('catalog.index') }}" aria-label="View more furniture in the catalog">View more <span aria-hidden="true">&rarr;</span></a>
                        </div>
                    </article>
                @empty
                    <div class="empty-state">The catalog is ready for its first furniture item.</div>
                @endforelse
            </div>
            @if($topProducts->count() > 1)<button class="carousel-control-prev" type="button" data-bs-target="#topProducts" data-bs-slide="prev"><span class="carousel-control-prev-icon"></span><span class="visually-hidden">Previous</span></button><button class="carousel-control-next" type="button" data-bs-target="#topProducts" data-bs-slide="next"><span class="carousel-control-next-icon"></span><span class="visually-hidden">Next</span></button>@endif
        </div>
        <p class="ranking-note">Featured products combine customer views, favorites, and confirmed or requested reservations. View more to explore the full catalog.</p>
    </div>
</section>
@endsection

