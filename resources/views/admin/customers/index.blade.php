@extends('layouts.app')
@section('title','Manage customers')
@section('content')
<header class="page-heading"><div><p class="section-kicker">Customer accounts</p><h1>Customer access</h1><p>Deactivate accounts without deleting reservation, preference, or recommendation history.</p></div></header>
<form class="filter-panel catalog-filters mb-3" method="GET" action="{{ route('admin.customers.index') }}" data-report-filters>
    <div class="search-wrap">
        <label class="form-label" for="customer-search">Search</label>
        <div class="d-flex gap-2">
            <input type="search" id="customer-search" class="form-control" name="q" value="{{ request('q') }}" placeholder="Search name or email" autocomplete="off" data-report-search aria-describedby="customer-search-help">
            <button class="btn btn-dark" type="submit">Search</button>
        </div>
    </div>
    <div class="form-text" id="customer-search-help">Results appear as you type. Partial names and emails are supported.</div>
    <div class="small text-danger" data-report-search-error role="status" hidden></div>
</form>
<div data-report-results aria-live="polite"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Customer</th><th>Activity</th><th>Joined</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody>@forelse($customers as $customer)<tr><td><strong>{{ $customer->name }}</strong><div class="small text-muted">{{ $customer->email }}@if($customer->phone) · {{ $customer->phone }}@endif</div></td><td>{{ $customer->recommendations_count }} recommendations<br>{{ $customer->reservations_count }} reservations</td><td>{{ $customer->created_at->format('M j, Y') }}</td><td><span class="status status-{{ $customer->is_active ? 'active' : 'inactive' }}">{{ $customer->is_active ? 'active' : 'inactive' }}</span></td><td class="text-end"><form method="POST" action="{{ route('admin.customers.update',$customer) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $customer->is_active ? 0 : 1 }}"><button class="btn btn-sm {{ $customer->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}">{{ $customer->is_active ? 'Deactivate' : 'Activate' }}</button></form></td></tr>@empty<tr><td colspan="5">No customers found.</td></tr>@endforelse</tbody></table></div>{{ $customers->links() }}
</div>
@endsection

