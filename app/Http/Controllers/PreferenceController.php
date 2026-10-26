<?php

namespace App\Http\Controllers;

use App\Models\FurnitureProduct;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PreferenceController extends Controller
{
    public function edit(Request $request)
    {
        $preference = $request->user()->preference()->first();
        $preferenceComplete = filled($preference?->furniture_type);
        $styles = FurnitureProduct::where('status', 'active')->distinct()->orderBy('style')->pluck('style');
        $woodTypes = FurnitureProduct::where('status', 'active')->distinct()->orderBy('wood_type')->pluck('wood_type');
        $varnishFinishes = FurnitureProduct::where('status', 'active')->distinct()->orderBy('varnish_finish')->pluck('varnish_finish');
        $furnitureTypes = FurnitureProduct::recommendationTypes();
        $rooms = $request->user()->roomImages()->latest()->get();
        $selectedRoomId = $rooms->contains('id', $request->integer('room')) ? $request->integer('room') : null;

        return view('preferences.edit', compact('preference', 'preferenceComplete', 'styles', 'woodTypes', 'varnishFinishes', 'furnitureTypes', 'rooms', 'selectedRoomId'));
    }

    public function update(Request $request)
    {
        if ($request->has('budget_range')) {
            $range = $request->validate(['budget_range' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,2})?-\d+(?:\.\d{1,2})?$/']]);
            [$minimum, $maximum] = explode('-', $range['budget_range']);
            $request->merge(['budget_min' => $minimum, 'budget_max' => $maximum]);
        }
        $furnitureTypes = FurnitureProduct::recommendationTypes();
        $data = $request->validate([
            'furniture_type' => ['required', 'string', Rule::in($furnitureTypes->all())],
            'budget_min' => ['required', 'numeric', 'min:0'],
            'budget_max' => ['required', 'numeric', 'gt:budget_min'],
            'style' => ['nullable', 'string', 'max:80'],
            'wood_type' => ['nullable', Rule::in(FurnitureProduct::where('status', 'active')->distinct()->pluck('wood_type')->all())],
            'varnish_finish' => ['nullable', Rule::in(FurnitureProduct::where('status', 'active')->distinct()->pluck('varnish_finish')->all())],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $preference = $request->user()->preference()->updateOrCreate([], $data);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Preferences updated successfully.',
                'preference' => $preference->only([
                    'furniture_type',
                    'budget_min',
                    'budget_max',
                    'style',
                    'wood_type',
                    'varnish_finish',
                    'notes',
                ]),
            ]);
        }

        return redirect()
            ->to(route('preferences.edit').'#room-preview')
            ->with('success', 'Preferences saved. Generate recommendations or add an optional room photo.');
    }
}
