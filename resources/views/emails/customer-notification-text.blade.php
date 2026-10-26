FURNICRAFT | LANCE FURNITURE SHOP
{{ strtoupper($eyebrow) }}

{{ $heading }}

{{ $intro }}
@if($code)
{{ $codeLabel }}: {{ $code }}
{{ $expiry }}
@endif
@foreach($details as $detail)
{{ $detail['label'] }}: {{ $detail['value'] }}
@endforeach
@if($buttonUrl && $buttonLabel)
{{ $buttonLabel }}: {{ $buttonUrl }}
@endif
@if($note)
{{ $note }}
@endif

Automated message from FurniCraft at Lance Furniture Shop.
