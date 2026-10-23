@extends('emails.layouts.app')
@php($verification = false)

@section('content')
    <p style="margin: 0; color: #929bad; font-size: 10px;">NAMA DOKUMEN</p>
    <p style="margin: 6px 0 16px; font-size: 14px; font-weight: 700; overflow-wrap: anywhere;">{{ $documentTitle }} — versi {{ $versionNumber }}</p>
    <p style="color: #435066; font-size: 12px; line-height: 18px;">{{ $description }}</p>
    @foreach ($details as $detail)
        <p style="color: #435066; font-size: 12px; line-height: 18px; white-space: pre-line; overflow-wrap: anywhere;">{{ $detail }}</p>
    @endforeach

    @if ($actionUrl)
        @include('emails.partials.button')
    @endif
    <p style="margin-top: 24px; font-size: 12px;">Salam hangat,<br><strong>Tim DokumenKita</strong></p>
@endsection

@section('fallback')
    @if ($actionUrl)
        @component('emails.partials.fallback-url', ['verification' => $verification, 'actionUrl' => $actionUrl])
            Jika tombol tidak dapat dibuka, salin dan tempel tautan berikut ke browser Anda:
        @endcomponent
    @endif
@endsection
