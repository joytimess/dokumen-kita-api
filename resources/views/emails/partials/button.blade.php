<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td align="center" style="padding: 24px 0;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td align="center" bgcolor="#1b4535" style="border-radius: 9px; box-shadow: 0 3px 5px rgba(27, 69, 53, 0.16);">
                        <a href="{{ $actionUrl }}" style="display: inline-block; border: 12px solid #1b4535; border-right-width: 22px; border-left-width: 22px; border-radius: 9px; color: #ffffff; font-size: 12px; line-height: 14px; font-weight: 700; text-decoration: none; mso-padding-alt: 0;">
                            @if ($verification)<span aria-hidden="true" style="color: #85e2be; padding-right: 5px;">⊙</span>@endif
                            {{ $actionText }} <span aria-hidden="true" style="padding-left: 5px; color: #d6e6de;">→</span>
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
