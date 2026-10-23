@extends('emails.layouts.app')
@php($verification = false)

@section('content')
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="table-layout: fixed;">
        <tr>
            <td bgcolor="#f7f9fb" style="padding: 14px 12px; border: 1px solid #f0f4f6; border-radius: 10px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="table-layout: fixed;">
                    <tr>
                        <td width="28" valign="middle">
                            <table role="presentation" width="26" cellspacing="0" cellpadding="0" border="0">
                                <tr><td height="26" align="center" bgcolor="#dff9ed" style="border-radius: 6px; color: #1b4535; font-size: 16px;"><span aria-hidden="true">▤</span></td></tr>
                            </table>
                        </td>
                        <td style="padding-left: 10px; word-break: break-word; word-wrap: break-word; overflow-wrap: anywhere;">
                            <p style="margin: 0 0 2px; color: #929bad; font-size: 9px; line-height: 12px; letter-spacing: 0.5px;">NAMA DOKUMEN</p>
                            <p style="margin: 0; font-size: 11px; line-height: 15px; font-weight: 700; word-break: break-word; word-wrap: break-word; overflow-wrap: anywhere;">{{ $documentTitle }} <span style="font-weight: 400;">—</span> <span style="color: #1b4535;">versi {{ $versionNumber }}</span></p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <p style="margin: 16px 0 0; color: #435066; font-size: 12px; line-height: 18px;">{{ $description }}</p>

    @include('emails.partials.button')

    @component('emails.partials.info-box', ['verification' => $verification])
        Gunakan akun dengan email yang menerima undangan ini dan verifikasi email sebelum melanjutkan.
    @endcomponent

    <p style="margin: 28px 0 0; color: #535f73; font-size: 11px; line-height: 17px;">Regards,<br><strong style="color: #172030;">DokumenKita</strong></p>
@endsection

@section('fallback')
    @component('emails.partials.fallback-url', ['verification' => $verification, 'actionUrl' => $actionUrl])
        If you're having trouble clicking the "Buka undangan" button, copy and paste the URL below into your web browser:
    @endcomponent
@endsection
