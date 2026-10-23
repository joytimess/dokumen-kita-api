DokumenKita

Halo!

{!! $documentTitle !!} — versi {{ $versionNumber }}

{!! $description !!}
@foreach ($details as $detail)

{!! $detail !!}
@endforeach
@if ($actionUrl)

{!! $actionText !!}: {!! $actionUrl !!}
@endif

Salam hangat,
Tim DokumenKita
