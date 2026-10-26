@extends('layouts.app')
@section('title','Plan your room')
@section('content')
@php($activeRoomId = (int) old('room_image_id', $selectedRoomId))
<header class="page-heading">
    <div>
        <p class="section-kicker">Guided customer workflow</p>
        <h1>Plan your room.</h1>
        <p>Save your furniture preferences to generate recommendations. Add a room photo for a room preview if you wish.</p>
    </div>
    <a class="btn btn-outline-dark" href="{{ route('recommendations.index') }}">Past recommendations</a>
</header>

<x-workflow-progress :step="$preferenceComplete ? 2 : 1" />

<section id="preference-form" class="workflow-section" aria-labelledby="preference-heading">
    <div class="workflow-section-heading">
        <div>
            <p class="section-kicker mb-1">Step 1 · Required</p>
            <h2 id="preference-heading">Browse and set your preferences.</h2>
            <p>Choose the furniture you need, then save the budget, materials, and style the recommendation engine should use.</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="workflow-state is-done" data-preference-save-state @if(!$preferenceComplete) hidden @endif>Saved</span>
            <a class="btn btn-outline-dark" href="{{ route('catalog.index') }}">Browse catalog</a>
        </div>
    </div>

    <form method="POST" action="{{ route('preferences.update') }}" class="panel" data-preferences-form>
        @csrf
        <div class="alert fc-alert mb-4" role="status" aria-live="polite" data-preferences-status hidden></div>
        <div class="row g-4">
            <div class="col-md-4"><label class="form-label" for="furniture_type">Furniture type</label><select class="form-select" id="furniture_type" name="furniture_type" required><option value="">Choose furniture</option>@foreach($furnitureTypes as $type)<option value="{{ $type }}" @selected(old('furniture_type',$preference?->furniture_type)===$type)>{{ $type }}</option>@endforeach</select><div class="form-text">Recommendations will only include this type.</div></div>
            <?php
                $budgetRanges = [];
                for ($upper = 10000; $upper <= 100000; $upper += 10000) {
                    $lower = $upper === 10000 ? 1000 : $upper - 9999;
                    $budgetRanges[$lower.'-'.$upper] = 'PHP '.number_format($lower).' – '.number_format($upper);
                }
                $budgetRanges['100001-500000'] = 'PHP 100,001 – 500,000';
                $savedMinimum = old('budget_min', $preference?->budget_min);
                $savedMaximum = old('budget_max', $preference?->budget_max);
                $selectedBudget = old('budget_range', $savedMinimum !== null && $savedMaximum !== null ? (float) $savedMinimum.'-'.(float) $savedMaximum : '');
                if ($selectedBudget && !isset($budgetRanges[$selectedBudget]) && $savedMinimum !== null && $savedMaximum !== null) {
                    $budgetRanges[$selectedBudget] = 'PHP '.number_format($savedMinimum, 2).' – '.number_format($savedMaximum, 2).' (saved budget)';
                }
            ?>
            <div class="col-md-8"><label class="form-label" for="budget_range">Budget range</label><select class="form-select" id="budget_range" name="budget_range" required><option value="">Choose your budget</option>@foreach($budgetRanges as $value => $label)<option value="{{ $value }}" @selected($selectedBudget === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-md-4"><label class="form-label" for="style">Furniture style</label><select class="form-select" id="style" name="style"><option value="">Open to any style</option>@foreach($styles as $style)<option @selected(old('style',$preference?->style)===$style)>{{ $style }}</option>@endforeach</select></div>
            <div class="col-md-4"><label class="form-label" for="wood_type">Wood type</label><select class="form-select" id="wood_type" name="wood_type"><option value="">Either wood</option>@foreach($woodTypes as $value)<option @selected(old('wood_type',$preference?->wood_type)===$value)>{{ $value }}</option>@endforeach</select></div>
            <div class="col-md-4"><label class="form-label" for="varnish_finish">Varnish finish</label><select class="form-select" id="varnish_finish" name="varnish_finish"><option value="">Any finish</option>@foreach($varnishFinishes as $value)<option @selected(old('varnish_finish',$preference?->varnish_finish)===$value)>{{ $value }}</option>@endforeach</select></div>
            <div class="col-12"><label class="form-label" for="notes">What else matters?</label><textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Example: Easy-clean dining furniture for a family of six.">{{ old('notes',$preference?->notes) }}</textarea></div>
            <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary btn-lg" type="submit" data-preferences-submit>{{ $preference ? 'Update preferences' : 'Save preferences and continue' }}</button></div>
        </div>
    </form>
</section>

<section id="room-preview" class="workflow-section" aria-labelledby="room-preview-heading">
    <div class="workflow-section-heading">
        <div>
            <p class="section-kicker mb-1">Step 2 · Optional</p>
            <h2 id="room-preview-heading">Choose a room for automatic AI fitting.</h2>
            <p>Upload a clear room photo. FurniCraft detects the usable space and perspective, then automatically positions and scales recommended <span data-saved-furniture-type-lower>{{ strtolower($preference?->furniture_type ?: 'furniture') }}</span> in the preview.</p>
        </div>
        <span class="workflow-state is-done" data-room-step-state @if(!$activeRoomId) hidden @endif>Room selected</span>
    </div>

    @unless($preferenceComplete)
        <div class="alert alert-warning fc-alert" data-step-one-warning>Complete Step 1 before uploading a room image.</div>
    @endunless

    <div class="row g-4">
        <div class="col-lg-5">
            <form method="POST" action="{{ route('room.store') }}" enctype="multipart/form-data" class="panel vstack gap-3">
                @csrf
                <input type="hidden" name="return_to" value="recommendations">
                <fieldset class="vstack gap-3" data-room-upload-fieldset @disabled(!$preferenceComplete)>
                    <div>
                        <label class="form-label" for="room_image">Room photo</label>
                        <input class="form-control" id="room_image" type="file" name="room_image" data-room-photo-input accept="image/jpeg,image/png,image/webp" required>
                        <div class="form-text">JPG, PNG, or WebP up to 8 MB. FurniCraft automatically analyzes the photo, chooses a clear placement area, and adjusts the preview scale. No room measurements are needed.</div>
                        <div class="small mt-2" data-room-photo-quality role="status" aria-live="polite"></div>
                    </div>
                    <label class="form-check panel room-photo-consent mb-0"><input class="form-check-input" type="checkbox" name="gemini_consent" value="1" @checked(old('gemini_consent'))><span class="form-check-label">Allow FurniCraft to send this room photo to Google Gemini for automatic AI analysis, placement, and preview fitting. <a href="{{ route('privacy-policy') }}" target="_blank" rel="noopener">Privacy details</a></span></label>
                    @error('gemini_consent')<p class="text-danger small" role="alert">{{ $message }}</p>@enderror
                    <button class="btn btn-primary btn-lg" type="submit">Upload room photo</button>
                </fieldset>
            </form>
        </div>

        <div class="col-lg-7">
            <h3 class="h4 mb-3">Your analyzed rooms</h3>
            <p class="small text-muted" data-room-selection-status aria-live="polite">Click a room to use it for your recommendation.</p>
            @forelse($rooms as $room)
                @php($isSelectedRoom = $activeRoomId === $room->id)
                @php($roomReady = in_array($room->analysis_status, ['completed', 'fallback'], true))
                @php($statusLabel = ['completed' => 'AI analyzed', 'queued' => 'Queued', 'processing' => 'Analyzing', 'retrying' => 'Retrying', 'failed' => 'Needs retry'][$room->analysis_status] ?? 'Local guidance')
                <article class="panel room-option mb-3 d-flex flex-column flex-sm-row gap-3 align-items-sm-center {{ $isSelectedRoom ? 'is-selected' : '' }}" data-room-option data-room-id="{{ $room->id }}" data-room-ready="{{ $roomReady ? 'true' : 'false' }}" data-room-analysis-current="{{ $room->analysis_status }}" data-room-analysis-watch="{{ route('room.analysis-status', $room) }}" role="button" tabindex="0" aria-pressed="{{ $isSelectedRoom ? 'true' : 'false' }}" aria-label="Use Room {{ $room->id }} for recommendations">
                    <img src="{{ route('room-images.original', $room) }}" alt="Uploaded room from {{ $room->created_at->format('M j, Y') }}" width="140" height="100" style="object-fit:cover">
                    <div class="flex-grow-1">
                        <div><strong>Room {{ $room->id }} · {{ $room->created_at->format('F j, Y · g:i A') }}</strong><span class="badge text-bg-primary ms-1" data-room-selected-badge @if(!$isSelectedRoom) hidden @endif>Selected</span><span class="badge text-bg-light ms-1" data-room-analysis-label>{{ $statusLabel }}</span></div>
                        <p class="small text-muted mb-2">Automatic image-based fit · <span data-room-analysis-message>{{ $room->analysis_error ?: (data_get($room->analysis, 'gemini_used') ? 'AI placement geometry is ready.' : 'Local placement guidance is ready.') }}</span></p>
                        <div class="d-flex flex-wrap gap-2">
                            
                            <form method="POST" action="{{ route('room.destroy', $room) }}" data-delete-room>@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" type="submit">Delete photo</button></form>
                            @unless(in_array($room->analysis_status, ['queued', 'processing', 'retrying'], true))
                                <form method="POST" action="{{ route('room.reanalyze', $room) }}">@csrf<button class="btn btn-sm btn-outline-dark" type="submit">Re-analyze room</button></form>
                            @endunless
                        </div>
                    </div>
                </article>
            @empty
                <div class="empty-state"><h3>No analyzed room yet.</h3><p>You can generate recommendations using your saved preferences without a room photo.</p></div>
            @endforelse
        </div>
    </div>

    <form method="POST" action="{{ route('recommendations.store') }}" class="panel mt-4" id="recommendation-form">
        @csrf
        <input type="hidden" id="room_image_id" name="room_image_id" value="{{ $activeRoomId ?: '' }}">
        <div class="row g-3 align-items-center">
            <div class="col-lg">
                <p class="section-kicker mb-1">AI-powered matching</p>
                <h3 class="h4 mb-1">Generate <span data-saved-furniture-type>{{ $preference?->furniture_type ?: 'furniture' }}</span> recommendations</h3>
                <p class="text-muted mb-0" data-selected-room-label>{{ $activeRoomId ? 'Room '.$activeRoomId.' selected. AI will fit each preview to the detected space.' : 'No room selected. Recommendations will use your saved preferences.' }} Only active <span data-saved-furniture-type-lower>{{ strtolower($preference?->furniture_type ?: 'furniture') }}</span> products will be ranked by your budget, style, wood, finish, and room fit.</p>
            </div>
            <div class="col-lg-auto d-grid">
                <button class="btn btn-outline-dark mb-2" type="button" data-skip-room @disabled(!$preferenceComplete)>Continue without room photo</button><button class="btn btn-primary btn-lg" type="submit" data-generate-recommendations @disabled(!$preferenceComplete)>Generate recommendations</button>
            </div>
        </div>
    </form>
</section>
@endsection
