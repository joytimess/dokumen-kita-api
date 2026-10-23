<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $subject }}</title>
    <style>
        @media only screen and (max-width: 400px) {
            .email-outer { padding-right: 12px !important; padding-left: 12px !important; }
            .email-content { padding: 26px 20px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; width: 100%; background-color: {{ $verification ? '#eef3f0' : '#f5f7fb' }}; color: #172030; font-family: Arial, Helvetica, sans-serif; -webkit-text-size-adjust: 100%;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="{{ $verification ? '#eef3f0' : '#f5f7fb' }}" style="font-family: Arial, Helvetica, sans-serif;">
    <tr>
        <td class="email-outer" align="center" style="padding: {{ $verification ? '14px 24px 10px' : '19px 18px 22px' }};">
            @include('emails.partials.header')
            <!--[if mso]><table role="presentation" width="{{ $verification ? 416 : 428 }}" cellspacing="0" cellpadding="0" border="0"><tr><td><![endif]-->
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width: {{ $verification ? 416 : 428 }}px; table-layout: fixed;">
                <tr>
                    <td class="email-content" bgcolor="#ffffff" style="padding: 30px; border: 1px solid #e8eceb; border-radius: {{ $verification ? 12 : 18 }}px; box-shadow: 0 2px 6px rgba(23, 32, 48, 0.04);">
                        <h1 style="margin: 0 0 {{ $verification ? 6 : 12 }}px; font-size: 20px; line-height: 26px; letter-spacing: -0.6px; font-weight: 700;">Halo!</h1>
                        @yield('content')
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top: {{ $verification ? 24 : 30 }}px; table-layout: fixed;">
                            <tr>
                                <td style="border-top: 1px solid #f0f2f2; padding-top: {{ $verification ? 16 : 20 }}px;">
                                    @yield('fallback')
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
            <!--[if mso]></td></tr></table><![endif]-->
        </td>
    </tr>
</table>
</body>
</html>
