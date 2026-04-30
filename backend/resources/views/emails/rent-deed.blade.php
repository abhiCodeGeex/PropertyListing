<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Rent Deed</title>
</head>

<body style="margin:0; padding:24px 0; font-family:Arial, sans-serif; background:#f3f6fb; color:#1f2937;">
    <div style="max-width:680px; background:#ffffff; margin:auto; border-radius:18px; overflow:hidden; box-shadow:0 18px 45px rgba(15,23,42,0.08);">
        <div style="padding:28px 32px 22px; background:#0f172a; color:#ffffff;">
            <div style="font-size:12px; letter-spacing:0.08em; text-transform:uppercase; opacity:0.75; margin-bottom:10px;">
                Property Listing Rent Deed
            </div>
            <h2 style="margin:0; font-size:28px; line-height:1.2;">Rent deed created for {{ $rentDeed['property_name'] }}</h2>
            <p style="margin:10px 0 0; font-size:14px; line-height:1.6; color:rgba(255,255,255,0.82);">
                A PDF copy of the rent deed is attached for your records.
            </p>
        </div>

        <div style="padding:30px 32px;">
            <p style="margin:0 0 18px; font-size:15px; line-height:1.7;">
                Hello {{ $recipient->name ?? 'there' }}, the rent deed has been created and attached to this email.
            </p>

            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; margin-bottom:24px; border:1px solid #e5e7eb; border-radius:14px; overflow:hidden;">
                <tbody>
                    <tr>
                        <td style="padding:14px 16px; background:#f8fafc; color:#475569; font-weight:bold; width:180px;">Agreement Number</td>
                        <td style="padding:14px 16px;">{{ $rentDeed['agreement_number'] }}</td>
                    </tr>
                    <tr>
                        <td style="padding:14px 16px; background:#f8fafc; color:#475569; font-weight:bold; border-top:1px solid #e5e7eb;">Agreement Date</td>
                        <td style="padding:14px 16px; border-top:1px solid #e5e7eb;">{{ $rentDeed['agreement_date'] ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td style="padding:14px 16px; background:#f8fafc; color:#475569; font-weight:bold; border-top:1px solid #e5e7eb;">Property</td>
                        <td style="padding:14px 16px; border-top:1px solid #e5e7eb;">{{ $rentDeed['property_name'] }}</td>
                    </tr>
                    <tr>
                        <td style="padding:14px 16px; background:#f8fafc; color:#475569; font-weight:bold; border-top:1px solid #e5e7eb;">Tenant</td>
                        <td style="padding:14px 16px; border-top:1px solid #e5e7eb;">{{ $rentDeed['tenant_name'] ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td style="padding:14px 16px; background:#f8fafc; color:#475569; font-weight:bold; border-top:1px solid #e5e7eb;">Owner</td>
                        <td style="padding:14px 16px; border-top:1px solid #e5e7eb;">{{ $rentDeed['owner_name'] ?? '-' }}</td>
                    </tr>
                </tbody>
            </table>

            <p style="margin:24px 0 0; font-size:12px; line-height:1.7; color:#64748b;">
                This is a system-generated email from Property Listing.
            </p>
        </div>
    </div>
</body>

</html>
