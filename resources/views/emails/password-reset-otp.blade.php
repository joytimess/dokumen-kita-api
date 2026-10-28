<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>Reset Password</title>
</head>
<body style="margin: 0; padding: 0; width: 100%; background-color: #F7F9F6; color: #142D22; font-family: Arial, Helvetica, sans-serif; -webkit-text-size-adjust: 100%;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#F7F9F6">
    <tr>
        <td align="center" style="padding: 24px 20px;">
            <!--[if mso]><table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0"><tr><td><![endif]-->
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width: 600px;">
                <tr>
                    <td bgcolor="#FFFFFF" style="padding: 32px 24px; border: 1px solid #DFE7DD; border-radius: 12px;">
                        <p style="margin: 0 0 24px; color: #1F4937; font-size: 20px; line-height: 26px; font-weight: bold; text-align: center;">DokumenKita</p>
                        <h1 style="margin: 0 0 12px; color: #142D22; font-size: 24px; line-height: 30px; font-weight: bold; text-align: center;">Reset Password</h1>
                        <p style="margin: 0 0 24px; color: #596E62; font-size: 14px; line-height: 22px; text-align: center;">Gunakan kode berikut untuk mereset password Anda.</p>

                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                            <tr>
                                <td align="center" bgcolor="#E8EFE8" style="padding: 24px 12px; border: 1px solid #DFE7DD; border-radius: 12px; text-align: center;">
                                    <p style="margin: 0 0 12px; color: #2A5B45; font-size: 12px; line-height: 18px; font-weight: bold;">KODE VERIFIKASI</p>
                                    <p style="margin: 0 0 12px; color: #1F4937; font-size: 36px; line-height: 44px; font-weight: bold; letter-spacing: 6px;">{{ $otp }}</p>
                                    <p style="margin: 0; color: #596E62; font-size: 14px; line-height: 20px;">Berlaku selama <strong>5 menit</strong> sejak permintaan dibuat.</p>
                                </td>
                            </tr>
                        </table>

                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top: 24px;">
                            <tr>
                                <td bgcolor="#F0F5EF" style="padding: 16px; color: #142D22; font-size: 14px; line-height: 22px;">
                                    Jangan bagikan kode ini kepada siapa pun.
                                    <p style="margin: 8px 0 0; color: #596E62;">Jika Anda tidak meminta reset password, abaikan email ini.</p>
                                </td>
                            </tr>
                        </table>

                        <p style="margin: 24px 0 0; color: #596E62; font-size: 14px; line-height: 22px;">Regards,<br>DokumenKita</p>
                        <p style="margin: 24px 0 0; padding-top: 20px; border-top: 1px solid #DFE7DD; color: #596E62; font-size: 12px; line-height: 18px; text-align: center;">&copy; {{ date('Y') }} DokumenKita. All rights reserved.</p>
                    </td>
                </tr>
            </table>
            <!--[if mso]></td></tr></table><![endif]-->
        </td>
    </tr>
</table>
</body>
</html>
