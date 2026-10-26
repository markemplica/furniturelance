@extends('layouts.app')
@section('title','Room comparison preview')
@section('content')
@php
    $selectedItem = $recommendation?->items->firstWhere('furniture_product_id', $selectedProduct?->id);
    $hasAiRoomFit = $roomImage->analysis_status === 'completed' && data_get($roomImage->analysis, 'gemini_used') && filled(data_get($roomImage->analysis, 'vision_geometry'));
@endphp
<div class="room-compare-layout">
    <aside class="room-compare-story">
        <p class="section-kicker">Your room, reimagined</p>
        <h1>See <span data-preview-name>{{ $selectedProduct?->name ?? 'furniture' }}</span> in your space.</h1>
        <p class="room-compare-intro">{{ $hasAiRoomFit
            ? 'FurniCraft automatically positions and scales the selected furniture using the clear area and perspective detected in the room photo.'
            : 'This room is currently using a local 2D estimate because AI fitting was unavailable. Restore Gemini access, then retry AI fitting for an image-based result.' }}</p>



        <section class="room-source" aria-labelledby="image-used-heading">
            <h2 id="image-used-heading" class="room-label">Image used</h2>
            <div class="room-source-card">
                <img src="{{ route('room-images.original', $roomImage) }}" alt="Uploaded room thumbnail">
                <div><span>Original room</span><strong>Room {{ $roomImage->id }}</strong><small>{{ $hasAiRoomFit ? 'AI image-based fit' : 'Local 2D estimate' }}</small></div>
            </div>
        </section>

        <ol class="comparison-steps">
            <li><span>1</span><div><strong>Before</strong><small>Your uploaded room remains the base scene.</small></div></li>
            <li><span>2</span><div><strong>After</strong><small><span data-preview-name>{{ $selectedProduct?->name ?? 'The furniture' }}</span> is automatically placed and scaled to the detected space.</small></div></li>
        </ol>

        @if($selectedProduct)
        <article class="preview-product-card" data-preview-product-card>
            <img src="{{ $selectedProduct->image_url }}" alt="{{ $selectedProduct->name }}" data-preview-card-image>
            <div>
                <span class="room-label">{{ $recommendation ? 'Recommended product' : 'Selected product' }}</span>
                <strong data-preview-name>{{ $selectedProduct->name }}</strong>
                <small><span data-preview-score @if(!$selectedItem) hidden @endif>{{ $selectedItem ? number_format($selectedItem->total_score, 1).'% match · ' : '' }}</span>PHP <span data-preview-price>{{ number_format($selectedProduct->price, 2) }}</span></small>
                <a href="{{ route('catalog.show', $selectedProduct) }}" data-preview-product-link>View product details</a>
            </div>
        </article>
        @endif
    </aside>

    <section class="comparison-panel" aria-labelledby="comparison-heading">
        <header class="comparison-panel-head">
            <div><span class="room-label">2D placement preview</span><h2 id="comparison-heading">Automatic 2D room preview</h2></div>
            <span class="preview-status"><i aria-hidden="true"></i> Planning preview</span>
        </header>

        @php($analysisRunning = in_array($roomImage->analysis_status, ['queued', 'processing', 'retrying'], true))
        @php($analysisLabel = ['completed' => 'AI room analysis complete', 'queued' => 'Room analysis queued', 'processing' => 'Analyzing room image', 'retrying' => 'AI temporarily unavailable · retrying', 'failed' => 'AI room analysis needs attention'][$roomImage->analysis_status] ?? 'Safe local room guidance')
        <div class="alert {{ $roomImage->analysis_status === 'failed' ? 'alert-warning' : 'alert-light' }} fc-alert" role="status" aria-live="polite" data-room-analysis-watch="{{ route('room.analysis-status', $roomImage) }}" data-room-analysis-current="{{ $roomImage->analysis_status }}">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div><strong data-room-analysis-label>{{ $analysisLabel }}</strong><div class="small text-muted" data-room-analysis-message>{{ $roomImage->analysis_error ?: ($analysisRunning ? 'You may keep this page open; the final analysis will appear automatically.' : 'Placement confidence and detected room zones are shown below.') }}</div></div>
                @unless($analysisRunning || auth()->user()->isAdmin())
                    <form method="POST" action="{{ route('room.reanalyze', $roomImage) }}">@csrf<button class="btn btn-sm btn-outline-primary" type="submit">Re-analyze room</button></form>
                @endunless
            </div>
        </div>
<div class="placement-status" aria-live="polite">
            <div><span class="room-label">Selected furniture · planning preview</span><strong data-placement-status>{{ data_get($selectedPlacement, 'renderable', true) ? 'Automatic image fit' : 'No validated placement available' }}</strong></div>
            <span data-placement-dimensions></span>
            <p data-placement-guidance class="small mb-0"></p>
        </div>

        <script type="application/json" data-initial-placement-quad>@json(data_get($selectedPlacement, 'quad_percent'))</script>

                <script type="application/json" data-preview-analysis>@json($roomImage->analysis ?? [])</script>
        <div class="room-preview-frame" data-room-preview
            data-room-width="{{ $roomImage->room_width_m }}" data-room-depth="{{ $roomImage->room_depth_m }}"
            data-product-id="{{ $selectedProduct?->id }}" data-product-name="{{ $selectedProduct?->name }}"
            data-product-width="{{ $selectedProduct?->width_cm }}" data-product-depth="{{ $selectedProduct?->depth_cm }}" data-product-height="{{ $selectedProduct?->height_cm }}" data-product-dimensions-estimated="{{ $selectedProduct?->dimensions_estimated ? 'true' : 'false' }}"
            data-product-type="{{ $selectedProduct?->recommendationType() }}" data-product-category="{{ $selectedProduct?->category }}"
            data-initial-x="{{ data_get($selectedPlacement,'x_percent',50) }}" data-initial-y="{{ data_get($selectedPlacement,'y_percent',86) }}"
            data-initial-width="{{ data_get($selectedPlacement,'max_width_percent',65) }}" data-initial-quad='@json(data_get($selectedPlacement,"quad_percent"))'
            data-confidence="{{ data_get($selectedPlacement,'confidence','low') }}" data-placement-source="{{ data_get($selectedPlacement,'source','safe-fallback') }}"
            data-placement-status="{{ $roomImage->analysis_status }}" data-consented="{{ $roomImage->gemini_consent_at ? 'true' : 'false' }}"
            data-preview-v2-url="{{ $selectedProduct ? route('room.preview-v2.store', $roomImage) : '' }}"
            data-verify-url="{{ $selectedProduct ? route('room.preview-verify', [$roomImage,$selectedProduct]) : '' }}"
            data-csrf="{{ csrf_token() }}">
            <img class="room-preview-source" src="{{ route('room-images.original', $roomImage) }}" alt="" data-preview-room>
            @if($selectedProduct)<img class="room-preview-source" src="{{ $selectedProduct->image_url }}" alt="" data-preview-product>@endif
            <canvas class="room-preview-canvas" data-room-preview-canvas role="img" aria-label="Automatically fitted furniture preview"></canvas>
            <div class="room-preview-loading" data-preview-loading role="status" aria-live="polite">Uploading · Analyzing room · Fitting furniture · Checking the result</div>
        </div>
        <div class="preview-detection-row" data-preview-detections aria-live="polite"></div>
        <div class="room-preview-actions">
                @if($roomImage->gemini_consent_at)
                <button type="button" class="btn btn-outline-primary" data-preview-retry>Retry AI preview</button>
                @endif
            <button type="button" class="btn btn-primary" data-preview-download>Download preview (PNG)</button>
            <form method="POST" action="{{ route('room.reanalyze',$roomImage) }}">@csrf<button class="btn btn-outline-secondary" type="submit">Try again</button></form>
            <a class="btn btn-outline-secondary" href="{{ route('preferences.edit',['room'=>$roomImage->id]) }}#room-preview">Upload another photo</a>
        </div>
            <p class="automatic-placement-note">Preview is an estimate, not a measurement. Verify actual dimensions with Lance Furniture Shop before reserving.</p>


        

        <div class="d-flex justify-content-end mt-3">
            @unless(auth()->user()->isAdmin())
                <a class="btn btn-outline-primary" href="{{ $recommendation ? route('recommendations.show', $recommendation) : route('preferences.edit', ['room' => $roomImage->id]).'#recommendation-form' }}">{{ $recommendation ? 'Back to recommendations' : 'Use room for recommendations' }}</a>
            @endunless
        </div>
        <div class="d-flex flex-wrap justify-content-end gap-2 mt-3">
            @if($roomImage->gemini_consent_at && !auth()->user()->isAdmin())<form method="POST" action="{{ route('room.ai-consent.revoke', $roomImage) }}" onsubmit="return confirm('Stop future AI processing and delete generated previews for this photo?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-secondary" type="submit">Turn off AI for this photo</button></form>@endif
            @unless(auth()->user()->isAdmin())<form method="POST" action="{{ route('room.destroy', $roomImage) }}" onsubmit="return confirm('Delete this room photo and its generated previews?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" type="submit">Delete room photo</button></form>@endunless
        </div>
    </section>
</div>
@endsection
