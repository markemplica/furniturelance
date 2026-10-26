<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['q' => ['nullable', 'string', 'max:200']]);
        $request->merge(['q' => trim((string) $request->input('q', ''))]);
        $customers = User::where('role', 'customer')->when($request->filled('q'), fn ($q) => $q->where(fn ($inner) => $inner->where('name', 'like', '%'.$request->q.'%')->orWhere('email', 'like', '%'.$request->q.'%')))->withCount(['reservations', 'recommendations'])->latest()->paginate(15)->withQueryString();

        return view('admin.customers.index', compact('customers'));
    }

    public function update(Request $request, User $user)
    {
        abort_unless($user->role === 'customer', 422);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $user->update($data);

        return back()->with('success', $user->is_active ? 'Customer account activated.' : 'Customer account deactivated.');
    }
}
