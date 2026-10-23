@extends('emails.layouts.app')
@php($verification = true)

@section('content')
    <p style="margin: 0; color: #535f73; font-size: 12px; line-height: 18px;">Terima kasih telah mendaftar di <span style="color: #172030;">DokumenKita</span>. Silakan klik tombol di bawah ini untuk memverifikasi alamat email Anda dan mengaktifkan akses tanda tangan digital Anda.</p>

    @include('emails.partials.button')

    @component('emails.partials.info-box', ['verification' => $verification])
        Tautan verifikasi ini aman dan akan kedaluwarsa secara otomatis dalam <strong>{{ $expiresIn }}</strong>.
    @endcomponent

    <p style="margin: 12px 0 18px; color: #788297; font-size: 11px; line-height: 16px;">Jika Anda tidak merasa pernah membuat akun di DokumenKita, abaikan email ini. Tidak ada tindakan lebih lanjut yang perlu dilakukan.</p>
    <p style="margin: 0 0 3px; color: #929bad; font-size: 10px; line-height: 14px;">SALAM HANGAT,</p>
    <p style="margin: 0; font-size: 12px; line-height: 16px; font-weight: 700;">Tim DokumenKita</p>
@endsection

@section('fallback')
    @component('emails.partials.fallback-url', ['verification' => $verification, 'actionUrl' => $actionUrl])
        Jika Anda mengalami kendala saat menekan tombol "Verifikasi Alamat Email", salin dan tempel tautan verifikasi resmi berikut ke peramban web Anda:
    @endcomponent
@endsection
