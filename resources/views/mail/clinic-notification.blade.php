{{--
    Plain-text notification email for an approved notification template.

    `dir="auto"` lets the browser pick the writing direction from the first
    strong character in the document, so an Urdu body renders right-to-left
    and an English body left-to-right from the same template (DESIGN §19).
    The body is escaped because it originates from a database template.
--}}
<!DOCTYPE html>
<html dir="auto">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Noto Nastaliq Urdu',Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                   style="max-width:600px;background-color:#ffffff;border:1px solid #e2e8f0;border-radius:8px;">
                <tr>
                    <td dir="auto" style="padding:24px;font-size:15px;line-height:1.7;color:#0f172a;white-space:pre-wrap;word-break:break-word;">
                        {{ $body }}
                    </td>
                </tr>
                <tr>
                    <td dir="auto" style="padding:16px 24px;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;">
                        {{ __('This is an automated message from your clinic. Please do not reply to this email.') }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
