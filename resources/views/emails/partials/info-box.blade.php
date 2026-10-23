<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="{{ $verification ? 'border-top: 1px solid #f0f2f2;' : '' }}">
    <tr>
        <td style="padding-top: {{ $verification ? 8 : 0 }}px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="table-layout: fixed;">
                <tr>
                    <td bgcolor="{{ $verification ? '#fffcf2' : '#f3fcf7' }}" style="border: 1px solid {{ $verification ? '#ffedb5' : '#d5f7e4' }}; border-radius: {{ $verification ? 6 : 9 }}px; padding: {{ $verification ? '8px 10px' : '11px 12px' }};">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="table-layout: fixed;">
                            <tr>
                                <td width="22" valign="top" style="color: {{ $verification ? '#b45309' : '#1b4535' }}; font-size: 14px; line-height: 16px;"><span aria-hidden="true">{{ $verification ? '◷' : 'ⓘ' }}</span></td>
                                <td style="color: {{ $verification ? '#a74408' : '#435066' }}; font-size: 11px; line-height: 16px;">{{ $slot }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
