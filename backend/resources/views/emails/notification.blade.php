<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $mailData['subject'] }}</title>
</head>

<body style="margin:0; padding:24px 12px; background-color:#f3f6fb; font-family:Arial, Helvetica, sans-serif; color:#1f2937;">
    @php
        $severity = $mailData['severity'] ?? 'success';
        $accent = match ($severity) {
            'danger' => '#dc2626',
            'warning' => '#d97706',
            default => '#2563eb',
        };
        $summaryBg = match ($severity) {
            'danger' => '#fef2f2',
            'warning' => '#fff7ed',
            default => '#eff6ff',
        };
        $summaryTitle = match ($severity) {
            'danger' => 'Attention Required',
            'warning' => 'Action Summary',
            default => 'Update Summary',
        };
    @endphp

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:680px; border-collapse:separate; background-color:#ffffff; border:1px solid #dbe4f0; border-radius:18px; overflow:hidden;">
                    <tr>
                        <td style="padding:28px 32px 24px; background-color:#0f172a;">
                            <div style="font-size:12px; line-height:18px; letter-spacing:1.4px; text-transform:uppercase; color:#cbd5e1; font-weight:700;">
                                Property Listing Notification
                            </div>
                            <div style="margin-top:10px; font-size:28px; line-height:36px; font-weight:700; color:#ffffff;">
                                {{ $mailData['subject'] }}
                            </div>
                            <div style="margin-top:10px; font-size:14px; line-height:22px; color:#cbd5e1;">
                                {{ $mailData['preheader'] ?? $mailData['message'] }}
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:30px 32px 12px;">
                            <div style="font-size:15px; line-height:24px; color:#1f2937; font-weight:700;">
                                {{ $mailData['greeting'] ?? 'Hello,' }}
                            </div>
                            <div style="margin-top:14px; font-size:15px; line-height:25px; color:#334155;">
                                {{ $mailData['intro'] ?? $mailData['message'] }}
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 32px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate; border-left:5px solid {{ $accent }}; background-color:{{ $summaryBg }}; border-radius:14px;">
                                <tr>
                                    <td style="padding:18px 20px;">
                                        <div style="font-size:12px; line-height:18px; letter-spacing:1px; text-transform:uppercase; color:#64748b; font-weight:700;">
                                            {{ $summaryTitle }}
                                        </div>
                                        <div style="margin-top:8px; font-size:15px; line-height:24px; color:#0f172a; font-weight:600;">
                                            {{ $mailData['message'] }}
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    @if(!empty($mailData['details']))
                        <tr>
                            <td style="padding:24px 32px 0;">
                                <div style="font-size:13px; line-height:18px; letter-spacing:1px; text-transform:uppercase; color:#64748b; font-weight:700; margin-bottom:12px;">
                                    Notification Details
                                </div>
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate; border:1px solid #e5e7eb; border-radius:14px; overflow:hidden;">
                                    @foreach($mailData['details'] as $label => $value)
                                        <tr>
                                            <td valign="top" style="width:210px; padding:14px 16px; background-color:#f8fafc; border-bottom:1px solid #e5e7eb; color:#475569; font-size:13px; line-height:20px; font-weight:700;">
                                                {{ $label }}
                                            </td>
                                            <td valign="top" style="padding:14px 16px; background-color:#ffffff; border-bottom:1px solid #e5e7eb; color:#111827; font-size:14px; line-height:22px;">
                                                {{ $value }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td style="padding:24px 32px 0;">
                            <div style="font-size:15px; line-height:25px; color:#334155;">
                                {{ $mailData['closing'] ?? 'Please sign in to your dashboard for more details.' }}
                            </div>
                        </td>
                    </tr>

                    @if(!empty($mailData['action_url']))
                        <tr>
                            <td style="padding:24px 32px 0;">
                                <a href="{{ $mailData['action_url'] }}"
                                   style="display:inline-block; padding:12px 18px; background-color:#2563eb; color:#ffffff; text-decoration:none; border-radius:10px; font-size:14px; line-height:20px; font-weight:700;">
                                    {{ $mailData['action_label'] ?? 'Open Dashboard' }}
                                </a>
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td style="padding:26px 32px 30px;">
                            <div style="height:1px; background-color:#e5e7eb; margin-bottom:18px;"></div>
                            <div style="font-size:13px; line-height:21px; color:#64748b;">
                                <strong style="color:#334155;">Property Listing</strong><br>
                                This is a system-generated notification to keep your tenancy, property, and payment records clear and up to date.
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
