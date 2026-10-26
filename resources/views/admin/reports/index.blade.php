@extends('layouts.app')
@section('title', 'Administrative reports')
@section('content')
<header class="page-heading"><div><p class="section-kicker">Administrative reporting</p><h1>Shop activity reports</h1><p>Review reservations, recommendation activity, customer accounts, and furniture catalog details for a selected date range.</p></div></header>

<form method="GET" action="{{ route('admin.reports.index') }}" class="filter-panel catalog-filters mb-4" data-report-filters>
    <div class="search-wrap mb-2"><label class="form-label" for="report-search">Search</label><input type="search" id="report-search" class="form-control" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search reports" maxlength="200" autocomplete="off" data-report-search aria-describedby="report-search-help"><div class="form-text" id="report-search-help">Search by name, furniture, reference, or report details. Results appear as you type.</div><div class="small text-danger" data-report-search-error role="status" hidden></div></div>
    <div class="row g-3 align-items-end">
        <div class="col-md-4"><label class="form-label" for="report">Report</label><select class="form-select" id="report" name="report">@foreach(['reservations' => 'Reservations', 'recommendations' => 'Recommendations', 'customers' => 'Customers', 'furniture' => 'Furniture information'] as $value => $label)<option value="{{ $value }}" @selected($report === $value)>{{ $label }}</option>@endforeach</select></div>
        <div class="col-md-3"><label class="form-label" for="date_from">From</label><input class="form-control" type="date" id="date_from" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></div>
        <div class="col-md-3"><label class="form-label" for="date_to">Through</label><input class="form-control" type="date" id="date_to" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></div>
        <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit">Apply filters</button></div>
    </div>
    <p class="small text-muted mb-0 mt-2">Date filters use each record’s creation date. Customer and product totals are lifetime counts for records created within the selected period.</p>
</form>
<div data-report-results aria-live="polite">

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><h2 class="h4 mb-0">{{ ['reservations' => 'Reservation report', 'recommendations' => 'Recommendation report', 'customers' => 'Customer report', 'furniture' => 'Furniture information report'][$report] }} <span class="text-muted">({{ number_format($recordCount) }} records)</span></h2><div class="dropdown"><button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Download</button><ul class="dropdown-menu dropdown-menu-end"><li><a class="dropdown-item" href="{{ route('admin.reports.export', array_merge($filters, ['report' => $report, 'format' => 'csv'])) }}">CSV (.csv)</a></li><li><a class="dropdown-item" href="{{ route('admin.reports.export', array_merge($filters, ['report' => $report, 'format' => 'pdf'])) }}">PDF (.pdf)</a></li></ul></div></div>

@if($report === 'reservations' && $branchStats->isNotEmpty())
    <section class="panel mb-4"><h3 class="h5">Reservations for Lance Furniture Shop</h3><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Shop location</th><th>Reservations</th><th>Confirmed</th></tr></thead><tbody>@foreach($branchStats as $branch)<tr><td>{{ $branch->branch }}</td><td>{{ $branch->reservation_count }}</td><td>{{ $branch->confirmed_count }}</td></tr>@endforeach</tbody></table></div></section>
@endif

<div class="table-responsive panel"><table class="table table-hover align-middle mb-0">
    @if($report === 'reservations')
        <thead><tr><th>Reference</th><th>Date</th><th>Customer</th><th>Furniture</th><th>Shop location</th><th>Status</th><th>Recommendation</th></tr></thead><tbody>@forelse($rows as $row)<tr><td>{{ $row->reference }}</td><td>{{ $row->created_at->format('M j, Y') }}</td><td>{{ $row->user?->name }}<div class="small text-muted">{{ $row->user?->email }}</div></td><td>{{ $row->product?->name }}</td><td>{{ $row->branch }}</td><td><span class="status status-{{ $row->status }}">{{ $row->status }}</span></td><td>{{ $row->recommendation_id ?: '—' }}</td></tr>@empty<tr><td colspan="7">No reservations match these filters.</td></tr>@endforelse</tbody>
    @elseif($report === 'recommendations')
        <thead><tr><th>ID / date</th><th>Customer</th><th>Type</th><th>Algorithm</th><th>Weights</th><th>Items</th><th>Linked reservations</th></tr></thead><tbody>@forelse($rows as $row)<tr><td>#{{ $row->id }}<div class="small text-muted">{{ $row->created_at->format('M j, Y') }}</div></td><td>{{ $row->user?->name }}<div class="small text-muted">{{ $row->user?->email }}</div></td><td>{{ $row->furniture_type ?: '—' }}</td><td>{{ $row->algorithm_version }}</td><td>{{ $row->content_weight === null ? '—' : number_format($row->content_weight * 100, 0).'% / '.number_format($row->collaborative_weight * 100, 0).'%' }}</td><td>{{ $row->items_count }}</td><td>{{ $row->reservation_count }}</td></tr>@empty<tr><td colspan="7">No recommendations match these filters.</td></tr>@endforelse</tbody>
    @elseif($report === 'customers')
        <thead><tr><th>Customer</th><th>Contact</th><th>Joined</th><th>Account</th><th>Recommendations</th><th>Reservations</th></tr></thead><tbody>@forelse($rows as $row)<tr><td>{{ $row->name }}</td><td>{{ $row->email }}<div class="small text-muted">{{ $row->phone ?: 'No phone recorded' }}</div></td><td>{{ $row->created_at->format('M j, Y') }}</td><td>{{ $row->is_active ? 'Active' : 'Inactive' }}</td><td>{{ $row->recommendations_count }}</td><td>{{ $row->reservations_count }}</td></tr>@empty<tr><td colspan="6">No customers match these filters.</td></tr>@endforelse</tbody>
    @else
        <thead><tr><th>Furniture</th><th>Category / style</th><th>Materials</th><th>Dimensions cm</th><th>Price</th><th>Status</th><th>Views / favorites / reservations</th></tr></thead><tbody>@forelse($rows as $row)<tr><td>{{ $row->name }}<div class="small text-muted">{{ $row->created_at->format('M j, Y') }}</div></td><td>{{ $row->category }}<div class="small text-muted">{{ $row->style }}</div></td><td>{{ $row->wood_type }} · {{ $row->varnish_finish }}</td><td>{{ $row->width_cm ?: '—' }} × {{ $row->depth_cm ?: '—' }} × {{ $row->height_cm ?: '—' }}</td><td>PHP {{ number_format($row->price, 2) }}</td><td>{{ $row->status }}</td><td>{{ $row->views_count }} / {{ $row->favorites_count }} / {{ $row->reservations_count }}</td></tr>@empty<tr><td colspan="7">No furniture records match these filters.</td></tr>@endforelse</tbody>
    @endif
</table></div>
<div class="mt-3">{{ $rows->links() }}</div>
</div>
@endsection


