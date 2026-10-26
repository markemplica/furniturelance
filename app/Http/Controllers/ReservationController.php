<?php

namespace App\Http\Controllers;

use App\Models\FurnitureProduct;
use App\Models\Recommendation;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
{
    public function index(Request $request)
    {
        return view('reservations.index', ['reservations' => $request->user()->reservations()->with('product')->latest()->paginate(12)]);
    }

    public function store(Request $request)
    {
        $request->mergeIfMissing(['branch' => config('furnicraft.branch')]);
        $data = $request->validate([
            'furniture_product_id' => ['required', 'exists:furniture_products,id'],
            'recommendation_id' => ['nullable', 'exists:recommendations,id'],
            'branch' => ['required', Rule::in(config('furnicraft.branches'))],
            'preferred_visit_at' => ['nullable', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'customization_requested' => ['nullable', 'boolean'],
            'custom_width_cm' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'custom_depth_cm' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'custom_height_cm' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'custom_wood_type' => ['nullable', 'string', 'max:80'],
            'custom_varnish_finish' => ['nullable', 'string', 'max:80'],
            'custom_design_notes' => ['nullable', 'string', 'max:1500', 'required_if:customization_requested,1'],
        ]);
        if ($request->boolean('customization_requested')) {
            $dimensionValues = collect([
                $data['custom_width_cm'] ?? null,
                $data['custom_depth_cm'] ?? null,
                $data['custom_height_cm'] ?? null,
            ]);
            $dimensions = $dimensionValues->contains(fn ($value) => filled($value))
                ? $dimensionValues->map(fn ($value) => filled($value) ? $value : '—')->implode(' × ')
                : null;
            $customDetails = collect([
                'Requested dimensions (W × D × H cm)' => $dimensions,
                'Preferred wood' => $data['custom_wood_type'] ?? null,
                'Preferred finish' => $data['custom_varnish_finish'] ?? null,
                'Design/details' => $data['custom_design_notes'] ?? null,
            ])->filter(fn ($value) => filled($value));
            $customNote = "CUSTOM FURNITURE REQUEST:\n".$customDetails->map(fn ($value, $label) => $label.': '.$value)->implode("\n");
            $customerNotes = trim((string) ($data['notes'] ?? ''));
            $data['notes'] = $customNote.($customerNotes !== '' ? "\n\nCUSTOMER NOTES:\n".$customerNotes : '');
        }
        unset($data['customization_requested'], $data['custom_width_cm'], $data['custom_depth_cm'], $data['custom_height_cm'], $data['custom_wood_type'], $data['custom_varnish_finish'], $data['custom_design_notes']);
        $product = FurnitureProduct::where('status', 'active')->findOrFail($data['furniture_product_id']);
        if (! empty($data['recommendation_id'])) {
            $recommendation = Recommendation::where('user_id', $request->user()->id)->findOrFail($data['recommendation_id']);
            abort_unless($recommendation->items()->where('furniture_product_id', $product->id)->exists(), 422, 'That item is not part of this recommendation.');
        }
        $reservation = DB::transaction(function () use ($data, $request) {
            // Lock an existing row even when there are no previous reservations.
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $duplicate = Reservation::where('user_id', $request->user()->id)->where('furniture_product_id', $data['furniture_product_id'])->whereIn('status', ['pending', 'confirmed'])->lockForUpdate()->exists();
            if ($duplicate) return null;
            $reservation = Reservation::create(array_merge($data, ['reference' => 'FC-'.now()->format('ymd').'-'.strtoupper(Str::random(6)), 'user_id' => $request->user()->id, 'expires_at' => now()->addDays(7)]));
            $reservation->transactions()->create(['actor_id' => $request->user()->id, 'action' => 'submitted', 'notes' => 'Reservation request submitted by customer.']);
            return $reservation;
        });
        if (! $reservation) {
            throw \Illuminate\Validation\ValidationException::withMessages(['furniture_product_id' => 'You already have an active request for this item.']);
        }
        if ($request->expectsJson()) {
            return response()->json(['message' => "Reservation {$reservation->reference} was submitted for review. No payment has been collected.", 'reference' => $reservation->reference], 201);
        }
        return redirect()->route('reservations.index')->with('success', "Reservation {$reservation->reference} was submitted for review. No payment has been collected.");
    }

    public function cancel(Request $request, Reservation $reservation)
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);
        $data = $request->validate(['cancellation_reason' => ['nullable', 'string', 'max:1000']]);
        DB::transaction(function () use ($request, $reservation, $data) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($reservation->status, ['pending', 'confirmed']), 422, 'Only active reservations can be cancelled.');
            $reservation->update(['status' => 'cancelled', 'cancellation_reason' => $data['cancellation_reason'] ?? null]);
            $reservation->transactions()->create(['actor_id' => $request->user()->id, 'action' => 'cancelled', 'notes' => $data['cancellation_reason'] ?? 'Cancelled by customer.']);
        });
        return back()->with('success', 'The reservation request was cancelled.');
    }
}
