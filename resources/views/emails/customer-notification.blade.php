<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $heading }} | FurniCraft</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f0eb;color:#33251f;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{{ $intro }}</div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f5f0eb;">
        <tr>
            <td align="center" style="padding:32px 14px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background-color:#ffffff;border:1px solid #e8d9ce;border-radius:16px;overflow:hidden;">
                    <tr>
                        <td style="padding:22px 30px;background-color:#513225;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td align="center" valign="middle" width="38" height="38" style="width:38px;height:38px;border-radius:50%;background-color:#fff7ef;color:#513225;font-size:20px;font-weight:700;">F</td>
                                    <td style="padding-left:11px;color:#ffffff;font-size:18px;font-weight:700;letter-spacing:.2px;">FurniCraft<span style="display:block;margin-top:4px;color:#e6cfc0;font-size:9px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;">Lance Furniture Shop</span></td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:34px 34px 12px;">
                            <p style="margin:0 0 10px;color:#a45532;font-size:11px;font-weight:700;letter-spacing:1.7px;line-height:1.4;text-transform:uppercase;">{{ $eyebrow }}</p>
                            <h1 style="margin:0;color:#2e211c;font-size:28px;font-weight:700;line-height:1.2;letter-spacing:-.5px;">{{ $heading }}</h1>
                            <p style="margin:16px 0 0;color:#6f5b50;font-size:15px;line-height:1.65;">{{ $intro }}</p>
                        </td>
                    </tr>
                    @if($code)
                    <tr>
                        <td style="padding:18px 34px 8px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#faf5f0;border:1px solid #ead9cc;border-radius:12px;">
                                <tr>
                                    <td align="center" style="padding:20px 16px 18px;">
                                        <p style="margin:0 0 10px;color:#80675a;font-size:10px;font-weight:700;letter-spacing:1.5px;line-height:1.4;">{{ $codeLabel }}</p>
                                        <p style="margin:0;color:#513225;font-family:Arial,Helvetica,sans-serif;font-size:34px;font-weight:700;letter-spacing:9px;line-height:1.3;">{{ $code }}</p>
                                        @if($expiry)<p style="margin:9px 0 0;color:#80675a;font-size:12px;line-height:1.5;">{{ $expiry }}</p>@endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @endif
                    @if(count($details))
                    <tr>
                        <td style="padding:18px 34px 4px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #eadfd7;border-radius:12px;">
                                @foreach($details as $detail)
                                <tr>
                                    <td style="padding:13px 16px;border-bottom:{{ $loop->last ? '0' : '1px solid #eee5df' }};">
                                        <p style="margin:0 0 5px;color:#8a7164;font-size:9px;font-weight:700;letter-spacing:1.25px;line-height:1.4;">{{ $detail['label'] }}</p>
                                        <p style="margin:0;color:#33251f;font-size:14px;font-weight:600;line-height:1.5;overflow-wrap:anywhere;">{{ $detail['value'] }}</p>
                                    </td>
                                </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                    @endif
                    @if($buttonUrl && $buttonLabel)
                    <tr>
                        <td align="center" style="padding:24px 34px 10px;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td align="center" bgcolor="#513225" style="border-radius:8px;">
                                        <a href="{{ $buttonUrl }}" style="display:inline-block;padding:13px 24px;border:1px solid #513225;border-radius:8px;background-color:#513225;color:#ffffff;font-size:14px;font-weight:700;line-height:1.3;text-decoration:none;">{{ $buttonLabel }}</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @endif
                    @if($note)
                    <tr>
                        <td style="padding:{{ $buttonUrl ? '16px' : '20px' }} 34px 30px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f8f3ee;border-left:3px solid #c58b65;">
                                <tr><td style="padding:12px 14px;color:#705c51;font-size:12px;line-height:1.6;">{{ $note }}</td></tr>
                            </table>
                        </td>
                    </tr>
                    @endif
                    <tr>
                        <td style="padding:18px 30px;border-top:1px solid #eee5df;background-color:#fcfaf8;">
                            <p style="margin:0;color:#806e63;font-size:11px;line-height:1.6;">This is an automated message from FurniCraft at Lance Furniture Shop. Please do not reply to this email unless your mail app supports replies to the shop.</p>
                            <p style="margin:8px 0 0;color:#a08e83;font-size:10px;line-height:1.5;">&copy; {{ now()->year }} FurniCraft. All rights reserved.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
