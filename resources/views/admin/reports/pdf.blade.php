<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>FurniCraft report</title>
<style>
@page { margin: 30px; }
body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #35251e; }
h1 { font-size: 22px; margin: 0 0 6px; color: #573323; }
h2 { font-size: 15px; margin: 0 0 12px; }
.meta { color: #66574e; margin-bottom: 16px; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
thead { display: table-header-group; }
th { background: #573323; color: white; text-align: left; }
td, th { padding: 7px 5px; border: 1px solid #d9c9be; overflow-wrap: break-word; word-wrap: break-word; }
tr:nth-child(even) td { background: #f6f0eb; }
</style></head><body>
<h1>FurniCraft</h1><h2>{{ ucfirst($report) }} report</h2>
<p class="meta">Generated: {{ now()->format('M j, Y g:i A') }} | {{ $rows->count() }} records<br>
Date range: {{ $filters['date_from'] ?? 'All dates' }} through {{ $filters['date_to'] ?? 'Present' }}
@if(!empty($filters['q'])) | Search: {{ $filters['q'] }} @endif
@if(!empty($filters['branch'])) | Shop: {{ $filters['branch'] }} @endif</p>
<table><thead><tr>@foreach($headers as $header)<th>{{ $header }}</th>@endforeach</tr></thead>
<tbody>@forelse($rows as $values)<tr>@foreach($values as $value)<td>{{ $value ?? '—' }}</td>@endforeach</tr>
@empty<tr><td colspan="{{ count($headers) }}">No records match these filters.</td></tr>@endforelse</tbody></table>
</body></html>
