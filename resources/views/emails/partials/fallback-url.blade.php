<p style="margin: 0 0 6px; color: #788297; font-size: 9px; line-height: 13px;">{{ $slot }}</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="table-layout: fixed;">
    <tr>
        <td bgcolor="#f7f9fa" style="padding: 8px; border: 1px solid #e3e9ed; border-radius: 6px; word-break: break-all; word-wrap: break-word; overflow-wrap: anywhere;">
            <a href="{{ $actionUrl }}" style="display: block; color: #1b4535; font-family: Consolas, 'Courier New', monospace; font-size: 9px; line-height: 12px; text-decoration: {{ $verification ? 'underline' : 'none' }}; word-break: break-all; word-wrap: break-word; overflow-wrap: anywhere;">{{ $actionUrl }}</a>
        </td>
    </tr>
</table>
